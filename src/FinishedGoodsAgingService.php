<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FinishedGoodsAgingService
{
    public function __construct(private readonly PDO $db) {}

    public function warningDays(): int
    {
        $s=$this->db->prepare("SELECT setting_value FROM finished_goods_aging_settings WHERE setting_key='warning_days'");$s->execute();
        return max(1,min(30,(int)($s->fetchColumn()?:3)));
    }

    public function setWarningDays(int $days): void
    {
        $days=max(1,min(30,$days));
        $s=$this->db->prepare("INSERT INTO finished_goods_aging_settings(setting_key,setting_value) VALUES('warning_days',?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP");
        $s->execute([(string)$days]);
    }

    public function rows(): array
    {
        $warning=$this->warningDays();
        $s=$this->db->prepare("SELECT b.*,f.name flavor_name,
            CASE
              WHEN b.best_by_date IS NULL THEN 'no-date'
              WHEN b.best_by_date<date('now') THEN 'expired'
              WHEN b.best_by_date<=date('now',?) THEN 'expiring'
              ELSE 'fresh'
            END aging_state,
            CASE WHEN b.best_by_date IS NULL THEN NULL ELSE CAST(julianday(b.best_by_date)-julianday(date('now')) AS INTEGER) END days_remaining
            FROM production_batches b JOIN flavors f ON f.id=b.flavor_id
            WHERE b.quantity_remaining>0 AND b.status IN ('active','hold')
            ORDER BY CASE WHEN b.best_by_date IS NULL THEN 1 ELSE 0 END,b.best_by_date,b.produced_at,b.id");
        $s->execute(['+'.$warning.' days']);return $s->fetchAll();
    }

    public function holdExpired(): int
    {
        $s=$this->db->prepare("UPDATE production_batches SET status='hold',updated_at=CURRENT_TIMESTAMP WHERE status='active' AND quantity_remaining>0 AND best_by_date IS NOT NULL AND best_by_date<date('now')");
        $s->execute();return $s->rowCount();
    }

    public function disposition(int $batchId,int $quantity,string $reason,string $notes,int $adminId): int
    {
        if($quantity<=0 || $quantity>100000) throw new \InvalidArgumentException('Disposition quantity must be between 1 and 100000.');
        if(!in_array($reason,['expired','quality','damage','donation','sample','other'],true)) throw new \InvalidArgumentException('Invalid finished-goods disposition reason.');

        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT * FROM production_batches WHERE id=?');$s->execute([$batchId]);$batch=$s->fetch();
            if(!$batch) throw new \InvalidArgumentException('Production batch not found.');
            if(!in_array($batch['status'],['active','hold'],true)) throw new \InvalidArgumentException('Only active or held remaining stock can be dispositioned.');
            if((int)$batch['quantity_remaining']<$quantity) throw new \InvalidArgumentException('Disposition quantity exceeds remaining finished goods.');
            if($reason==='expired' && (empty($batch['best_by_date']) || (string)$batch['best_by_date']>=gmdate('Y-m-d'))) throw new \InvalidArgumentException('Expired disposition requires a past best-by date.');

            $u=$this->db->prepare("UPDATE production_batches SET quantity_remaining=quantity_remaining-?,status=CASE WHEN quantity_remaining-?=0 THEN 'depleted' ELSE status END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','hold') AND quantity_remaining>=?");
            $u->execute([$quantity,$quantity,$batchId,$quantity]);
            if($u->rowCount()!==1) throw new \RuntimeException('Production batch changed before disposition.');

            $i=$this->db->prepare('INSERT INTO finished_goods_dispositions(batch_id,flavor_id,quantity,reason,notes,recorded_by) VALUES(?,?,?,?,?,?)');
            $i->execute([$batchId,(int)$batch['flavor_id'],$quantity,$reason,mb_substr(trim($notes),0,2000),$adminId]);
            $id=(int)$this->db->lastInsertId();
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function dispositions(int $limit=200): array
    {
        $limit=max(1,min(500,$limit));
        return $this->db->query("SELECT d.*,b.batch_code,f.name flavor_name,a.email admin_email
            FROM finished_goods_dispositions d
            JOIN production_batches b ON b.id=d.batch_id
            JOIN flavors f ON f.id=d.flavor_id
            LEFT JOIN admin_users a ON a.id=d.recorded_by
            ORDER BY d.id DESC LIMIT {$limit}")->fetchAll();
    }

    public function summary(): array
    {
        $out=['expired_batches'=>0,'expired_units'=>0,'expiring_batches'=>0,'expiring_units'=>0,'no_date_batches'=>0,'disposed_30d'=>0];
        foreach($this->rows() as $row){
            $state=(string)$row['aging_state'];$qty=(int)$row['quantity_remaining'];
            if($state==='expired'){$out['expired_batches']++;$out['expired_units']+=$qty;}
            elseif($state==='expiring'){$out['expiring_batches']++;$out['expiring_units']+=$qty;}
            elseif($state==='no-date')$out['no_date_batches']++;
        }
        $out['disposed_30d']=(int)$this->db->query("SELECT COALESCE(SUM(quantity),0) FROM finished_goods_dispositions WHERE created_at>=datetime('now','-30 days')")->fetchColumn();
        return $out;
    }
}
