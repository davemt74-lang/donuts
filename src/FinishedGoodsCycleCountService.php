<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FinishedGoodsCycleCountService
{
    public function __construct(private readonly PDO $db) {}

    public function frequencyDays(): int
    {
        $s=$this->db->prepare("SELECT setting_value FROM finished_goods_cycle_count_settings WHERE setting_key='count_frequency_days'");$s->execute();
        return max(1,min(90,(int)($s->fetchColumn()?:7)));
    }

    public function setFrequencyDays(int $days): void
    {
        $days=max(1,min(90,$days));
        $s=$this->db->prepare("INSERT INTO finished_goods_cycle_count_settings(setting_key,setting_value) VALUES('count_frequency_days',?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP");
        $s->execute([(string)$days]);
    }

    public function queue(): array
    {
        $days=$this->frequencyDays();
        $s=$this->db->prepare("SELECT b.*,f.name flavor_name,
            (SELECT MAX(c.created_at) FROM finished_goods_cycle_counts c WHERE c.batch_id=b.id) last_counted_at,
            CASE
              WHEN (SELECT MAX(c.created_at) FROM finished_goods_cycle_counts c WHERE c.batch_id=b.id) IS NULL THEN 'never'
              WHEN (SELECT MAX(c.created_at) FROM finished_goods_cycle_counts c WHERE c.batch_id=b.id)<datetime('now',?) THEN 'due'
              ELSE 'current'
            END count_state
            FROM production_batches b JOIN flavors f ON f.id=b.flavor_id
            WHERE b.status IN ('active','hold') AND b.quantity_remaining>=0
            ORDER BY CASE count_state WHEN 'never' THEN 0 WHEN 'due' THEN 1 ELSE 2 END,
                     CASE WHEN b.best_by_date IS NULL THEN 1 ELSE 0 END,b.best_by_date,b.produced_at,b.id");
        $s->execute(['-'.$days.' days']);return $s->fetchAll();
    }

    public function reconcile(int $batchId,int $countedQuantity,string $reason,string $notes,int $adminId): int
    {
        if($countedQuantity<0 || $countedQuantity>100000) throw new \InvalidArgumentException('Counted quantity must be between 0 and 100000.');
        if(!in_array($reason,['routine','shrinkage','damage','found_stock','correction','other'],true)) throw new \InvalidArgumentException('Invalid cycle-count reason.');

        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM production_batches WHERE id=?");$s->execute([$batchId]);$batch=$s->fetch();
            if(!$batch) throw new \InvalidArgumentException('Production batch not found.');
            if(!in_array($batch['status'],['active','hold'],true)) throw new \InvalidArgumentException('Only active or held finished goods can be cycle counted.');

            $assigned=$this->sum('SELECT COALESCE(SUM(quantity),0) FROM order_batch_assignments WHERE batch_id=?',$batchId);
            $disposed=$this->sum('SELECT COALESCE(SUM(quantity),0) FROM finished_goods_dispositions WHERE batch_id=?',$batchId);
            $maximum=max(0,(int)$batch['quantity_produced']-$assigned-$disposed);
            if($countedQuantity>$maximum) throw new \InvalidArgumentException('Counted quantity exceeds the maximum remaining units after assigned and dispositioned stock.');

            $system=(int)$batch['quantity_remaining'];$variance=$countedQuantity-$system;
            if($variance!==0 && $reason==='routine') throw new \InvalidArgumentException('A variance reason is required when the physical count differs from the system quantity.');
            if($variance>0 && !in_array($reason,['found_stock','correction','other'],true)) throw new \InvalidArgumentException('Positive variance requires found stock, correction, or other.');
            if($variance<0 && !in_array($reason,['shrinkage','damage','correction','other'],true)) throw new \InvalidArgumentException('Negative variance requires shrinkage, damage, correction, or other.');

            $nextStatus=$countedQuantity===0?'depleted':(string)$batch['status'];
            $u=$this->db->prepare("UPDATE production_batches SET quantity_remaining=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND quantity_remaining=? AND status IN ('active','hold')");
            $u->execute([$countedQuantity,$nextStatus,$batchId,$system]);
            if($u->rowCount()!==1) throw new \RuntimeException('Production batch changed before cycle-count reconciliation.');

            $i=$this->db->prepare('INSERT INTO finished_goods_cycle_counts(batch_id,flavor_id,system_quantity,counted_quantity,variance_quantity,reason,notes,counted_by) VALUES(?,?,?,?,?,?,?,?)');
            $i->execute([$batchId,(int)$batch['flavor_id'],$system,$countedQuantity,$variance,$reason,mb_substr(trim($notes),0,2000),$adminId]);
            $id=(int)$this->db->lastInsertId();$this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function history(int $limit=200): array
    {
        $limit=max(1,min(500,$limit));
        return $this->db->query("SELECT c.*,b.batch_code,f.name flavor_name,a.email admin_email
            FROM finished_goods_cycle_counts c
            JOIN production_batches b ON b.id=c.batch_id
            JOIN flavors f ON f.id=c.flavor_id
            LEFT JOIN admin_users a ON a.id=c.counted_by
            ORDER BY c.id DESC LIMIT {$limit}")->fetchAll();
    }

    public function summary(): array
    {
        $out=['never'=>0,'due'=>0,'current'=>0,'variance_units_30d'=>0,'negative_variance_units_30d'=>0,'positive_variance_units_30d'=>0];
        foreach($this->queue() as $row)$out[(string)$row['count_state']]++;
        $s=$this->db->query("SELECT COALESCE(SUM(ABS(variance_quantity)),0) total,
            COALESCE(SUM(CASE WHEN variance_quantity<0 THEN ABS(variance_quantity) ELSE 0 END),0) negative,
            COALESCE(SUM(CASE WHEN variance_quantity>0 THEN variance_quantity ELSE 0 END),0) positive
            FROM finished_goods_cycle_counts WHERE created_at>=datetime('now','-30 days')");
        $row=$s->fetch();$out['variance_units_30d']=(int)$row['total'];$out['negative_variance_units_30d']=(int)$row['negative'];$out['positive_variance_units_30d']=(int)$row['positive'];
        return $out;
    }

    private function sum(string $sql,int $batchId): int
    {
        try{$s=$this->db->prepare($sql);$s->execute([$batchId]);return (int)$s->fetchColumn();}
        catch(\PDOException $e){
            $m=strtolower($e->getMessage());
            if(str_contains($m,'finished_goods_dispositions')) return 0;
            throw $e;
        }
    }
}
