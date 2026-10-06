<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class PackagingPurchasingService
{
    public function __construct(private readonly PDO $db) {}

    public function supplierItems(?int $supplierId=null,bool $activeOnly=true): array
    {
        $where=[];$params=[];if($supplierId){$where[]='i.supplier_id=?';$params[]=$supplierId;}if($activeOnly)$where[]='i.active=1 AND s.active=1 AND m.active=1';
        $s=$this->db->prepare('SELECT i.*,s.name supplier_name,s.active supplier_active,m.sku,m.name material_name,m.stock_on_hand,m.reorder_point,m.reorder_quantity FROM packaging_supplier_items i JOIN suppliers s ON s.id=i.supplier_id JOIN packaging_materials m ON m.id=i.material_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY s.name,m.name');
        $s->execute($params);return $s->fetchAll();
    }

    public function saveSupplierItem(array $data): int
    {
        $id=(int)($data['id']??0);$supplier=(int)($data['supplier_id']??0);$material=(int)($data['material_id']??0);
        $this->assertSupplier($supplier);$this->assertMaterial($material);
        $sku=mb_substr(trim((string)($data['supplier_sku']??'')),0,120);$cost=max(0,(int)($data['unit_cost_cents']??0));$lead=max(0,min(365,(int)($data['lead_time_days']??0)));
        $min=trim((string)($data['min_order_quantity']??''));$min=$min===''?null:max(1,(int)$min);
        try{
            if($id){
                $s=$this->db->prepare('UPDATE packaging_supplier_items SET supplier_id=?,material_id=?,supplier_sku=?,unit_cost_cents=?,lead_time_days=?,min_order_quantity=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $s->execute([$supplier,$material,$sku,$cost,$lead,$min,!empty($data['active'])?1:0,$id]);
                if($s->rowCount()===0 && !$this->supplierItem($id)) throw new \InvalidArgumentException('Packaging supplier item not found.');
                return $id;
            }
            $s=$this->db->prepare('INSERT INTO packaging_supplier_items(supplier_id,material_id,supplier_sku,unit_cost_cents,lead_time_days,min_order_quantity,active) VALUES(?,?,?,?,?,?,?)');
            $s->execute([$supplier,$material,$sku,$cost,$lead,$min,!empty($data['active'])?1:0]);return (int)$this->db->lastInsertId();
        }catch(\PDOException $e){
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('That supplier already has a packaging item for this material.');
            throw $e;
        }
    }

    public function recommendations(): array
    {
        $plan=(new PackagingInventoryService($this->db))->plan();$items=$this->supplierItems();$byMaterial=[];
        foreach($items as $item)$byMaterial[(int)$item['material_id']][]=$item;
        $openSupply=[];
        $s=$this->db->query("SELECT i.material_id,COALESCE(SUM(i.quantity_ordered-i.quantity_received),0) qty
            FROM packaging_purchase_order_items i JOIN packaging_purchase_orders p ON p.id=i.purchase_order_id
            WHERE p.status IN ('ordered','partially_received') GROUP BY i.material_id");
        foreach($s->fetchAll() as $supply)$openSupply[(int)$supply['material_id']]=(int)$supply['qty'];

        $rows=[];$critical=0;$cost=0;
        foreach($plan['rows'] as $row){
            $materialId=(int)$row['id'];$incoming=(int)($openSupply[$materialId]??0);
            $needed=max(0,(int)$row['suggested_purchase']-$incoming);
            if($needed<=0)continue;
            $choices=$byMaterial[$materialId]??[];
            usort($choices,fn($a,$b)=>((int)$a['unit_cost_cents']<=>(int)$b['unit_cost_cents']) ?: ((int)$a['lead_time_days']<=>(int)$b['lead_time_days']));
            $best=$choices[0]??null;$qty=$needed;
            if($best && $best['min_order_quantity']!==null)$qty=max($qty,(int)$best['min_order_quantity']);
            if(!$best)$critical++;else $cost+=$qty*(int)$best['unit_cost_cents'];
            $rows[]=$row+['incoming_units'=>$incoming,'supplier_item'=>$best,'recommended_order_quantity'=>$qty,'estimated_cost_cents'=>$best?$qty*(int)$best['unit_cost_cents']:0];
        }
        return ['rows'=>$rows,'critical'=>$critical,'estimated_cost_cents'=>$cost];
    }

    public function createPurchaseOrder(int $supplierId,array $lines,string $expectedAt,string $notes,int $adminId): int
    {
        $this->assertSupplier($supplierId);$clean=[];$seen=[];
        foreach($lines as $line){
            $itemId=(int)($line['supplier_item_id']??0);$qty=(int)($line['quantity_ordered']??0);if($itemId<1||$qty<=0)continue;
            if(isset($seen[$itemId])) throw new \InvalidArgumentException('A packaging item can appear only once on a purchase order.');$seen[$itemId]=true;
            $item=$this->supplierItem($itemId);if((int)$item['supplier_id']!==$supplierId || !(int)$item['active']) throw new \InvalidArgumentException('Packaging item is not active for this supplier.');
            if($item['min_order_quantity']!==null && $qty<(int)$item['min_order_quantity']) throw new \InvalidArgumentException($item['material_name'].' is below its minimum order quantity.');
            $clean[]=['item'=>$item,'qty'=>$qty];
        }
        if(!$clean) throw new \InvalidArgumentException('Packaging purchase order needs at least one item.');
        $expectedAt=trim($expectedAt);if($expectedAt!==''&&strtotime($expectedAt)===false) throw new \InvalidArgumentException('Expected date is invalid.');
        if($expectedAt!==''&&substr($expectedAt,0,10)<gmdate('Y-m-d')) throw new \InvalidArgumentException('Expected date cannot be in the past.');
        $number='PKG-PO-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("INSERT INTO packaging_purchase_orders(po_number,supplier_id,status,expected_at,notes,created_by) VALUES(?,?,'draft',?,?,?)");$s->execute([$number,$supplierId,$expectedAt?:null,mb_substr(trim($notes),0,4000),$adminId]);$id=(int)$this->db->lastInsertId();
            $i=$this->db->prepare('INSERT INTO packaging_purchase_order_items(purchase_order_id,supplier_item_id,material_id,material_name,quantity_ordered,unit_cost_cents) VALUES(?,?,?,?,?,?)');
            foreach($clean as $line){$x=$line['item'];$i->execute([$id,(int)$x['id'],(int)$x['material_id'],$x['material_name'],$line['qty'],(int)$x['unit_cost_cents']]);}
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function markOrdered(int $id): void
    {
        $s=$this->db->prepare("UPDATE packaging_purchase_orders SET status='ordered',ordered_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='draft'");$s->execute([$id]);
        if($s->rowCount()!==1) throw new \InvalidArgumentException('Only draft packaging purchase orders can be ordered.');
    }

    public function cancel(int $id): void
    {
        $po=$this->purchaseOrder($id);if(!in_array($po['status'],['draft','ordered'],true)) throw new \InvalidArgumentException('Only draft or unreceived ordered packaging POs can be cancelled.');
        foreach($po['items'] as $item)if((int)$item['quantity_received']>0) throw new \InvalidArgumentException('A packaging PO with receipts cannot be cancelled.');
        $s=$this->db->prepare("UPDATE packaging_purchase_orders SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=?");$s->execute([$id]);
    }

    public function receive(int $poItemId,int $quantity,string $receivedAt,string $notes,int $adminId): void
    {
        $item=$this->purchaseOrderItem($poItemId);$po=$this->purchaseOrder((int)$item['purchase_order_id']);
        if(!in_array($po['status'],['ordered','partially_received'],true)) throw new \InvalidArgumentException('Packaging purchase order must be ordered before receiving.');
        if($quantity<=0) throw new \InvalidArgumentException('Received quantity must be greater than zero.');
        $remaining=(int)$item['quantity_ordered']-(int)$item['quantity_received'];if($quantity>$remaining) throw new \InvalidArgumentException('Receipt exceeds remaining packaging PO quantity.');
        if(trim($receivedAt)===''||strtotime($receivedAt)===false) throw new \InvalidArgumentException('Received date/time is required.');

        $this->db->beginTransaction();
        try{
            (new PackagingInventoryService($this->db))->receive((int)$item['material_id'],$quantity,'Received against '.$po['po_number'].'. '.trim($notes),$adminId);
            $u=$this->db->prepare('UPDATE packaging_purchase_order_items SET quantity_received=quantity_received+? WHERE id=? AND quantity_received+?<=quantity_ordered');$u->execute([$quantity,$poItemId,$quantity]);if($u->rowCount()!==1) throw new \RuntimeException('Packaging PO quantity changed during receipt.');
            $r=$this->db->prepare('INSERT INTO packaging_purchase_receipts(purchase_order_item_id,quantity_received,received_at,notes,received_by) VALUES(?,?,?,?,?)');$r->execute([$poItemId,$quantity,$receivedAt,mb_substr(trim($notes),0,2000),$adminId]);
            $this->refreshStatus((int)$po['id']);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function purchaseOrders(?string $status=null): array
    {
        $where='';$params=[];if($status!==null&&$status!==''){if(!in_array($status,['draft','ordered','partially_received','received','cancelled'],true)) throw new \InvalidArgumentException('Invalid packaging PO status.');$where=' WHERE p.status=?';$params[]=$status;}
        $s=$this->db->prepare("SELECT p.*,s.name supplier_name,(SELECT COALESCE(SUM(i.quantity_ordered*i.unit_cost_cents),0) FROM packaging_purchase_order_items i WHERE i.purchase_order_id=p.id) total_cents FROM packaging_purchase_orders p JOIN suppliers s ON s.id=p.supplier_id{$where} ORDER BY CASE p.status WHEN 'ordered' THEN 0 WHEN 'partially_received' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END,p.expected_at,p.id DESC");
        $s->execute($params);return $s->fetchAll();
    }

    public function purchaseOrder(int $id): array
    {
        $s=$this->db->prepare('SELECT p.*,s.name supplier_name,s.email supplier_email FROM packaging_purchase_orders p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=?');$s->execute([$id]);$po=$s->fetch();if(!$po) throw new \InvalidArgumentException('Packaging purchase order not found.');
        $i=$this->db->prepare('SELECT i.*,(i.quantity_ordered-i.quantity_received) remaining_quantity FROM packaging_purchase_order_items i WHERE i.purchase_order_id=? ORDER BY i.id');$i->execute([$id]);$po['items']=$i->fetchAll();
        return $po;
    }

    public function summary(): array
    {
        $out=['open_po'=>(int)$this->db->query("SELECT COUNT(*) FROM packaging_purchase_orders WHERE status IN ('ordered','partially_received')")->fetchColumn(),'overdue_po'=>0,'critical_supplier_gaps'=>0];
        $out['overdue_po']=(int)$this->db->query("SELECT COUNT(*) FROM packaging_purchase_orders WHERE status IN ('ordered','partially_received') AND expected_at IS NOT NULL AND expected_at<date('now')")->fetchColumn();
        $out['critical_supplier_gaps']=(int)$this->recommendations()['critical'];return $out;
    }

    private function supplierItem(int $id): ?array
    {
        $s=$this->db->prepare('SELECT i.*,m.name material_name FROM packaging_supplier_items i JOIN packaging_materials m ON m.id=i.material_id WHERE i.id=?');$s->execute([$id]);return $s->fetch()?:null;
    }

    private function purchaseOrderItem(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM packaging_purchase_order_items WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row) throw new \InvalidArgumentException('Packaging purchase order item not found.');return $row;
    }

    private function assertSupplier(int $id): void
    {
        $s=$this->db->prepare('SELECT active FROM suppliers WHERE id=?');$s->execute([$id]);$active=$s->fetchColumn();if($active===false||!(int)$active) throw new \InvalidArgumentException('Choose an active supplier.');
    }

    private function assertMaterial(int $id): void
    {
        $s=$this->db->prepare('SELECT active FROM packaging_materials WHERE id=?');$s->execute([$id]);$active=$s->fetchColumn();if($active===false||!(int)$active) throw new \InvalidArgumentException('Choose an active packaging material.');
    }

    private function refreshStatus(int $id): void
    {
        $s=$this->db->prepare('SELECT COUNT(*) total,SUM(CASE WHEN quantity_received>=quantity_ordered THEN 1 ELSE 0 END) complete,SUM(CASE WHEN quantity_received>0 THEN 1 ELSE 0 END) touched FROM packaging_purchase_order_items WHERE purchase_order_id=?');$s->execute([$id]);$x=$s->fetch();
        $status=((int)$x['complete']===(int)$x['total'])?'received':((int)$x['touched']>0?'partially_received':'ordered');
        $u=$this->db->prepare('UPDATE packaging_purchase_orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([$status,$id]);
    }
}
