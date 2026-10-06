<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class DisputeService
{
    private const OPEN_STATUSES=['warning_needs_response','warning_under_review','needs_response','under_review'];
    private const BLOCKING_STATUSES=['warning_needs_response','warning_under_review','needs_response','under_review','lost'];

    public function __construct(private readonly PDO $db) {}

    public function attachGiftPurchasePaymentIntent(int $purchaseId,string $paymentIntentId): void
    {
        $paymentIntentId=trim($paymentIntentId);
        if($paymentIntentId==='') return;
        $s=$this->db->prepare('UPDATE gift_card_purchases SET stripe_payment_intent_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([$paymentIntentId,$purchaseId]);
    }

    public function recordStripeEvent(array $dispute,string $eventType): array
    {
        $id=trim((string)($dispute['id']??''));
        $paymentIntent=trim((string)($dispute['payment_intent']??''));
        $charge=trim((string)($dispute['charge']??''));
        $status=trim((string)($dispute['status']??''));
        $currency=strtolower(trim((string)($dispute['currency']??'usd')));
        if($id==='' || $status==='' || (int)($dispute['amount']??-1)<0) throw new \InvalidArgumentException('Invalid Stripe dispute payload.');

        $orderId=null;$giftPurchaseId=null;
        if($paymentIntent!==''){
            $q=$this->db->prepare('SELECT order_id FROM order_payment_details WHERE stripe_payment_intent_id=?');$q->execute([$paymentIntent]);$v=$q->fetchColumn();
            if($v!==false)$orderId=(int)$v;
            try{
                $g=$this->db->prepare('SELECT id FROM gift_card_purchases WHERE stripe_payment_intent_id=?');$g->execute([$paymentIntent]);$gv=$g->fetchColumn();
                if($gv!==false)$giftPurchaseId=(int)$gv;
            }catch(\Throwable){}
        }

        $due=null;$dueUnix=(int)($dispute['evidence_details']['due_by']??0);
        if($dueUnix>0)$due=gmdate('Y-m-d H:i:s',$dueUnix);
        $refundable=!empty($dispute['is_charge_refundable'])?1:0;
        $livemode=!empty($dispute['livemode'])?1:0;

        $s=$this->db->prepare('INSERT INTO stripe_disputes(stripe_dispute_id,order_id,gift_card_purchase_id,stripe_payment_intent_id,stripe_charge_id,amount_cents,currency,reason,status,evidence_due_at,is_charge_refundable,livemode,last_event_type) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(stripe_dispute_id) DO UPDATE SET order_id=COALESCE(excluded.order_id,stripe_disputes.order_id),gift_card_purchase_id=COALESCE(excluded.gift_card_purchase_id,stripe_disputes.gift_card_purchase_id),stripe_payment_intent_id=excluded.stripe_payment_intent_id,stripe_charge_id=excluded.stripe_charge_id,amount_cents=excluded.amount_cents,currency=excluded.currency,reason=excluded.reason,status=excluded.status,evidence_due_at=excluded.evidence_due_at,is_charge_refundable=excluded.is_charge_refundable,livemode=excluded.livemode,last_event_type=excluded.last_event_type,updated_at=CURRENT_TIMESTAMP');
        $s->execute([$id,$orderId,$giftPurchaseId,$paymentIntent,$charge,(int)$dispute['amount'],$currency,mb_substr((string)($dispute['reason']??''),0,80),$status,$due,$refundable,$livemode,mb_substr($eventType,0,80)]);

        if($orderId)$this->recordOrderEvent($orderId,$id,$status,$eventType);
        if($giftPurchaseId)$this->protectGiftCardPurchase($giftPurchaseId,$status);
        return $this->findByStripeId($id);
    }

    public function findByStripeId(string $stripeId): array
    {
        $s=$this->db->prepare('SELECT d.*,o.order_number,gp.recipient_email gift_recipient_email FROM stripe_disputes d LEFT JOIN orders o ON o.id=d.order_id LEFT JOIN gift_card_purchases gp ON gp.id=d.gift_card_purchase_id WHERE d.stripe_dispute_id=?');
        $s->execute([$stripeId]);$row=$s->fetch();if(!$row)throw new \InvalidArgumentException('Dispute not found.');return $row;
    }

    public function recent(?string $status=null,int $limit=200): array
    {
        $limit=max(1,min(500,$limit));
        $base='SELECT d.*,o.order_number,gp.recipient_email gift_recipient_email FROM stripe_disputes d LEFT JOIN orders o ON o.id=d.order_id LEFT JOIN gift_card_purchases gp ON gp.id=d.gift_card_purchase_id';
        if($status!==null && $status!==''){
            $s=$this->db->prepare($base.' WHERE d.status=? ORDER BY d.updated_at DESC,d.id DESC LIMIT '.$limit);$s->execute([$status]);return $s->fetchAll();
        }
        return $this->db->query($base.' ORDER BY CASE WHEN d.status IN (\''.implode("','",self::OPEN_STATUSES)."') THEN 0 WHEN d.status='lost' THEN 1 ELSE 2 END,d.updated_at DESC,d.id DESC LIMIT ".$limit)->fetchAll();
    }

    public function stats(): array
    {
        $out=['open'=>0,'lost'=>0,'won'=>0,'total'=>0,'open_amount_cents'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count,COALESCE(SUM(amount_cents),0) amount_cents FROM stripe_disputes GROUP BY status')->fetchAll() as $row){
            $count=(int)$row['count'];$out['total']+=$count;
            if(in_array((string)$row['status'],self::OPEN_STATUSES,true)){$out['open']+=$count;$out['open_amount_cents']+=(int)$row['amount_cents'];}
            elseif($row['status']==='lost')$out['lost']+=$count;
            elseif(in_array((string)$row['status'],['won','warning_closed'],true))$out['won']+=$count;
        }
        return $out;
    }

    public function activeForOrder(int $orderId): array
    {
        $marks=implode(',',array_fill(0,count(self::BLOCKING_STATUSES),'?'));
        $s=$this->db->prepare("SELECT * FROM stripe_disputes WHERE order_id=? AND status IN ({$marks}) ORDER BY updated_at DESC");
        $s->execute([$orderId,...self::BLOCKING_STATUSES]);return $s->fetchAll();
    }

    public function hasBlockingDispute(int $orderId): bool
    {
        try{return count($this->activeForOrder($orderId))>0;}catch(\Throwable){return false;}
    }

    public function isOpenStatus(string $status): bool
    {
        return in_array($status,self::OPEN_STATUSES,true);
    }

    private function recordOrderEvent(int $orderId,string $disputeId,string $status,string $eventType): void
    {
        $s=$this->db->prepare('INSERT INTO order_events(order_id,event_type,note,payload) VALUES(?,?,?,?)');
        $s->execute([$orderId,'stripe_dispute','Stripe dispute '.$status.'.',json_encode(['dispute_id'=>$disputeId,'status'=>$status,'event_type'=>$eventType],JSON_THROW_ON_ERROR)]);
    }

    private function protectGiftCardPurchase(int $purchaseId,string $status): void
    {
        $s=$this->db->prepare('SELECT gift_card_id FROM gift_card_purchases WHERE id=?');$s->execute([$purchaseId]);$cardId=$s->fetchColumn();
        if($cardId===false || !$cardId)return;
        if(in_array($status,self::OPEN_STATUSES,true) || $status==='lost'){
            $u=$this->db->prepare("UPDATE gift_cards SET status='disabled',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'");$u->execute([(int)$cardId]);
        }elseif(in_array($status,['won','warning_closed'],true)){
            $u=$this->db->prepare("UPDATE gift_cards SET status=CASE WHEN balance_cents>0 THEN 'active' ELSE 'depleted' END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='disabled'");$u->execute([(int)$cardId]);
        }
    }
}
