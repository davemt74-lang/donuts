<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class SupplierPurchasingService
{
    public function __construct(private readonly PDO $db) {}

    public function createSupplier(array $data,int $adminId): int
    {
        $name=mb_substr(trim((string)($data['name']??'')),0,190);
        $email=strtolower(trim((string)($data['email']??'')));
        if($name==='') throw new \InvalidArgumentException('Supplier name is required.');
        if($email!=='' && filter_var($email,FILTER_VALIDATE_EMAIL)===false) throw new \InvalidArgumentException('Supplier email is invalid.');
        try{
            $s=$this->db->prepare('INSERT INTO suppliers(name,contact_name,email,phone,address,active,notes,created_by) VALUES(?,?,?,?,?,?,?,?)');
            $s->execute([$name,mb_substr(trim((string)($data['contact_name']??'')),0,190),$email,mb_substr(trim((string)($data['phone']??'')),0,64),mb_substr(trim((string)($data['address']??'')),0,2000),!empty($data['active'])?1:0,mb_substr(trim((string)($data['notes']??'')),0,4000),$adminId]);
            return (int)$this->db->lastInsertId();
        }catch(\PDOException $e){
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('Supplier name already exists.');
            throw $e;
        }
    }

    public function supplier(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM suppliers WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Supplier not found.');
        $row['items']=$this->items($id,false);return $row;
    }

    public function suppliers(bool $activeOnly=false): array
    {
        return $this->db->query('SELECT s.*,(SELECT COUNT(*) FROM supplier_items i WHERE i.supplier_id=s.id AND i.active=1) active_items FROM suppliers s'.($activeOnly?' WHERE s.active=1':'').' ORDER BY s.active DESC,s.name')->fetchAll();
    }

    public function setSupplierActive(int $id,bool $active): void
    {
        $s=$this->db->prepare('UPDATE suppliers SET active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$active?1:0,$id]);
        if($s->rowCount()!==1 && !$this->exists('suppliers',$id)) throw new \InvalidArgumentException('Supplier not found.');
    }

    public function createItem(int $supplierId,array $data): int
    {
        $supplier=$this->supplier($supplierId);if(!(int)$supplier['active']) throw new \InvalidArgumentException('Supplier is inactive.');
        $ingredient=mb_substr(trim((string)($data['ingredient_name']??'')),0,190);
        $unit=mb_strtolower(mb_substr(trim((string)($data['quantity_unit']??'')),0,32));
        if($ingredient===''||$unit==='') throw new \InvalidArgumentException('Ingredient name and unit are required.');
        $cost=max(0,(int)($data['unit_cost_cents']??0));$lead=max(0,(int)($data['lead_time_days']??0));
        $min=trim((string)($data['min_order_quantity']??''));$min=$min===''?null:(float)$min;if($min!==null&&$min<=0)throw new \InvalidArgumentException('Minimum order quantity must be greater than zero.');
        try{
            $s=$this->db->prepare('INSERT INTO supplier_items(supplier_id,ingredient_name,supplier_sku,quantity_unit,unit_cost_cents,lead_time_days,min_order_quantity,active) VALUES(?,?,?,?,?,?,?,1)');
            $s->execute([$supplierId,$ingredient,mb_substr(trim((string)($data['supplier_sku']??'')),0,120),$unit,$cost,$lead,$min]);return (int)$this->db->lastInsertId();
        }catch(\PDOException $e){
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('That supplier ingredient/unit already exists.');
            throw $e;
        }
    }

    public function items(?int $supplierId=null,bool $activeOnly=true): array
    {
        $where=[];$params=[];if($supplierId){$where[]='i.supplier_id=?';$params[]=$supplierId;}if($activeOnly)$where[]='i.active=1';
        $s=$this->db->prepare('SELECT i.*,s.name supplier_name,s.active supplier_active FROM supplier_items i JOIN suppliers s ON s.id=i.supplier_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY s.name,i.ingredient_name,i.quantity_unit');
        $s->execute($params);return $s->fetchAll();
    }

    public function createPurchaseOrder(int $supplierId,array $lines,string $expectedAt,string $notes,int $adminId): int
    {
        $supplier=$this->supplier($supplierId);if(!(int)$supplier['active']) throw new \InvalidArgumentException('Supplier is inactive.');
        $clean=[];foreach($lines as $line){
            $itemId=(int)($line['supplier_item_id']??0);$qty=(float)($line['quantity_ordered']??0);if($itemId<1||$qty<=0)continue;
            $item=$this->item($itemId);if((int)$item['supplier_id']!==$supplierId||!(int)$item['active'])throw new \InvalidArgumentException('Purchase order item is not active for this supplier.');
            if($item['min_order_quantity']!==null && $qty+0.000001<(float)$item['min_order_quantity'])throw new \InvalidArgumentException($item['ingredient_name'].' is below its minimum order quantity.');
            $clean[]=['item'=>$item,'qty'=>$qty];
        }
        if(!$clean) throw new \InvalidArgumentException('Purchase order needs at least one item.');
        $expectedAt=trim($expectedAt);if($expectedAt!==''&&strtotime($expectedAt)===false)throw new \InvalidArgumentException('Expected date is invalid.');
        $number='PO-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("INSERT INTO purchase_orders(po_number,supplier_id,status,expected_at,notes,created_by) VALUES(?,?,'draft',?,?,?)");
            $s->execute([$number,$supplierId,$expectedAt?:null,mb_substr(trim($notes),0,4000),$adminId]);$id=(int)$this->db->lastInsertId();
            $i=$this->db->prepare('INSERT INTO purchase_order_items(purchase_order_id,supplier_item_id,ingredient_name,quantity_ordered,quantity_unit,unit_cost_cents) VALUES(?,?,?,?,?,?)');
            foreach($clean as $line){$item=$line['item'];$i->execute([$id,(int)$item['id'],$item['ingredient_name'],$line['qty'],$item['quantity_unit'],(int)$item['unit_cost_cents']]);}
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function markOrdered(int $poId): void
    {
        $s=$this->db->prepare("UPDATE purchase_orders SET status='ordered',ordered_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='draft'");$s->execute([$poId]);
        if($s->rowCount()!==1) throw new \InvalidArgumentException('Only draft purchase orders can be ordered.');
    }

    public function cancel(int $poId): void
    {
        $po=$this->purchaseOrder($poId);if(!in_array($po['status'],['draft','ordered'],true))throw new \InvalidArgumentException('Only draft or unreceived ordered POs can be cancelled.');
        foreach($po['items'] as $item)if((float)$item['quantity_received']>0)throw new \InvalidArgumentException('A purchase order with receipts cannot be cancelled.');
        $s=$this->db->prepare("UPDATE purchase_orders SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=?");$s->execute([$poId]);
    }

    public function receive(int $poItemId,array $data,int $adminId): int
    {
        $item=$this->purchaseOrderItem($poItemId);$po=$this->purchaseOrder((int)$item['purchase_order_id']);
        if(!in_array($po['status'],['ordered','partially_received'],true)) throw new \InvalidArgumentException('Purchase order must be ordered before receiving.');
        $qty=(float)($data['quantity_received']??0);if($qty<=0)throw new \InvalidArgumentException('Received quantity must be greater than zero.');
        $remaining=(float)$item['quantity_ordered']-(float)$item['quantity_received'];
        if($qty>$remaining+0.000001)throw new \InvalidArgumentException('Receipt exceeds the remaining purchase-order quantity.');
        $received=trim((string)($data['received_at']??''));if($received===''||strtotime($received)===false)throw new \InvalidArgumentException('Received date/time is required.');

        $this->db->beginTransaction();
        try{
            $supplier=$this->supplier((int)$po['supplier_id']);
            $lotId=(new IngredientTraceabilityService($this->db))->createLot([
                'ingredient_name'=>$item['ingredient_name'],
                'supplier_name'=>$supplier['name'],
                'supplier_lot_code'=>(string)($data['supplier_lot_code']??''),
                'received_at'=>$received,
                'best_by_date'=>(string)($data['best_by_date']??''),
                'quantity_received'=>$qty,
                'quantity_unit'=>$item['quantity_unit'],
                'notes'=>'Received against '.$po['po_number'].'. '.trim((string)($data['notes']??'')),
            ],$adminId);
            $u=$this->db->prepare('UPDATE purchase_order_items SET quantity_received=quantity_received+? WHERE id=? AND quantity_received+?<=quantity_ordered+0.000001');
            $u->execute([$qty,$poItemId,$qty]);if($u->rowCount()!==1)throw new \RuntimeException('Purchase order quantity changed during receiving.');
            $r=$this->db->prepare('INSERT INTO purchase_receipts(purchase_order_item_id,ingredient_lot_id,quantity_received,received_at,received_by) VALUES(?,?,?,?,?)');
            $r->execute([$poItemId,$lotId,$qty,$received,$adminId]);
            $this->refreshPurchaseOrderStatus((int)$po['id']);
            $this->db->commit();return $lotId;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function purchaseOrders(?string $status=null): array
    {
        $where='';$params=[];if($status!==null&&$status!==''){if(!in_array($status,['draft','ordered','partially_received','received','cancelled'],true))throw new \InvalidArgumentException('Invalid purchase order status.');$where=' WHERE p.status=?';$params[]=$status;}
        $s=$this->db->prepare("SELECT p.*,s.name supplier_name,(SELECT COALESCE(SUM(i.quantity_ordered*i.unit_cost_cents),0) FROM purchase_order_items i WHERE i.purchase_order_id=p.id) total_cents FROM purchase_orders p JOIN suppliers s ON s.id=p.supplier_id{$where} ORDER BY CASE p.status WHEN 'ordered' THEN 0 WHEN 'partially_received' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END,p.expected_at,p.id DESC");
        $s->execute($params);return $s->fetchAll();
    }

    public function purchaseOrder(int $id): array
    {
        $s=$this->db->prepare('SELECT p.*,s.name supplier_name,s.email supplier_email FROM purchase_orders p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=?');$s->execute([$id]);$po=$s->fetch();
        if(!$po)throw new \InvalidArgumentException('Purchase order not found.');
        $i=$this->db->prepare('SELECT i.*,(i.quantity_ordered-i.quantity_received) remaining_quantity FROM purchase_order_items i WHERE i.purchase_order_id=? ORDER BY i.id');$i->execute([$id]);$po['items']=$i->fetchAll();
        $r=$this->db->prepare('SELECT r.*,i.ingredient_name,l.supplier_lot_code FROM purchase_receipts r JOIN purchase_order_items i ON i.id=r.purchase_order_item_id JOIN ingredient_lots l ON l.id=r.ingredient_lot_id WHERE i.purchase_order_id=? ORDER BY r.id DESC');$r->execute([$id]);$po['receipts']=$r->fetchAll();
        return $po;
    }

    public function summary(): array
    {
        $out=['active_suppliers'=>(int)$this->db->query('SELECT COUNT(*) FROM suppliers WHERE active=1')->fetchColumn(),'open_po'=>0,'overdue_po'=>0,'open_value_cents'=>0];
        $out['open_po']=(int)$this->db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_received')")->fetchColumn();
        $out['overdue_po']=(int)$this->db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_received') AND expected_at IS NOT NULL AND expected_at<date('now')")->fetchColumn();
        $out['open_value_cents']=(int)$this->db->query("SELECT COALESCE(SUM((i.quantity_ordered-i.quantity_received)*i.unit_cost_cents),0) FROM purchase_order_items i JOIN purchase_orders p ON p.id=i.purchase_order_id WHERE p.status IN ('ordered','partially_received')")->fetchColumn();
        return $out;
    }

    private function item(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM supplier_items WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new \InvalidArgumentException('Supplier item not found.');return $row;
    }

    private function purchaseOrderItem(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM purchase_order_items WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new \InvalidArgumentException('Purchase order item not found.');return $row;
    }

    private function refreshPurchaseOrderStatus(int $poId): void
    {
        $s=$this->db->prepare('SELECT COUNT(*) total,SUM(CASE WHEN quantity_received+0.000001>=quantity_ordered THEN 1 ELSE 0 END) complete,SUM(CASE WHEN quantity_received>0 THEN 1 ELSE 0 END) touched FROM purchase_order_items WHERE purchase_order_id=?');
        $s->execute([$poId]);$x=$s->fetch();$status=((int)$x['complete']===(int)$x['total'])?'received':((int)$x['touched']>0?'partially_received':'ordered');
        $u=$this->db->prepare('UPDATE purchase_orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([$status,$poId]);
    }

    private function exists(string $table,int $id): bool
    {
        $s=$this->db->prepare('SELECT 1 FROM '.$table.' WHERE id=?');$s->execute([$id]);return (bool)$s->fetchColumn();
    }
}
