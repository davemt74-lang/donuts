<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class RefundService
{
    public function __construct(private readonly PDO $db) {}

    public function refundableCents(int $orderId): int
    {
        $s=$this->db->prepare("SELECT total_cents FROM orders WHERE id=? AND status IN ('payment_review','paid','preparing','ready','shipped','delivered','completed')");
        $s->execute([$orderId]);$total=$s->fetchColumn();
        if($total===false) return 0;
        $r=$this->db->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM refund_records WHERE order_id=? AND status IN ('pending','review','succeeded')");
        $r->execute([$orderId]);
        return max(0,(int)$total-(int)$r->fetchColumn());
    }

    public function create(int $orderId,int $amountCents,string $reason,?int $adminId): int
    {
        $available=$this->refundableCents($orderId);
        if($amountCents<=0 || $amountCents>$available) throw new \InvalidArgumentException('Refund amount exceeds the refundable balance.');

        $giftRemaining=0;
        try{
            $g=$this->db->prepare("SELECT redeemed_cents,refunded_cents FROM order_gift_card_applications WHERE order_id=? AND status='redeemed'");
            $g->execute([$orderId]);$application=$g->fetch();
            if($application){
                $p=$this->db->prepare("SELECT COALESCE(SUM(gift_card_amount_cents),0) FROM refund_records WHERE order_id=? AND status IN ('pending','review')");
                $p->execute([$orderId]);
                $giftRemaining=max(0,(int)$application['redeemed_cents']-(int)$application['refunded_cents']-(int)$p->fetchColumn());
            }
        }catch(\Throwable){$giftRemaining=0;}

        $giftPart=min($amountCents,$giftRemaining);$stripePart=$amountCents-$giftPart;
        $provider=$giftPart>0&&$stripePart>0?'mixed':($giftPart>0?'gift_card':'stripe');
        if($this->giftCardRefundColumnsAvailable()){
            $s=$this->db->prepare("INSERT INTO refund_records(order_id,amount_cents,reason,provider,requested_by_admin_id,status,gift_card_amount_cents,stripe_amount_cents) VALUES(?,?,?,?,?,'pending',?,?)");
            $s->execute([$orderId,$amountCents,mb_substr(trim($reason),0,255),$provider,$adminId,$giftPart,$stripePart]);
        }else{
            $s=$this->db->prepare("INSERT INTO refund_records(order_id,amount_cents,reason,provider,requested_by_admin_id,status) VALUES(?,?,?,?,?,'pending')");
            $s->execute([$orderId,$amountCents,mb_substr(trim($reason),0,255),'stripe',$adminId]);
        }
        return (int)$this->db->lastInsertId();
    }

    public function refund(int $refundId): array
    {
        $s=$this->db->prepare('SELECT * FROM refund_records WHERE id=?');$s->execute([$refundId]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Refund not found.');
        return $row;
    }

    public function markSucceeded(int $refundId,?string $providerRefundId): void
    {
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM refund_records WHERE id=?");$s->execute([$refundId]);$refund=$s->fetch();
            if(!$refund) throw new \InvalidArgumentException('Refund not found.');
            if($refund['status']==='succeeded'){$this->db->rollBack();return;}
            $u=$this->db->prepare("UPDATE refund_records SET status='succeeded',provider_refund_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
            $u->execute([$providerRefundId,$refundId]);
            if($u->rowCount()!==1) throw new \RuntimeException('Refund changed before completion.');
            if($this->refundableCents((int)$refund['order_id'])===0){
                $o=$this->db->prepare("UPDATE orders SET status='refunded',updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $o->execute([(int)$refund['order_id']]);
            }
            $e=$this->db->prepare("INSERT INTO order_events(order_id,event_type,note,payload) VALUES(?,?,?,?)");
            $e->execute([(int)$refund['order_id'],'refund_succeeded','Refund completed.',json_encode(['refund_id'=>$refundId,'provider_refund_id'=>$providerRefundId],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            try{(new LoyaltyService($this->db))->applyRefund((int)$refund['order_id'],$refundId);}
            catch(\Throwable $e){
                try{ObservabilityService::captureThrowable($e,dirname(__DIR__),'loyalty_refund_adjustment_failure');}catch(\Throwable){}
            }
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function markReview(int $refundId,?string $providerRefundId,string $reason): void
    {
        $s=$this->db->prepare("UPDATE refund_records SET status='review',provider_refund_id=?,reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
        $s->execute([$providerRefundId,mb_substr($reason,0,255),$refundId]);
    }

    public function markFailed(int $refundId,string $reason): void
    {
        $s=$this->db->prepare("UPDATE refund_records SET status='failed',reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
        $s->execute([mb_substr($reason,0,255),$refundId]);
    }

    public function forOrder(int $orderId): array
    {
        $s=$this->db->prepare('SELECT * FROM refund_records WHERE order_id=? ORDER BY id DESC');$s->execute([$orderId]);return $s->fetchAll();
    }

    public function requestCancellation(int $orderId,int $userId,string $reason): int
    {
        $s=$this->db->prepare("SELECT status FROM orders WHERE id=? AND user_id=?");$s->execute([$orderId,$userId]);$status=$s->fetchColumn();
        if($status===false) throw new \InvalidArgumentException('Order not found.');
        if(!in_array($status,['pending_payment','paid','preparing'],true)) throw new \InvalidArgumentException('This order can no longer be cancelled online.');
        $q=$this->db->prepare("SELECT id FROM cancellation_requests WHERE order_id=? AND status='pending'");$q->execute([$orderId]);
        if($id=$q->fetchColumn()) return (int)$id;
        $i=$this->db->prepare("INSERT INTO cancellation_requests(order_id,user_id,reason,status) VALUES(?,?,?,'pending')");
        $i->execute([$orderId,$userId,mb_substr(trim($reason),0,500)]);return (int)$this->db->lastInsertId();
    }

    public function pendingCancellation(int $orderId): ?array
    {
        $s=$this->db->prepare("SELECT * FROM cancellation_requests WHERE order_id=? AND status='pending'");$s->execute([$orderId]);return $s->fetch()?:null;
    }

    public function resolveCancellation(int $requestId,string $status,int $adminId): void
    {
        if(!in_array($status,['approved','declined'],true)) throw new \InvalidArgumentException('Invalid cancellation resolution.');
        $s=$this->db->prepare("UPDATE cancellation_requests SET status=?,resolved_by_admin_id=?,resolved_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
        $s->execute([$status,$adminId,$requestId]);
        if($s->rowCount()!==1) throw new \RuntimeException('Cancellation request changed before resolution.');
    }

    private function giftCardRefundColumnsAvailable(): bool
    {
        try{
            foreach($this->db->query("PRAGMA table_info(refund_records)")->fetchAll() as $row){
                if(($row['name']??'')==='gift_card_amount_cents') return true;
            }
        }catch(\Throwable){}
        return false;
    }
}
