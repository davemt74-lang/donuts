<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class AdminService
{
    private const ORDER_TRANSITIONS = [
        'pending_payment'=>['paid','payment_failed','cancelled'],
        'payment_failed'=>['pending_payment','cancelled'],
        'paid'=>['preparing','refunded','cancelled'],
        'preparing'=>['ready','shipped','refunded','cancelled'],
        'ready'=>['completed','cancelled'],
        'shipped'=>['delivered','refunded'],
        'delivered'=>['completed','refunded'],
        'completed'=>['refunded'],
        'cancelled'=>[],
        'refunded'=>[],
    ];

    public function __construct(private readonly PDO $db) {}

    public function orders(int $limit=100,?string $status=null): array
    {
        $limit=max(1,min(500,$limit));
        if($status!==null && $status!==''){
            $allowed=array_keys(self::ORDER_TRANSITIONS);
            if(!in_array($status,$allowed,true)) throw new \InvalidArgumentException('Invalid order status filter.');
            $s=$this->db->prepare("SELECT * FROM orders WHERE status=? ORDER BY id DESC LIMIT {$limit}");
            $s->execute([$status]);return $s->fetchAll();
        }
        return $this->db->query("SELECT * FROM orders ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    public function order(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM orders WHERE id=?');$s->execute([$id]);$order=$s->fetch();
        if(!$order)return null;
        $i=$this->db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$i->execute([$id]);$order['items']=$i->fetchAll();
        $e=$this->db->prepare('SELECT * FROM order_events WHERE order_id=? ORDER BY id DESC');$e->execute([$id]);$order['events']=$e->fetchAll();
        try{$p=$this->db->prepare('SELECT stripe_payment_intent_id FROM order_payment_details WHERE order_id=?');$p->execute([$id]);$order['stripe_payment_intent_id']=$p->fetchColumn()?:null;}catch(\Throwable){$order['stripe_payment_intent_id']=null;}
        try{$r=$this->db->prepare('SELECT * FROM order_payment_reconciliation WHERE order_id=?');$r->execute([$id]);$order['payment_reconciliation']=$r->fetch()?:null;}catch(\Throwable){$order['payment_reconciliation']=null;}
        return $order;
    }

    public function transitionOrder(int $orderId,string $to,string $note=''): void
    {
        $order=$this->order($orderId);
        if(!$order)throw new \InvalidArgumentException('Order not found.');
        $from=(string)$order['status'];
        if(!in_array($to,self::ORDER_TRANSITIONS[$from]??[],true))throw new \InvalidArgumentException("Cannot move order from {$from} to {$to}.");
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('UPDATE orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=?');
            $s->execute([$to,$orderId,$from]);
            if($s->rowCount()!==1)throw new \RuntimeException('Order changed before update.');
            $e=$this->db->prepare('INSERT INTO order_events(order_id,event_type,note) VALUES(?,?,?)');
            $e->execute([$orderId,'status_changed',trim($note)!==''?$note:"{$from} → {$to}"]);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function setPickupZip(string $zip,bool $active): void
    {
        $zip=substr(trim($zip),0,5);
        if(!preg_match('/^\d{5}$/',$zip))throw new \InvalidArgumentException('Enter a five-digit ZIP code.');
        if($active){
            $s=$this->db->prepare('INSERT INTO pickup_zip_codes(postal_code,active) VALUES(?,1) ON CONFLICT(postal_code) DO UPDATE SET active=1');$s->execute([$zip]);
        }else{
            $s=$this->db->prepare('UPDATE pickup_zip_codes SET active=0 WHERE postal_code=?');$s->execute([$zip]);
        }
    }

    public function pickupZips(): array
    {
        return $this->db->query('SELECT * FROM pickup_zip_codes ORDER BY postal_code')->fetchAll();
    }

    public function discounts(): array
    {
        return $this->db->query('SELECT * FROM discount_rules ORDER BY active DESC,sort_order,id')->fetchAll();
    }
}
