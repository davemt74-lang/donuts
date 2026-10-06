<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class OrderService
{
    public function __construct(private readonly PDO $db) {}

    public function create(?int $userId,array $cart,array $checkout,array $fulfillment,?string $checkoutAttemptToken=null): array
    {
        if(empty($cart['items'])) throw new \InvalidArgumentException('Cart is empty.');
        $shipping=(int)$fulfillment['price_cents'];
        $total=(int)$cart['total_cents']+$shipping;
        $checkoutAttemptToken=trim((string)$checkoutAttemptToken);
        if($checkoutAttemptToken==='') $checkoutAttemptToken=bin2hex(random_bytes(32));
        if(strlen($checkoutAttemptToken)<32 || strlen($checkoutAttemptToken)>160 || !preg_match('/^[A-Za-z0-9_-]+$/',$checkoutAttemptToken)){
            throw new \InvalidArgumentException('Invalid checkout attempt token.');
        }
        $fingerprint=hash('sha256','checkout-attempt|'.$checkoutAttemptToken);
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
            if(!empty($checkout['terms_accepted'])){
                $content=new ContentService($this->db);
                $consent=$this->db->prepare('INSERT INTO order_consents(order_id,terms_accepted,terms_version,privacy_version,refund_policy_version) VALUES(?,?,?,?,?)');
                $consent->execute([$orderId,1,$content->get('terms_version','unknown'),$content->get('privacy_version','unknown'),$content->get('refund_policy_version','unknown')]);
            }
            $this->event($orderId,'created','Order created from validated checkout.');
            $this->db->commit();
            return $this->find($orderId);
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function preparePaymentAttempt(int $orderId): array
    {
        $order=$this->find($orderId);
        if($order['status']==='paid') throw new \RuntimeException('Order is already paid.');
        if(!in_array($order['status'],['pending_payment','payment_failed'],true)) throw new \RuntimeException('Order is not eligible for payment.');
        if($order['status']==='payment_failed'){
            $s=$this->db->prepare("UPDATE orders SET status='pending_payment',stripe_checkout_session_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='payment_failed'");
            $s->execute([$orderId]);
            if($s->rowCount()!==1) throw new \RuntimeException('Order changed before payment retry.');
            $this->event($orderId,'payment_retry_started','Customer started another payment attempt.');
        }
        return $this->find($orderId);
    }

    public function markPaymentInitializationFailed(int $orderId,string $reason=''): void
    {
        $s=$this->db->prepare("UPDATE orders SET status='payment_failed',stripe_checkout_session_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending_payment'");
        $s->execute([$orderId]);
        if($s->rowCount()) $this->event($orderId,'payment_failed',$reason!==''?$reason:'Payment checkout initialization failed.');
    }

    public function attachStripeSession(int $orderId,string $sessionId): void
    {
        $s=$this->db->prepare("UPDATE orders SET stripe_checkout_session_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending_payment'");
        $s->execute([$sessionId,$orderId]);
        if($s->rowCount()!==1) throw new \RuntimeException('Order can no longer accept a payment session.');
        $this->event($orderId,'payment_session_created','Stripe Checkout session created.',['session_id'=>$sessionId]);
    }

    public function attachStripePaymentIntent(string $sessionId,string $paymentIntentId): void
    {
        if($paymentIntentId==='') return;
        $q=$this->db->prepare('SELECT id FROM orders WHERE stripe_checkout_session_id=?');$q->execute([$sessionId]);$orderId=$q->fetchColumn();
        if($orderId===false) return;
        $s=$this->db->prepare('INSERT INTO order_payment_details(order_id,stripe_payment_intent_id) VALUES(?,?) ON CONFLICT(order_id) DO UPDATE SET stripe_payment_intent_id=excluded.stripe_payment_intent_id,updated_at=CURRENT_TIMESTAMP');
        $s->execute([(int)$orderId,$paymentIntentId]);
    }

    public function markPaidByStripeSession(string $sessionId,int $stripeSubtotalCents,int $amountTotal,int $taxCents,string $currency='usd'): bool
    {
        $currency=strtolower(trim($currency));
        $s=$this->db->prepare("SELECT * FROM orders WHERE stripe_checkout_session_id=?");
        $s->execute([$sessionId]);$order=$s->fetch();
        if(!$order) return false;
        if($order['status']==='paid') return true;
        if($order['status']==='payment_review') return false;
        if($order['status']!=='pending_payment') throw new \RuntimeException('Unexpected order payment state.');

        $reconciliation=(new PaymentReconciliationService($this->db))->reconcile((int)$order['id'],$sessionId,[
            'amount_subtotal'=>$stripeSubtotalCents,
            'amount_total'=>$amountTotal,
            'currency'=>$currency,
            'total_details'=>['amount_tax'=>$taxCents],
        ]);
        if(!$reconciliation['matched']){
            $this->markPaymentReviewByStripeSession($sessionId,'Stripe total or currency reconciliation failed.');
            return false;
        }

        $this->db->beginTransaction();
        try{
            $u=$this->db->prepare("UPDATE orders SET status='paid',tax_cents=?,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending_payment'");
            $u->execute([$taxCents,$amountTotal,(int)$order['id']]);
            if($u->rowCount()!==1) throw new \RuntimeException('Order changed before payment reconciliation completed.');
            $this->event((int)$order['id'],'paid','Stripe confirmed and reconciled payment.',['amount_total'=>$amountTotal,'tax_cents'=>$taxCents,'currency'=>$currency]);
            $this->db->commit();
            return true;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function markPaidByExternalTender(string $sessionId,int $stripeChargedCents,int $externalTenderCents,int $calculatedTaxCents,string $currency='usd'): bool
    {
        $s=$this->db->prepare("SELECT * FROM orders WHERE stripe_checkout_session_id=?");$s->execute([$sessionId]);$order=$s->fetch();
        if(!$order)return false;
        if($order['status']==='paid')return true;
        if($order['status']==='payment_review')return false;
        if($order['status']!=='pending_payment')throw new \RuntimeException('Unexpected order payment state.');

        $rec=(new PaymentReconciliationService($this->db))->reconcileExternalTender((int)$order['id'],$sessionId,$stripeChargedCents,$externalTenderCents,$calculatedTaxCents,$currency);
        if(!$rec['matched']){
            $this->markPaymentReviewByStripeSession($sessionId,'Mixed tender reconciliation failed.');
            return false;
        }
        $gross=(int)$order['total_cents']+$calculatedTaxCents;
        $this->db->beginTransaction();
        try{
            $u=$this->db->prepare("UPDATE orders SET status='paid',tax_cents=?,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending_payment'");
            $u->execute([$calculatedTaxCents,$gross,(int)$order['id']]);
            if($u->rowCount()!==1) throw new \RuntimeException('Order changed before mixed tender settlement.');
            $this->event((int)$order['id'],'paid','Gift card and Stripe tender reconciled.',['stripe_cents'=>$stripeChargedCents,'gift_card_cents'=>$externalTenderCents,'tax_cents'=>$calculatedTaxCents,'currency'=>$currency]);
            $this->db->commit();return true;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function markSettlementReview(int $orderId,string $reason): void
    {
        $s=$this->db->prepare("UPDATE orders SET status='payment_review',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('pending_payment','paid')");
        $s->execute([$orderId]);
        if($s->rowCount()) $this->event($orderId,'payment_review',$reason);
    }

    public function markPaymentReviewByStripeSession(string $sessionId,string $reason=''): void
    {
        $s=$this->db->prepare("UPDATE orders SET status='payment_review',updated_at=CURRENT_TIMESTAMP WHERE stripe_checkout_session_id=? AND status='pending_payment'");
        $s->execute([$sessionId]);
        if($s->rowCount()){
            $q=$this->db->prepare('SELECT id FROM orders WHERE stripe_checkout_session_id=?');$q->execute([$sessionId]);$orderId=(int)$q->fetchColumn();
            $this->event($orderId,'payment_review',$reason!==''?$reason:'Payment requires manual review.');
        }
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

    public function findByNumber(string $orderNumber): ?array
    {
        $s=$this->db->prepare('SELECT id FROM orders WHERE order_number=?');$s->execute([trim($orderNumber)]);
        $id=$s->fetchColumn();return $id===false?null:$this->find((int)$id);
    }

    public function findByStripeSession(string $sessionId): ?array
    {
        $s=$this->db->prepare('SELECT id FROM orders WHERE stripe_checkout_session_id=?');$s->execute([$sessionId]);
        $id=$s->fetchColumn();return $id===false?null:$this->find((int)$id);
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
        try{$p=$this->db->prepare('SELECT stripe_payment_intent_id FROM order_payment_details WHERE order_id=?');$p->execute([$id]);$order['stripe_payment_intent_id']=$p->fetchColumn()?:null;}catch(\Throwable){$order['stripe_payment_intent_id']=null;}
        try{$r=$this->db->prepare('SELECT * FROM order_payment_reconciliation WHERE order_id=?');$r->execute([$id]);$order['payment_reconciliation']=$r->fetch()?:null;}catch(\Throwable){$order['payment_reconciliation']=null;}
        try{$f=$this->db->prepare('SELECT * FROM order_fulfillment_details WHERE order_id=?');$f->execute([$id]);$order['fulfillment_details']=$f->fetch()?:null;}catch(\Throwable){$order['fulfillment_details']=null;}
        return $order;
    }

    private function event(int $orderId,string $type,string $note='',array $payload=[]): void
    {
        $s=$this->db->prepare('INSERT INTO order_events(order_id,event_type,note,payload) VALUES(?,?,?,?)');
        $s->execute([$orderId,$type,$note,$payload?json_encode($payload,JSON_THROW_ON_ERROR):'']);
    }
}
