<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FulfillmentWaveService
{
    public function __construct(private readonly PDO $db) {}

    public function create(array $orderIds,string $type,string $operator,string $notes,int $adminId): int
    {
        $ids=$this->ids($orderIds);
        if(!in_array($type,['shipping','pickup'],true)) throw new \InvalidArgumentException('Invalid fulfillment wave type.');
        if(count($ids)>100) throw new \InvalidArgumentException('A fulfillment wave is limited to 100 orders.');

        $orders=$this->ordersByIds($ids);
        if(count($orders)!==count($ids)) throw new \InvalidArgumentException('One or more selected orders were not found.');
        foreach($orders as $order){
            if($order['status']!=='preparing') throw new \InvalidArgumentException('All wave orders must be Preparing.');
            if($order['fulfillment_type']!==$type) throw new \InvalidArgumentException('All wave orders must use the selected fulfillment type.');
            $this->assertSafe((int)$order['id']);
            if($this->hasActiveWave((int)$order['id'])) throw new \InvalidArgumentException('Order '.$order['order_number'].' is already in an active fulfillment wave.');
        }

        $number='WAVE-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        $this->db->beginTransaction();
        try{
            $w=$this->db->prepare("INSERT INTO fulfillment_waves(wave_number,fulfillment_type,status,operator_name,notes,created_by) VALUES(?,?,'planned',?,?,?)");
            $w->execute([$number,$type,mb_substr(trim($operator),0,190),mb_substr(trim($notes),0,2000),$adminId]);
            $waveId=(int)$this->db->lastInsertId();
            $i=$this->db->prepare('INSERT INTO fulfillment_wave_orders(wave_id,order_id,active) VALUES(?,?,1)');
            foreach($ids as $id)$i->execute([$waveId,$id]);
            $this->db->commit();return $waveId;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function start(int $waveId): void
    {
        $wave=$this->wave($waveId);
        if($wave['status']!=='planned') throw new \InvalidArgumentException('Only planned waves can be started.');
        foreach($wave['orders'] as $order){
            if((int)$order['active']!==1) continue;
            if($order['status']!=='preparing') throw new \InvalidArgumentException('All wave orders must still be Preparing.');
            $this->assertSafe((int)$order['id']);
        }
        $s=$this->db->prepare("UPDATE fulfillment_waves SET status='in_progress',started_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='planned'");
        $s->execute([$waveId]);if($s->rowCount()!==1) throw new \RuntimeException('Fulfillment wave changed before start.');
    }

    public function setPacked(int $waveId,int $orderId,bool $packed,int $adminId): void
    {
        $wave=$this->wave($waveId);
        if($wave['status']!=='in_progress') throw new \InvalidArgumentException('Packing can only be changed while a wave is in progress.');
        $member=null;foreach($wave['orders'] as $row)if((int)$row['id']===$orderId && (int)$row['active']===1){$member=$row;break;}
        if(!$member) throw new \InvalidArgumentException('Order is not active in this wave.');
        if($member['status']!=='preparing') throw new \InvalidArgumentException('Only Preparing orders can be packed in a wave.');
        if($packed)$this->assertSafe($orderId);
        $s=$this->db->prepare('UPDATE fulfillment_wave_orders SET packed_at=?,packed_by=? WHERE wave_id=? AND order_id=? AND active=1');
        $s->execute([$packed?gmdate('Y-m-d H:i:s'):null,$packed?$adminId:null,$waveId,$orderId]);
        if($s->rowCount()!==1) throw new \RuntimeException('Wave order changed before packing update.');
    }

    public function complete(int $waveId): array
    {
        $wave=$this->wave($waveId);
        if($wave['status']!=='in_progress') throw new \InvalidArgumentException('Only in-progress waves can be completed.');
        if(!$wave['orders']) throw new \InvalidArgumentException('Fulfillment wave has no active orders.');
        foreach($wave['orders'] as $order){
            if((int)$order['active']!==1) continue;
            if(empty($order['packed_at'])) throw new \InvalidArgumentException('Every order must be marked packed before completing the wave.');
            if($order['status']!=='preparing') throw new \InvalidArgumentException('All wave orders must still be Preparing.');
            $this->assertSafe((int)$order['id']);
            try{
                if((new DisputeService($this->db))->hasBlockingDispute((int)$order['id'])) throw new \RuntimeException('An order has an open Stripe dispute and cannot be made Ready.');
            }catch(\PDOException $e){
                if(!str_contains(strtolower($e->getMessage()),'stripe_disputes')) throw $e;
            }
        }

        $ids=array_map(fn($o)=>(int)$o['id'],array_values(array_filter($wave['orders'],fn($o)=>(int)$o['active']===1)));
        $this->db->beginTransaction();
        try{
            $u=$this->db->prepare("UPDATE orders SET status='ready',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='preparing'");
            $e=$this->db->prepare("INSERT INTO order_events(order_id,event_type,note) VALUES(?,'status_changed',?)");
            foreach($ids as $id){
                $u->execute([$id]);if($u->rowCount()!==1) throw new \RuntimeException('An order changed while the wave was completing.');
                $e->execute([$id,'preparing → ready (fulfillment wave)']);
            }
            $m=$this->db->prepare('UPDATE fulfillment_wave_orders SET active=0 WHERE wave_id=? AND active=1');$m->execute([$waveId]);
            $w=$this->db->prepare("UPDATE fulfillment_waves SET status='completed',completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='in_progress'");
            $w->execute([$waveId]);if($w->rowCount()!==1) throw new \RuntimeException('Fulfillment wave changed before completion.');
            $this->db->commit();return $ids;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function cancel(int $waveId): void
    {
        $wave=$this->wave($waveId);
        if(!in_array($wave['status'],['planned','in_progress'],true)) throw new \InvalidArgumentException('Only open waves can be cancelled.');
        $this->db->beginTransaction();
        try{
            $m=$this->db->prepare('UPDATE fulfillment_wave_orders SET active=0 WHERE wave_id=? AND active=1');$m->execute([$waveId]);
            $w=$this->db->prepare("UPDATE fulfillment_waves SET status='cancelled',cancelled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('planned','in_progress')");
            $w->execute([$waveId]);if($w->rowCount()!==1) throw new \RuntimeException('Fulfillment wave changed before cancellation.');
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function wave(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM fulfillment_waves WHERE id=?');$s->execute([$id]);$wave=$s->fetch();
        if(!$wave) throw new \InvalidArgumentException('Fulfillment wave not found.');
        $o=$this->db->prepare('SELECT o.*,wo.packed_at,wo.packed_by,wo.active FROM fulfillment_wave_orders wo JOIN orders o ON o.id=wo.order_id WHERE wo.wave_id=? ORDER BY o.id');
        $o->execute([$id]);$wave['orders']=$o->fetchAll();return $wave;
    }

    public function waves(?string $status=null,int $limit=100): array
    {
        $limit=max(1,min(300,$limit));$params=[];$where='';
        if($status!==null&&$status!==''){
            if(!in_array($status,['planned','in_progress','completed','cancelled'],true)) throw new \InvalidArgumentException('Invalid wave status.');
            $where=' WHERE w.status=?';$params[]=$status;
        }
        $s=$this->db->prepare("SELECT w.*,
            (SELECT COUNT(*) FROM fulfillment_wave_orders wo WHERE wo.wave_id=w.id) order_count,
            (SELECT COUNT(*) FROM fulfillment_wave_orders wo WHERE wo.wave_id=w.id AND wo.packed_at IS NOT NULL) packed_count
            FROM fulfillment_waves w{$where} ORDER BY CASE w.status WHEN 'in_progress' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END,w.id DESC LIMIT {$limit}");
        $s->execute($params);return $s->fetchAll();
    }

    public function eligible(string $type): array
    {
        if(!in_array($type,['shipping','pickup'],true)) throw new \InvalidArgumentException('Invalid fulfillment type.');
        $s=$this->db->prepare("SELECT o.* FROM orders o WHERE o.status='preparing' AND o.fulfillment_type=? AND NOT EXISTS(SELECT 1 FROM fulfillment_wave_orders wo WHERE wo.order_id=o.id AND wo.active=1) ORDER BY o.id");
        $s->execute([$type]);$rows=[];
        foreach($s->fetchAll() as $order){
            $ready=(new BatchTraceabilityService($this->db))->orderShipmentReady((int)$order['id']);
            $order['traceability_ok']=$ready['ok'];$order['traceability_reason']=$ready['reason'];$rows[]=$order;
        }
        return $rows;
    }

    public function pickSummary(int $waveId): array
    {
        $wave=$this->wave($waveId);$flavors=[];
        $trace=new BatchTraceabilityService($this->db);
        foreach($wave['orders'] as $order){
            foreach($trace->requiredFlavorQuantities((int)$order['id']) as $flavorId=>$qty)$flavors[$flavorId]=($flavors[$flavorId]??0)+$qty;
        }
        if(!$flavors)return [];
        $ids=array_keys($flavors);$marks=implode(',',array_fill(0,count($ids),'?'));
        $s=$this->db->prepare("SELECT id,name FROM flavors WHERE id IN ({$marks})");$s->execute($ids);$names=[];
        foreach($s->fetchAll() as $row)$names[(int)$row['id']]=(string)$row['name'];
        $out=[];foreach($flavors as $id=>$qty)$out[]=['flavor_id'=>$id,'name'=>$names[$id]??('Flavor '.$id),'quantity'=>$qty];
        usort($out,fn($a,$b)=>strcmp($a['name'],$b['name']));return $out;
    }

    private function hasActiveWave(int $orderId): bool
    {
        $s=$this->db->prepare('SELECT 1 FROM fulfillment_wave_orders WHERE order_id=? AND active=1');$s->execute([$orderId]);return (bool)$s->fetchColumn();
    }

    private function assertSafe(int $orderId): void
    {
        $ready=(new BatchTraceabilityService($this->db))->orderShipmentReady($orderId);
        if(!$ready['ok']) throw new \InvalidArgumentException('Order is not traceability-ready: '.$ready['reason']);
    }

    private function ids(array $ids): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($id)=>$id>0)));
        if(!$ids) throw new \InvalidArgumentException('Select at least one order.');
        return $ids;
    }

    private function ordersByIds(array $ids): array
    {
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $s=$this->db->prepare("SELECT * FROM orders WHERE id IN ({$marks}) ORDER BY id");$s->execute($ids);return $s->fetchAll();
    }
}
