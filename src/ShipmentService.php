<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ShipmentService
{
    public function __construct(private readonly PDO $db) {}

    public function forOrder(int $orderId): array
    {
        $s=$this->db->prepare('SELECT * FROM order_shipments WHERE order_id=? ORDER BY id');
        $s->execute([$orderId]);$rows=$s->fetchAll();
        $i=$this->db->prepare('SELECT si.order_item_id,si.quantity,oi.pack_size,oi.configuration_json FROM order_shipment_items si JOIN order_items oi ON oi.id=si.order_item_id WHERE si.shipment_id=? ORDER BY oi.id');
        foreach($rows as &$row){$i->execute([(int)$row['id']]);$row['items']=$i->fetchAll();}
        unset($row);return $rows;
    }

    public function shipment(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM order_shipments WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Shipment not found.');
        $i=$this->db->prepare('SELECT si.*,oi.pack_size,oi.quantity order_quantity,oi.configuration_json FROM order_shipment_items si JOIN order_items oi ON oi.id=si.order_item_id WHERE si.shipment_id=? ORDER BY oi.id');
        $i->execute([$id]);$row['items']=$i->fetchAll();return $row;
    }

    public function remainingItems(int $orderId): array
    {
        $s=$this->db->prepare("SELECT oi.id,oi.pack_size,oi.quantity,oi.configuration_json,
            oi.quantity-COALESCE(SUM(CASE WHEN os.status<>'cancelled' THEN si.quantity ELSE 0 END),0) remaining
            FROM order_items oi
            LEFT JOIN order_shipment_items si ON si.order_item_id=oi.id
            LEFT JOIN order_shipments os ON os.id=si.shipment_id
            WHERE oi.order_id=?
            GROUP BY oi.id,oi.pack_size,oi.quantity,oi.configuration_json
            ORDER BY oi.id");
        $s->execute([$orderId]);return $s->fetchAll();
    }

    public function create(int $orderId,array $data,array $quantities): array
    {
        $o=$this->db->prepare('SELECT fulfillment_type,status FROM orders WHERE id=?');$o->execute([$orderId]);$order=$o->fetch();
        if(!$order) throw new \InvalidArgumentException('Order not found.');
        if($order['fulfillment_type']!=='shipping') throw new \InvalidArgumentException('Shipments are only available for shipping orders.');
        if(in_array((string)$order['status'],['cancelled','refunded','payment_failed','pending_payment','payment_review'],true)) throw new \InvalidArgumentException('This order is not ready for shipment planning.');

        $carrier=mb_substr(trim((string)($data['carrier']??'')),0,80);
        $tracking=mb_substr(trim((string)($data['tracking_number']??'')),0,190);
        $url=trim((string)($data['tracking_url']??''));
        if($url!=='' && !filter_var($url,FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('Tracking URL must be valid.');
        if($tracking!=='' && $carrier==='') throw new \InvalidArgumentException('Carrier is required when a tracking number is provided.');

        $remaining=[];foreach($this->remainingItems($orderId) as $row)$remaining[(int)$row['id']]=(int)$row['remaining'];
        $selected=[];
        foreach($quantities as $itemId=>$qty){
            $itemId=(int)$itemId;$qty=(int)$qty;if($qty<=0)continue;
            if(!array_key_exists($itemId,$remaining) || $qty>$remaining[$itemId]) throw new \InvalidArgumentException('Shipment quantity exceeds the unshipped order quantity.');
            $selected[$itemId]=$qty;
        }
        if(!$selected) throw new \InvalidArgumentException('Select at least one order item for this shipment.');

        $this->db->beginTransaction();
        try{
            $number='SHP-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $s=$this->db->prepare("INSERT INTO order_shipments(order_id,shipment_number,carrier,tracking_number,tracking_url,status) VALUES(?,?,?,?,?,'pending')");
            $s->execute([$orderId,$number,$carrier,$tracking,$url]);$id=(int)$this->db->lastInsertId();
            $i=$this->db->prepare('INSERT INTO order_shipment_items(shipment_id,order_item_id,quantity) VALUES(?,?,?)');
            foreach($selected as $itemId=>$qty)$i->execute([$id,$itemId,$qty]);
            $e=$this->db->prepare('INSERT INTO order_events(order_id,event_type,note,payload) VALUES(?,?,?,?)');
            $e->execute([$orderId,'shipment_created','Shipment '.$number.' created.',json_encode(['shipment_id'=>$id,'shipment_number'=>$number,'items'=>$selected],JSON_THROW_ON_ERROR)]);
            $this->db->commit();return $this->shipment($id);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function markShipped(int $shipmentId): array
    {
        return $this->transition($shipmentId,'shipped');
    }

    public function markDelivered(int $shipmentId): array
    {
        return $this->transition($shipmentId,'delivered');
    }

    public function cancel(int $shipmentId): array
    {
        $shipment=$this->shipment($shipmentId);
        if($shipment['status']==='delivered') throw new \InvalidArgumentException('A delivered shipment cannot be cancelled.');
        return $this->transition($shipmentId,'cancelled');
    }

    public function allShipped(int $orderId): bool
    {
        return $this->allUnitsAtLeast($orderId,['shipped','delivered']);
    }

    public function allDelivered(int $orderId): bool
    {
        return $this->allUnitsAtLeast($orderId,['delivered']);
    }

    private function allUnitsAtLeast(int $orderId,array $shipmentStatuses): bool
    {
        $marks=implode(',',array_fill(0,count($shipmentStatuses),'?'));
        $s=$this->db->prepare("SELECT oi.id,oi.quantity,COALESCE(SUM(CASE WHEN os.status IN ({$marks}) THEN si.quantity ELSE 0 END),0) fulfilled
            FROM order_items oi
            LEFT JOIN order_shipment_items si ON si.order_item_id=oi.id
            LEFT JOIN order_shipments os ON os.id=si.shipment_id
            WHERE oi.order_id=?
            GROUP BY oi.id,oi.quantity");
        $s->execute([...$shipmentStatuses,$orderId]);$rows=$s->fetchAll();
        if(!$rows)return false;
        foreach($rows as $row)if((int)$row['fulfilled']<(int)$row['quantity'])return false;
        return true;
    }

    private function transition(int $shipmentId,string $to): array
    {
        $shipment=$this->shipment($shipmentId);$from=(string)$shipment['status'];
        $allowed=['pending'=>['shipped','cancelled'],'shipped'=>['delivered'],'delivered'=>[],'cancelled'=>[]];
        if(!in_array($to,$allowed[$from]??[],true)) throw new \InvalidArgumentException("Cannot move shipment from {$from} to {$to}.");
        $this->db->beginTransaction();
        try{
            $sets=["status=?","updated_at=CURRENT_TIMESTAMP"];$params=[$to];
            if($to==='shipped')$sets[]='shipped_at=CURRENT_TIMESTAMP';
            if($to==='delivered')$sets[]='delivered_at=CURRENT_TIMESTAMP';
            $params[]=$shipmentId;$params[]=$from;
            $u=$this->db->prepare('UPDATE order_shipments SET '.implode(',',$sets).' WHERE id=? AND status=?');$u->execute($params);
            if($u->rowCount()!==1) throw new \RuntimeException('Shipment changed before update.');
            $e=$this->db->prepare('INSERT INTO order_events(order_id,event_type,note,payload) VALUES(?,?,?,?)');
            $e->execute([(int)$shipment['order_id'],'shipment_'.$to,'Shipment '.$shipment['shipment_number'].' '.$to.'.',json_encode(['shipment_id'=>$shipmentId,'shipment_number'=>$shipment['shipment_number']],JSON_THROW_ON_ERROR)]);
            $this->syncOrderStatus((int)$shipment['order_id']);
            $this->db->commit();return $this->shipment($shipmentId);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function syncOrderStatus(int $orderId): void
    {
        $s=$this->db->prepare('SELECT status FROM orders WHERE id=?');$s->execute([$orderId]);$status=(string)$s->fetchColumn();
        if($this->allDelivered($orderId) && in_array($status,['preparing','shipped'],true)){
            $u=$this->db->prepare("UPDATE orders SET status='delivered',updated_at=CURRENT_TIMESTAMP WHERE id=?");$u->execute([$orderId]);
        }elseif($this->allShipped($orderId) && in_array($status,['paid','preparing','ready'],true)){
            $u=$this->db->prepare("UPDATE orders SET status='shipped',updated_at=CURRENT_TIMESTAMP WHERE id=?");$u->execute([$orderId]);
        }
    }
}
