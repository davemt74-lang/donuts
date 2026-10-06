<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class PackagingInventoryService
{
    public function __construct(private readonly PDO $db) {}

    public function enabled(): bool
    {
        try{
            $s=$this->db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name='packaging_materials'");$s->execute();
            return (bool)$s->fetchColumn();
        }catch(\Throwable){return false;}
    }

    public function materials(): array
    {
        return $this->db->query("SELECT m.*,
          COALESCE((SELECT SUM(a.quantity) FROM order_packaging_allocations a WHERE a.material_id=m.id AND a.status='reserved'),0) reserved_units
          FROM packaging_materials m ORDER BY active DESC,name")->fetchAll();
    }

    public function saveMaterial(array $data,int $adminId): int
    {
        $id=(int)($data['id']??0);$sku=strtoupper(trim((string)($data['sku']??'')));$name=trim((string)($data['name']??''));
        if(!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,79}$/',$sku)) throw new \InvalidArgumentException('Packaging SKU must use letters, numbers, dots, dashes or underscores.');
        if($name==='') throw new \InvalidArgumentException('Packaging material name is required.');
        $reorder=max(0,(int)($data['reorder_point']??0));$reorderQty=max(0,(int)($data['reorder_quantity']??0));
        $unit=mb_substr(trim((string)($data['unit']??'each'))?:'each',0,40);$notes=mb_substr(trim((string)($data['notes']??'')),0,2000);
        try{
            if($id){
                $s=$this->db->prepare('UPDATE packaging_materials SET sku=?,name=?,unit=?,reorder_point=?,reorder_quantity=?,active=?,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $s->execute([$sku,$name,$unit,$reorder,$reorderQty,!empty($data['active'])?1:0,$notes,$id]);
                if($s->rowCount()===0 && !$this->material($id)) throw new \InvalidArgumentException('Packaging material not found.');
                return $id;
            }
            $s=$this->db->prepare('INSERT INTO packaging_materials(sku,name,unit,reorder_point,reorder_quantity,active,notes) VALUES(?,?,?,?,?,?,?)');
            $s->execute([$sku,$name,$unit,$reorder,$reorderQty,!empty($data['active'])?1:0,$notes]);return (int)$this->db->lastInsertId();
        }catch(\PDOException $e){
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('Packaging SKU already exists.');
            throw $e;
        }
    }

    public function adjust(int $materialId,int $newQuantity,string $reason,int $adminId): void
    {
        if($newQuantity<0 || $newQuantity>1000000) throw new \InvalidArgumentException('Packaging stock must be between 0 and 1000000.');
        $reason=mb_substr(trim($reason),0,500);if($reason==='') throw new \InvalidArgumentException('Adjustment reason is required.');
        $this->transaction(function() use($materialId,$newQuantity,$reason,$adminId): void {
            $m=$this->material($materialId);if(!$m) throw new \InvalidArgumentException('Packaging material not found.');
            $current=(int)$m['stock_on_hand'];$delta=$newQuantity-$current;
            $u=$this->db->prepare('UPDATE packaging_materials SET stock_on_hand=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND stock_on_hand=?');
            $u->execute([$newQuantity,$materialId,$current]);if($u->rowCount()!==1) throw new \RuntimeException('Packaging stock changed before adjustment.');
            $this->movement($materialId,null,'adjustment',$delta,$newQuantity,$reason,$adminId);
        });
    }

    public function receive(int $materialId,int $quantity,string $reason,int $adminId): void
    {
        if($quantity<=0 || $quantity>1000000) throw new \InvalidArgumentException('Received quantity must be between 1 and 1000000.');
        $reason=mb_substr(trim($reason),0,500)?:'Packaging received.';
        $this->transaction(function() use($materialId,$quantity,$reason,$adminId): void {
            $m=$this->material($materialId);if(!$m) throw new \InvalidArgumentException('Packaging material not found.');
            $balance=(int)$m['stock_on_hand']+$quantity;
            $u=$this->db->prepare('UPDATE packaging_materials SET stock_on_hand=stock_on_hand+?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $u->execute([$quantity,$materialId]);$this->movement($materialId,null,'receive',$quantity,$balance,$reason,$adminId);
        });
    }

    public function requirements(): array
    {
        return $this->db->query('SELECT r.*,p.size,p.name pack_name,m.sku,m.name material_name FROM pack_packaging_requirements r JOIN pack_sizes p ON p.id=r.pack_size_id JOIN packaging_materials m ON m.id=r.material_id ORDER BY p.size,m.name')->fetchAll();
    }

    public function setRequirement(int $packId,int $materialId,int $quantity): void
    {
        if($quantity<0 || $quantity>1000) throw new \InvalidArgumentException('Packaging requirement must be between 0 and 1000.');
        if($quantity===0){$s=$this->db->prepare('DELETE FROM pack_packaging_requirements WHERE pack_size_id=? AND material_id=?');$s->execute([$packId,$materialId]);return;}
        $s=$this->db->prepare('INSERT INTO pack_packaging_requirements(pack_size_id,material_id,quantity_per_box) VALUES(?,?,?) ON CONFLICT(pack_size_id,material_id) DO UPDATE SET quantity_per_box=excluded.quantity_per_box');
        $s->execute([$packId,$materialId,$quantity]);
    }

    public function orderNeeds(int $orderId): array
    {
        $s=$this->db->prepare("SELECT r.material_id,m.sku,m.name,COALESCE(SUM(oi.quantity*r.quantity_per_box),0) quantity
          FROM order_items oi JOIN pack_sizes p ON p.size=oi.pack_size
          JOIN pack_packaging_requirements r ON r.pack_size_id=p.id
          JOIN packaging_materials m ON m.id=r.material_id AND m.active=1
          WHERE oi.order_id=? GROUP BY r.material_id,m.sku,m.name ORDER BY m.name");
        $s->execute([$orderId]);return $s->fetchAll();
    }

    public function reserveOrder(int $orderId,int $adminId=0): void
    {
        $this->transaction(function() use($orderId,$adminId): void {
            $existing=$this->db->prepare("SELECT COUNT(*) FROM order_packaging_allocations WHERE order_id=? AND status IN ('reserved','consumed')");$existing->execute([$orderId]);
            if((int)$existing->fetchColumn()>0) return;
            $needs=$this->orderNeeds($orderId);
            foreach($needs as $need){
                $m=$this->material((int)$need['material_id']);$qty=(int)$need['quantity'];
                if(!$m || !(int)$m['active'] || (int)$m['stock_on_hand']<$qty) throw new \RuntimeException('Insufficient packaging: '.$need['name'].'.');
            }
            $dec=$this->db->prepare('UPDATE packaging_materials SET stock_on_hand=stock_on_hand-?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND active=1 AND stock_on_hand>=?');
            $ins=$this->db->prepare("INSERT INTO order_packaging_allocations(order_id,material_id,quantity,status) VALUES(?,?,?,'reserved')");
            foreach($needs as $need){
                $mid=(int)$need['material_id'];$qty=(int)$need['quantity'];$before=$this->material($mid);
                $dec->execute([$qty,$mid,$qty]);if($dec->rowCount()!==1) throw new \RuntimeException('Packaging inventory changed during reservation.');
                $balance=(int)$before['stock_on_hand']-$qty;$ins->execute([$orderId,$mid,$qty]);
                $this->movement($mid,$orderId,'reserve',-$qty,$balance,'Reserved for order fulfillment.',$adminId?:null);
            }
        });
    }

    public function consumeOrder(int $orderId,int $adminId=0): void
    {
        $this->transaction(function() use($orderId,$adminId): void {
            $s=$this->db->prepare("SELECT a.*,m.stock_on_hand FROM order_packaging_allocations a JOIN packaging_materials m ON m.id=a.material_id WHERE a.order_id=? AND a.status='reserved'");$s->execute([$orderId]);$rows=$s->fetchAll();
            $u=$this->db->prepare("UPDATE order_packaging_allocations SET status='consumed',consumed_at=CURRENT_TIMESTAMP WHERE order_id=? AND material_id=? AND status='reserved'");
            foreach($rows as $row){$u->execute([$orderId,(int)$row['material_id']]);$this->movement((int)$row['material_id'],$orderId,'consume',0,(int)$row['stock_on_hand'],'Packaging consumed by fulfillment.',$adminId?:null);}
        });
    }

    public function releaseOrder(int $orderId,int $adminId=0): void
    {
        $this->transaction(function() use($orderId,$adminId): void {
            $s=$this->db->prepare("SELECT * FROM order_packaging_allocations WHERE order_id=? AND status='reserved'");$s->execute([$orderId]);$rows=$s->fetchAll();
            $inc=$this->db->prepare('UPDATE packaging_materials SET stock_on_hand=stock_on_hand+?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $u=$this->db->prepare("UPDATE order_packaging_allocations SET status='released',released_at=CURRENT_TIMESTAMP WHERE order_id=? AND material_id=? AND status='reserved'");
            foreach($rows as $row){$mid=(int)$row['material_id'];$qty=(int)$row['quantity'];$m=$this->material($mid);$inc->execute([$qty,$mid]);$u->execute([$orderId,$mid]);$this->movement($mid,$orderId,'release',$qty,(int)$m['stock_on_hand']+$qty,'Packaging reservation released.',$adminId?:null);}
        });
    }

    public function plan(): array
    {
        $paidDemand=[];
        $orders=$this->db->query("SELECT id FROM orders WHERE status='paid' ORDER BY id")->fetchAll();
        foreach($orders as $order)foreach($this->orderNeeds((int)$order['id']) as $need)$paidDemand[(int)$need['material_id']]=($paidDemand[(int)$need['material_id']]??0)+(int)$need['quantity'];
        $rows=[];$shortages=0;$suggested=0;
        foreach($this->materials() as $m){
            if(!(int)$m['active'])continue;$id=(int)$m['id'];$demand=(int)($paidDemand[$id]??0);$stock=(int)$m['stock_on_hand'];$target=(int)$m['reorder_point']+$demand;
            $short=max(0,$target-$stock);$buy=$short>0?max((int)$m['reorder_quantity'],$short):0;
            if($short>0)$shortages++;$suggested+=$buy;
            $m['paid_order_demand']=$demand;$m['target_units']=$target;$m['shortage_units']=$short;$m['suggested_purchase']=$buy;$rows[]=$m;
        }
        usort($rows,fn($a,$b)=>($b['shortage_units']<=>$a['shortage_units']) ?: strcmp($a['name'],$b['name']));
        return ['rows'=>$rows,'shortage_materials'=>$shortages,'suggested_purchase_units'=>$suggested];
    }

    public function movements(int $materialId,int $limit=100): array
    {
        $limit=max(1,min(500,$limit));$s=$this->db->prepare("SELECT x.*,o.order_number,a.email admin_email FROM packaging_stock_movements x LEFT JOIN orders o ON o.id=x.order_id LEFT JOIN admin_users a ON a.id=x.recorded_by WHERE x.material_id=? ORDER BY x.id DESC LIMIT {$limit}");$s->execute([$materialId]);return $s->fetchAll();
    }

    private function material(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM packaging_materials WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;
    }

    private function movement(int $materialId,?int $orderId,string $type,int $delta,int $balance,string $reason,?int $adminId): void
    {
        $s=$this->db->prepare('INSERT INTO packaging_stock_movements(material_id,order_id,movement_type,quantity_delta,balance_after,reason,recorded_by) VALUES(?,?,?,?,?,?,?)');
        $s->execute([$materialId,$orderId,$type,$delta,$balance,$reason,$adminId]);
    }

    private function transaction(callable $callback): void
    {
        $owned=!$this->db->inTransaction();if($owned)$this->db->beginTransaction();
        try{$callback();if($owned)$this->db->commit();}catch(\Throwable $e){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
