<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class BatchTraceabilityService
{
    public function __construct(private readonly PDO $db) {}

    public function create(array $data,int $adminId): int
    {
        $code=strtoupper(trim((string)($data['batch_code']??'')));
        if(!preg_match('/^[A-Z0-9][A-Z0-9-]{2,63}$/',$code)) throw new \InvalidArgumentException('Batch code must use letters, numbers, and hyphens.');
        $producedAt=trim((string)($data['produced_at']??''));
        if($producedAt==='' || strtotime($producedAt)===false) throw new \InvalidArgumentException('Produced date/time is required.');
        $bestBy=trim((string)($data['best_by_date']??''))?:null;
        if($bestBy!==null && strtotime($bestBy)===false) throw new \InvalidArgumentException('Best-by date is invalid.');
        $notes=mb_substr(trim((string)($data['notes']??'')),0,5000);
        $flavors=$this->normalizeFlavorQuantities((array)($data['flavor_quantity']??[]));
        if(!$flavors) throw new \InvalidArgumentException('Add at least one flavor quantity to the batch.');

        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("INSERT INTO production_batches(batch_code,produced_at,best_by_date,status,notes,created_by) VALUES(?,?,?,'draft',?,?)");
            $s->execute([$code,$producedAt,$bestBy,$notes,$adminId]);$id=(int)$this->db->lastInsertId();
            $i=$this->db->prepare('INSERT INTO production_batch_flavors(batch_id,flavor_id,quantity_produced) VALUES(?,?,?)');
            foreach($flavors as $flavorId=>$qty)$i->execute([$id,$flavorId,$qty]);
            $this->db->commit();return $id;
        }catch(\PDOException $e){
            if($this->db->inTransaction())$this->db->rollBack();
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('That batch code already exists.');
            throw $e;
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();throw $e;
        }
    }

    public function batch(int $id): array
    {
        $s=$this->db->prepare('SELECT b.*,a.email created_by_email FROM production_batches b LEFT JOIN admin_users a ON a.id=b.created_by WHERE b.id=?');
        $s->execute([$id]);$batch=$s->fetch();
        if(!$batch) throw new \InvalidArgumentException('Production batch not found.');
        $f=$this->db->prepare('SELECT bf.*,f.name,f.slug FROM production_batch_flavors bf JOIN flavors f ON f.id=bf.flavor_id WHERE bf.batch_id=? ORDER BY f.sort_order,f.name');
        $f->execute([$id]);$batch['flavors']=$f->fetchAll();
        $batch['affected_orders']=$this->affectedOrders($id);
        return $batch;
    }

    public function list(?string $status=null,int $limit=200): array
    {
        $limit=max(1,min(500,$limit));$where='';$params=[];
        if($status!==null&&$status!==''){
            if(!in_array($status,['draft','released','hold','recalled','closed'],true)) throw new \InvalidArgumentException('Invalid batch status.');
            $where=' WHERE b.status=?';$params[]=$status;
        }
        $s=$this->db->prepare("SELECT b.*,COUNT(DISTINCT opb.order_id) order_count,COALESCE(SUM(bf.quantity_produced),0) total_units FROM production_batches b LEFT JOIN order_production_batches opb ON opb.batch_id=b.id LEFT JOIN production_batch_flavors bf ON bf.batch_id=b.id{$where} GROUP BY b.id ORDER BY b.produced_at DESC,b.id DESC LIMIT {$limit}");
        $s->execute($params);return $s->fetchAll();
    }

    public function setStatus(int $id,string $status,string $reason=''): void
    {
        if(!in_array($status,['draft','released','hold','recalled','closed'],true)) throw new \InvalidArgumentException('Invalid batch status.');
        $batch=$this->batch($id);$current=(string)$batch['status'];
        $allowed=[
            'draft'=>['released','hold'],
            'released'=>['hold','closed','recalled'],
            'hold'=>['released','recalled','closed'],
            'recalled'=>['closed'],
            'closed'=>[],
        ];
        if($current===$status)return;
        if(!in_array($status,$allowed[$current]??[],true)) throw new \InvalidArgumentException("Batch cannot move from {$current} to {$status}.");
        if($status==='recalled' && trim($reason)==='') throw new \InvalidArgumentException('Recall reason is required.');

        if($status==='recalled'){
            $s=$this->db->prepare("UPDATE production_batches SET status='recalled',recall_reason=?,recalled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $s->execute([mb_substr(trim($reason),0,5000),$id]);
        }else{
            $s=$this->db->prepare('UPDATE production_batches SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $s->execute([$status,$id]);
        }
    }

    public function assignOrder(int $orderId,int $batchId,int $adminId): void
    {
        $b=$this->batch($batchId);
        if($b['status']!=='released') throw new \InvalidArgumentException('Only released production batches can be assigned to orders.');
        $o=$this->db->prepare('SELECT status FROM orders WHERE id=?');$o->execute([$orderId]);$status=$o->fetchColumn();
        if($status===false) throw new \InvalidArgumentException('Order not found.');
        if(!in_array($status,['preparing','ready','shipped','delivered','completed'],true)) throw new \InvalidArgumentException('Order must be in fulfillment before assigning a production batch.');
        $s=$this->db->prepare('INSERT OR IGNORE INTO order_production_batches(order_id,batch_id,assigned_by) VALUES(?,?,?)');
        $s->execute([$orderId,$batchId,$adminId]);
    }

    public function removeOrderBatch(int $orderId,int $batchId): void
    {
        $o=$this->db->prepare('SELECT status FROM orders WHERE id=?');$o->execute([$orderId]);$status=$o->fetchColumn();
        if($status===false) throw new \InvalidArgumentException('Order not found.');
        if(in_array($status,['shipped','delivered','completed'],true)) throw new \InvalidArgumentException('Batch traceability cannot be removed after shipment.');
        $s=$this->db->prepare('DELETE FROM order_production_batches WHERE order_id=? AND batch_id=?');$s->execute([$orderId,$batchId]);
    }

    public function batchesForOrder(int $orderId): array
    {
        $s=$this->db->prepare('SELECT b.* FROM order_production_batches opb JOIN production_batches b ON b.id=opb.batch_id WHERE opb.order_id=? ORDER BY b.produced_at,b.id');
        $s->execute([$orderId]);return $s->fetchAll();
    }

    public function assignableBatches(): array
    {
        return $this->db->query("SELECT * FROM production_batches WHERE status='released' ORDER BY produced_at DESC,id DESC")->fetchAll();
    }

    public function affectedOrders(int $batchId): array
    {
        $s=$this->db->prepare("SELECT o.id,o.order_number,o.status,o.email,o.first_name,o.last_name,o.phone,o.fulfillment_type,o.created_at
            FROM order_production_batches opb JOIN orders o ON o.id=opb.order_id
            WHERE opb.batch_id=? ORDER BY o.id");
        $s->execute([$batchId]);return $s->fetchAll();
    }

    public function recallNotificationTargets(int $batchId): array
    {
        $batch=$this->batch($batchId);
        if($batch['status']!=='recalled') throw new \InvalidArgumentException('Only recalled batches can generate recall notifications.');
        return $batch['affected_orders'];
    }

    private function normalizeFlavorQuantities(array $input): array
    {
        $out=[];
        foreach($input as $flavorId=>$qty){
            $id=(int)$flavorId;$qty=(int)$qty;
            if($id<1 || $qty<=0)continue;
            $s=$this->db->prepare('SELECT 1 FROM flavors WHERE id=?');$s->execute([$id]);
            if(!$s->fetchColumn()) throw new \InvalidArgumentException('Unknown flavor in production batch.');
            $out[$id]=$qty;
        }
        return $out;
    }
}
