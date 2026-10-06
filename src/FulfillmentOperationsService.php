<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FulfillmentOperationsService
{
    public function __construct(private readonly PDO $db) {}

    public function batchTransition(array $orderIds,string $to): int
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$orderIds),fn($id)=>$id>0)));
        if(!$ids) throw new \InvalidArgumentException('Select at least one order.');
        if(count($ids)>100) throw new \InvalidArgumentException('Batch updates are limited to 100 orders.');
        $from=$to==='preparing'?'paid':($to==='ready'?'preparing':null);
        if($from===null) throw new \InvalidArgumentException('Batch updates only support preparing and ready.');

        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $s=$this->db->prepare("SELECT id,status FROM orders WHERE id IN ({$placeholders}) ORDER BY id");
        $s->execute($ids);$rows=$s->fetchAll();
        if(count($rows)!==count($ids)) throw new \InvalidArgumentException('One or more selected orders were not found.');
        foreach($rows as $row){
            if($row['status']!==$from) throw new \InvalidArgumentException('All selected orders must currently be '.$from.'.');
        }

        $this->db->beginTransaction();
        try{
            $u=$this->db->prepare('UPDATE orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=?');
            $e=$this->db->prepare('INSERT INTO order_events(order_id,event_type,note) VALUES(?,?,?)');
            foreach($ids as $id){
                $u->execute([$to,$id,$from]);
                if($u->rowCount()!==1) throw new \RuntimeException('An order changed during the batch update.');
                $e->execute([$id,'status_changed',"{$from} → {$to} (batch)"]);
            }
            $this->db->commit();return count($ids);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function shippingRows(?string $status=null): array
    {
        return $this->fulfillmentRows('shipping',$status);
    }

    public function pickupRows(?string $status=null): array
    {
        return $this->fulfillmentRows('pickup',$status);
    }

    public static function csvCell(string $value): string
    {
        $value=str_replace(["\r","\n"],' ',trim($value));
        if($value!=='' && in_array($value[0],['=','+','-','@'],true)) $value="'".$value;
        return $value;
    }

    public function packingSlip(int $orderId): array
    {
        $s=$this->db->prepare('SELECT * FROM orders WHERE id=?');$s->execute([$orderId]);$order=$s->fetch();
        if(!$order) throw new \InvalidArgumentException('Order not found.');
        $i=$this->db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$i->execute([$orderId]);$items=$i->fetchAll();
        foreach($items as &$item)$item['configuration']=json_decode((string)$item['configuration_json'],true)?:[];
        unset($item);
        $g=$this->db->prepare('SELECT * FROM order_gift_options WHERE order_id=?');$g->execute([$orderId]);
        $order['items']=$items;$order['gift_options']=$g->fetch()?:null;return $order;
    }

    private function fulfillmentRows(string $type,?string $status): array
    {
        $params=[$type];$where='fulfillment_type=?';
        if($status!==null&&$status!==''){
            $allowed=['paid','preparing','ready','shipped','delivered','completed'];
            if(!in_array($status,$allowed,true)) throw new \InvalidArgumentException('Invalid fulfillment status.');
            $where.=' AND status=?';$params[]=$status;
        }
        $s=$this->db->prepare("SELECT order_number,created_at,status,first_name,last_name,email,line1,line2,city,region,postal_code,country,phone,fulfillment_name,total_cents FROM orders WHERE {$where} ORDER BY id");
        $s->execute($params);return $s->fetchAll();
    }
}
