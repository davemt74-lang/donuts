<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class OrderService
{
    public function __construct(private readonly PDO $db) {}

    public function create(?int $userId,array $cart,array $checkout,array $fulfillment): array
    {
        if(empty($cart['items'])) throw new \InvalidArgumentException('Cart is empty.');
        $shipping=(int)$fulfillment['price_cents'];
        $total=(int)$cart['total_cents']+$shipping;
        $fingerprint=hash('sha256',json_encode([$userId,$cart,$checkout,$fulfillment],JSON_THROW_ON_ERROR));
        $existing=$this->db->prepare('SELECT id FROM orders WHERE checkout_fingerprint=?');
        $existing->execute([$fingerprint]);
        if($id=$existing->fetchColumn()) return $this->find((int)$id);
        $number='FD-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('INSERT INTO orders(order_number,checkout_fingerprint,user_id,email,first_name,last_name,line1,line2,city,region,postal_code,country,phone,is_gift,gift_message,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,discount_cents,shipping_cents,total_cents) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$number,$fingerprint,$userId,$checkout['email'],$checkout['first_name'],$checkout['last_name'],$checkout['line1'],$checkout['line2'],$checkout['city'],$checkout['region'],$checkout['postal_code'],$checkout['country'],$checkout['phone'],$checkout['is_gift']?1:0,$checkout['gift_message'],$fulfillment['code'],$fulfillment['name'],$fulfillment['type'],$cart['subtotal_cents'],$cart['discount_cents'],$shipping,$total]);
            $orderId=(int)$this->db->lastInsertId();

            $i=$this->db->prepare('INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)');
            foreach($cart['items'] as $line){
                $i->execute([$orderId,$line['box']['type'],(int)$line['box']['size'],(int)$line['quantity'],(int)$line['box']['total_cents'],(int)$line['line_total_cents'],json_encode($line['box'],JSON_THROW_ON_ERROR)]);
            }
            if(!empty($cart['discounts'])) (new PromotionService($this->db))->recordOrderDiscounts($orderId,$cart['discounts']);
            if(!empty($checkout['is_gift'])) (new GiftService($this->db))->persist($orderId,$checkout);
            $this->event($orderId,'created','Order created from validated checkout.');
            $this->db->commit();
            return $this->find($orderId);
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function attachStripeSession(int $orderId,string $sessionId): void
    {
        $s=$this->db->prepare("UPDATE orders SET stripe_checkout_session_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending_payment'");
        $s->execute([$sessionId,$orderId]);
        if($s->rowCount()!==1) throw new \RuntimeException('Order can no longer accept a payment session.');
        $this->event($orderId,'payment_session_created','Stripe Checkout session created.',['session_id'=>$sessionId]);
    }

    public function markPaidByStripeSession(string $sessionId,int $amountTotal,int $taxCents): void
    {
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM orders WHERE stripe_checkout_session_id=?");
            $s->execute([$sessionId]);$order=$s->fetch();
            if(!$order){$this->db->rollBack();return;}
            if($order['status']==='paid'){$this->db->rollBack();return;}
            if($order['status']!=='pending_payment') throw new \RuntimeException('Unexpected order payment state.');
            $u=$this->db->prepare("UPDATE orders SET status='paid',tax_cents=?,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $u->execute([$taxCents,$amountTotal,(int)$order['id']]);
            $this->event((int)$order['id'],'paid','Stripe confirmed payment.',['amount_total'=>$amountTotal,'tax_cents'=>$taxCents]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function markPaymentFailedByStripeSession(string $sessionId,string $reason=''): void
    {
        $s=$this->db->prepare("UPDATE orders SET status='payment_failed',updated_at=CURRENT_TIMESTAMP WHERE stripe_checkout_session_id=? AND status='pending_payment'");
        $s->execute([$sessionId]);
        if($s->rowCount()){
            $q=$this->db->prepare('SELECT id FROM orders WHERE stripe_checkout_session_id=?');$q->execute([$sessionId]);
            $this->event((int)$q->fetchColumn(),'payment_failed',$reason);
        }
    }

    public function idByStripeSession(string $sessionId): ?int
    {
        $s=$this->db->prepare('SELECT id FROM orders WHERE stripe_checkout_session_id=?');$s->execute([$sessionId]);
        $id=$s->fetchColumn();return $id===false?null:(int)$id;
    }

    public function find(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM orders WHERE id=?');$s->execute([$id]);$order=$s->fetch();
        if(!$order) throw new \RuntimeException('Order not found.');
        $i=$this->db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$i->execute([$id]);$order['items']=$i->fetchAll();
        return $order;
    }

    private function event(int $orderId,string $type,string $note='',array $payload=[]): void
    {
        $s=$this->db->prepare('INSERT INTO order_events(order_id,event_type,note,payload) VALUES(?,?,?,?)');
        $s->execute([$orderId,$type,$note,$payload?json_encode($payload,JSON_THROW_ON_ERROR):'']);
    }
}
