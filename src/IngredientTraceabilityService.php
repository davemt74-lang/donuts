<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class IngredientTraceabilityService
{
    public function __construct(private readonly PDO $db) {}

    public function createLot(array $data,int $adminId): int
    {
        $ingredient=mb_substr(trim((string)($data['ingredient_name']??'')),0,190);
        $supplier=mb_substr(trim((string)($data['supplier_name']??'')),0,190);
        $lot=mb_substr(strtoupper(trim((string)($data['supplier_lot_code']??''))),0,120);
        $received=trim((string)($data['received_at']??''));
        $bestBy=trim((string)($data['best_by_date']??''))?:null;
        $qty=trim((string)($data['quantity_received']??''));$qty=$qty===''?null:(float)$qty;
        $unit=mb_substr(trim((string)($data['quantity_unit']??'')),0,32);
        $notes=mb_substr(trim((string)($data['notes']??'')),0,4000);

        if($ingredient==='') throw new \InvalidArgumentException('Ingredient name is required.');
        if($supplier==='') throw new \InvalidArgumentException('Supplier name is required.');
        if($lot==='') throw new \InvalidArgumentException('Supplier lot code is required.');
        $receivedTs=$received!==''?strtotime($received):false;
        if($receivedTs===false) throw new \InvalidArgumentException('Received date/time is required.');
        if($receivedTs>time()+300) throw new \InvalidArgumentException('Received date/time cannot be in the future.');
        if($bestBy!==null && strtotime($bestBy)===false) throw new \InvalidArgumentException('Best-by date is invalid.');
        if($bestBy!==null && $bestBy<gmdate('Y-m-d',$receivedTs)) throw new \InvalidArgumentException('Best-by date cannot be before received date.');
        if($qty!==null && $qty<=0) throw new \InvalidArgumentException('Received quantity must be greater than zero.');

        try{
            $s=$this->db->prepare("INSERT INTO ingredient_lots(ingredient_name,supplier_name,supplier_lot_code,received_at,best_by_date,quantity_received,quantity_unit,status,notes,created_by) VALUES(?,?,?,?,?,?,?,'active',?,?)");
            $s->execute([$ingredient,$supplier,$lot,$received,$bestBy,$qty,$unit,$notes,$adminId]);
            return (int)$this->db->lastInsertId();
        }catch(\PDOException $e){
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('That supplier ingredient lot already exists.');
            throw $e;
        }
    }

    public function lots(?string $status=null): array
    {
        $where='';$params=[];
        if($status!==null&&$status!==''){
            if(!in_array($status,['active','hold','recalled'],true)) throw new \InvalidArgumentException('Invalid ingredient lot status.');
            $where=' WHERE l.status=?';$params[]=$status;
        }
        $s=$this->db->prepare("SELECT l.*,(SELECT COUNT(*) FROM production_batch_ingredients pbi WHERE pbi.ingredient_lot_id=l.id) batch_count FROM ingredient_lots l{$where} ORDER BY l.received_at DESC,l.id DESC");
        $s->execute($params);return $s->fetchAll();
    }

    public function lot(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM ingredient_lots WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Ingredient lot not found.');
        $row['batches']=$this->affectedBatches($id);$row['orders']=$this->affectedOrders($id);
        return $row;
    }

    public function setHold(int $id,bool $hold): void
    {
        $lot=$this->lot($id);
        if($lot['status']==='recalled') throw new \InvalidArgumentException('Recalled ingredient lots cannot be released.');
        if(!$hold && !empty($lot['best_by_date']) && (string)$lot['best_by_date']<gmdate('Y-m-d')) throw new \InvalidArgumentException('Expired ingredient lots cannot be released from hold.');
        $s=$this->db->prepare('UPDATE ingredient_lots SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([$hold?'hold':'active',$id]);
    }

    public function recall(int $id,string $reason,int $adminId): array
    {
        $reason=mb_substr(trim($reason),0,4000);if($reason==='') throw new \InvalidArgumentException('Recall reason is required.');
        $lot=$this->lot($id);
        if($lot['status']!=='recalled'){
            $s=$this->db->prepare("UPDATE ingredient_lots SET status='recalled',recall_reason=?,recalled_at=CURRENT_TIMESTAMP,recalled_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $s->execute([$reason,$adminId,$id]);
        }
        return $this->affectedOrders($id);
    }

    public function linkBatch(int $batchId,int $lotId,?float $quantity,string $unit,int $adminId): void
    {
        $batch=$this->productionBatch($batchId);
        if($batch['status']==='recalled') throw new \InvalidArgumentException('Recalled production batches cannot accept ingredient links.');
        if($this->batchHasShippedOrders($batchId)) throw new \InvalidArgumentException('Ingredient provenance cannot be changed after a linked production batch has shipped.');
        $lot=$this->lot($lotId);
        if($lot['status']!=='active') throw new \InvalidArgumentException('Only active ingredient lots can be linked.');
        $receivedTs=strtotime((string)$lot['received_at']);
        $batchTs=strtotime((string)$batch['produced_at']);
        if($receivedTs===false || $receivedTs>time()) throw new \InvalidArgumentException('Future-dated ingredient lots cannot be linked.');
        if($batchTs!==false && $receivedTs>$batchTs) throw new \InvalidArgumentException('Ingredient lot was received after this production batch was produced.');
        if(!empty($lot['best_by_date']) && (string)$lot['best_by_date']<gmdate('Y-m-d')) throw new \InvalidArgumentException('Expired ingredient lots cannot be linked.');
        if($quantity!==null && $quantity<=0) throw new \InvalidArgumentException('Ingredient quantity used must be greater than zero.');
        $unit=mb_substr(trim($unit),0,32);
        if($quantity!==null && $lot['quantity_received']!==null){
            $receivedUnit=trim((string)$lot['quantity_unit']);
            if($receivedUnit!=='' && $unit!==$receivedUnit) throw new \InvalidArgumentException('Ingredient usage unit must match the received lot unit.');
            $q=$this->db->prepare('SELECT COALESCE(SUM(quantity_used),0) FROM production_batch_ingredients WHERE ingredient_lot_id=? AND batch_id<>?');
            $q->execute([$lotId,$batchId]);$used=(float)$q->fetchColumn();
            if($used+$quantity>(float)$lot['quantity_received']+0.000001) throw new \InvalidArgumentException('Ingredient usage exceeds the received lot quantity.');
        }
        $s=$this->db->prepare('INSERT INTO production_batch_ingredients(batch_id,ingredient_lot_id,quantity_used,quantity_unit,linked_by) VALUES(?,?,?,?,?) ON CONFLICT(batch_id,ingredient_lot_id) DO UPDATE SET quantity_used=excluded.quantity_used,quantity_unit=excluded.quantity_unit,linked_by=excluded.linked_by,linked_at=CURRENT_TIMESTAMP');
        $s->execute([$batchId,$lotId,$quantity,$unit,$adminId]);
    }

    public function unlinkBatch(int $batchId,int $lotId): void
    {
        if($this->batchHasShippedOrders($batchId)) throw new \InvalidArgumentException('Ingredient provenance cannot be removed after a linked production batch has shipped.');
        $s=$this->db->prepare('DELETE FROM production_batch_ingredients WHERE batch_id=? AND ingredient_lot_id=?');$s->execute([$batchId,$lotId]);
    }

    public function ingredientsForBatch(int $batchId): array
    {
        $s=$this->db->prepare('SELECT pbi.*,l.ingredient_name,l.supplier_name,l.supplier_lot_code,l.received_at,l.best_by_date,l.status,l.recall_reason FROM production_batch_ingredients pbi JOIN ingredient_lots l ON l.id=pbi.ingredient_lot_id WHERE pbi.batch_id=? ORDER BY l.ingredient_name,l.supplier_name,l.supplier_lot_code');
        $s->execute([$batchId]);return $s->fetchAll();
    }

    public function batchRisk(int $batchId): array
    {
        $lots=$this->ingredientsForBatch($batchId);
        if(!$lots) return ['ok'=>true,'linked'=>false,'reason'=>''];
        foreach($lots as $lot){
            if(in_array($lot['status'],['hold','recalled'],true)) return ['ok'=>false,'linked'=>true,'reason'=>'A linked ingredient lot is on hold or recalled.'];
            if(strtotime((string)$lot['received_at'])>time()) return ['ok'=>false,'linked'=>true,'reason'=>'A linked ingredient lot has a future received timestamp.'];
            if(!empty($lot['best_by_date']) && (string)$lot['best_by_date']<gmdate('Y-m-d')) return ['ok'=>false,'linked'=>true,'reason'=>'A linked ingredient lot is past its best-by date.'];
        }
        return ['ok'=>true,'linked'=>true,'reason'=>''];
    }

    public function affectedBatches(int $lotId): array
    {
        $s=$this->db->prepare('SELECT b.id,b.batch_code,b.status,b.produced_at,b.best_by_date,f.name flavor_name,pbi.quantity_used,pbi.quantity_unit FROM production_batch_ingredients pbi JOIN production_batches b ON b.id=pbi.batch_id JOIN flavors f ON f.id=b.flavor_id WHERE pbi.ingredient_lot_id=? ORDER BY b.produced_at,b.id');
        $s->execute([$lotId]);return $s->fetchAll();
    }

    public function affectedOrders(int $lotId): array
    {
        $s=$this->db->prepare("SELECT DISTINCT o.id,o.order_number,o.status,o.email,o.first_name,o.last_name,o.created_at
            FROM production_batch_ingredients pbi
            JOIN order_batch_assignments oba ON oba.batch_id=pbi.batch_id
            JOIN orders o ON o.id=oba.order_id
            WHERE pbi.ingredient_lot_id=? ORDER BY o.id");
        $s->execute([$lotId]);return $s->fetchAll();
    }

    public function summary(): array
    {
        $out=['active'=>0,'hold'=>0,'recalled'=>0,'expired_active'=>0,'unlinked_batches'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count FROM ingredient_lots GROUP BY status')->fetchAll() as $r)$out[(string)$r['status']]=(int)$r['count'];
        $out['expired_active']=(int)$this->db->query("SELECT COUNT(*) FROM ingredient_lots WHERE status='active' AND best_by_date IS NOT NULL AND best_by_date<date('now')")->fetchColumn();
        $out['unlinked_batches']=(int)$this->db->query("SELECT COUNT(*) FROM production_batches b WHERE NOT EXISTS(SELECT 1 FROM production_batch_ingredients pbi WHERE pbi.batch_id=b.id)")->fetchColumn();
        return $out;
    }

    private function batchHasShippedOrders(int $batchId): bool
    {
        $q=$this->db->prepare("SELECT COUNT(*) FROM order_batch_assignments a JOIN orders o ON o.id=a.order_id WHERE a.batch_id=? AND o.status IN ('shipped','delivered','completed')");
        $q->execute([$batchId]);return (int)$q->fetchColumn()>0;
    }

    private function productionBatch(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM production_batches WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Production batch not found.');
        return $row;
    }
}
