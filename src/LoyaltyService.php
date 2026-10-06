<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class LoyaltyService
{
    public function __construct(private readonly PDO $db) {}

    public function settings(): array
    {
        $defaults=['enabled'=>'1','points_per_dollar'=>'1','cents_per_point'=>'1','minimum_redeem_points'=>'100','maximum_redeem_percent'=>'100'];
        try{
            foreach($this->db->query('SELECT setting_key,setting_value FROM loyalty_settings')->fetchAll() as $row)$defaults[(string)$row['setting_key']]=(string)$row['setting_value'];
        }catch(\Throwable){}
        return $defaults;
    }

    public function saveSettings(array $data): void
    {
        $values=[
            'enabled'=>!empty($data['enabled'])?'1':'0',
            'points_per_dollar'=>(string)max(0,min(100,(int)($data['points_per_dollar']??1))),
            'cents_per_point'=>(string)max(1,min(100,(int)($data['cents_per_point']??1))),
            'minimum_redeem_points'=>(string)max(0,min(100000,(int)($data['minimum_redeem_points']??100))),
            'maximum_redeem_percent'=>(string)max(1,min(100,(int)($data['maximum_redeem_percent']??100))),
        ];
        $s=$this->db->prepare('INSERT INTO loyalty_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP');
        foreach($values as $key=>$value)$s->execute([$key,$value]);
    }

    public function account(int $userId): array
    {
        $this->db->prepare('INSERT OR IGNORE INTO loyalty_accounts(user_id) VALUES(?)')->execute([$userId]);
        $s=$this->db->prepare('SELECT * FROM loyalty_accounts WHERE user_id=?');$s->execute([$userId]);$row=$s->fetch();
        if(!$row) throw new \RuntimeException('Loyalty account could not be loaded.');
        $row['available_points']=max(0,(int)$row['points_balance']-(int)$row['reserved_points']);
        return $row;
    }

    public function quoteRedemption(int $userId,int $requestedPoints,int $eligibleCents): array
    {
        $settings=$this->settings();$account=$this->account($userId);
        if($settings['enabled']!=='1' || $requestedPoints<=0 || $eligibleCents<=0)return ['points'=>0,'discount_cents'=>0,'available_points'=>(int)$account['available_points']];
        $min=(int)$settings['minimum_redeem_points'];
        if($requestedPoints<$min) throw new \InvalidArgumentException('Redeem at least '.$min.' points.');
        $available=(int)$account['available_points'];
        if($requestedPoints>$available) throw new \InvalidArgumentException('You do not have that many available points.');
        $centsPerPoint=(int)$settings['cents_per_point'];
        $maxDiscount=intdiv($eligibleCents*(int)$settings['maximum_redeem_percent'],100);
        $maxPoints=intdiv($maxDiscount,$centsPerPoint);
        $points=min($requestedPoints,$maxPoints);
        if($points<$min) throw new \InvalidArgumentException('This order is too small for the requested rewards redemption.');
        return ['points'=>$points,'discount_cents'=>$points*$centsPerPoint,'available_points'=>$available];
    }

    public function reserveForOrder(int $userId,int $orderId,int $requestedPoints,int $eligibleCents): array
    {
        if($requestedPoints<=0)return $this->ensureOrderRow($userId,$orderId);
        $this->db->beginTransaction();
        try{
            $existing=$this->orderRow($orderId);
            if($existing){
                if((int)$existing['user_id']!==$userId) throw new \RuntimeException('Rewards reservation belongs to a different customer.');
                if($existing['status']==='reserved'||$existing['status']==='redeemed'){$this->db->commit();return $existing;}
                if($existing['status']==='released'){
                    if((int)$existing['reserved_points']!==$requestedPoints) throw new \RuntimeException('Released rewards reservation does not match this retry.');
                    $account=$this->account($userId);
                    if((int)$account['available_points']<$requestedPoints) throw new \InvalidArgumentException('Rewards balance changed before payment retry.');
                    $u=$this->db->prepare('UPDATE loyalty_accounts SET reserved_points=reserved_points+?,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND points_balance-reserved_points>=?');
                    $u->execute([$requestedPoints,$userId,$requestedPoints]);if($u->rowCount()!==1)throw new \RuntimeException('Rewards balance changed before reservation.');
                    $q=$this->db->prepare("UPDATE order_loyalty_redemptions SET status='reserved',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='released'");$q->execute([$orderId]);
                    $this->db->commit();return $this->orderRow($orderId)??[];
                }
            }

            $quote=$this->quoteRedemption($userId,$requestedPoints,$eligibleCents);
            $points=(int)$quote['points'];$discount=(int)$quote['discount_cents'];
            if($points<=0){$row=$this->ensureOrderRow($userId,$orderId);$this->db->commit();return $row;}
            $u=$this->db->prepare('UPDATE loyalty_accounts SET reserved_points=reserved_points+?,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND points_balance-reserved_points>=?');
            $u->execute([$points,$userId,$points]);if($u->rowCount()!==1)throw new \RuntimeException('Rewards balance changed before reservation.');
            $i=$this->db->prepare("INSERT INTO order_loyalty_redemptions(order_id,user_id,reserved_points,discount_cents,status) VALUES(?,?,?,?,'reserved')");
            $i->execute([$orderId,$userId,$points,$discount]);
            $o=$this->db->prepare('UPDATE orders SET discount_cents=discount_cents+?,total_cents=total_cents-?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=? AND total_cents>=?');
            $o->execute([$discount,$discount,$orderId,$userId,$discount]);if($o->rowCount()!==1)throw new \RuntimeException('Order changed before rewards reservation.');
            $this->db->commit();return $this->orderRow($orderId)??[];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function releaseForOrder(int $orderId): void
    {
        $this->db->beginTransaction();
        try{
            $row=$this->orderRow($orderId);
            if(!$row || $row['status']!=='reserved'){$this->db->rollBack();return;}
            $u=$this->db->prepare('UPDATE loyalty_accounts SET reserved_points=MAX(0,reserved_points-?),updated_at=CURRENT_TIMESTAMP WHERE user_id=?');
            $u->execute([(int)$row['reserved_points'],(int)$row['user_id']]);
            $q=$this->db->prepare("UPDATE order_loyalty_redemptions SET status='released',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='reserved'");$q->execute([$orderId]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function commitRedemption(int $orderId): int
    {
        $this->db->beginTransaction();
        try{
            $row=$this->orderRow($orderId);
            if(!$row || (int)$row['reserved_points']<=0){$this->db->rollBack();return 0;}
            if($row['status']==='redeemed'){$this->db->rollBack();return (int)$row['redeemed_points'];}
            if($row['status']!=='reserved') throw new \RuntimeException('Rewards reservation is not redeemable.');
            $points=(int)$row['reserved_points'];$userId=(int)$row['user_id'];
            $account=$this->account($userId);
            if((int)$account['points_balance']<$points || (int)$account['reserved_points']<$points) throw new \RuntimeException('Rewards reservation no longer matches the account balance.');
            $newBalance=(int)$account['points_balance']-$points;
            $u=$this->db->prepare('UPDATE loyalty_accounts SET points_balance=?,reserved_points=reserved_points-?,lifetime_redeemed_points=lifetime_redeemed_points+?,updated_at=CURRENT_TIMESTAMP WHERE user_id=?');
            $u->execute([$newBalance,$points,$points,$userId]);
            $q=$this->db->prepare("UPDATE order_loyalty_redemptions SET redeemed_points=?,status='redeemed',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='reserved'");$q->execute([$points,$orderId]);
            $l=$this->db->prepare("INSERT INTO loyalty_ledger(user_id,order_id,entry_type,points,balance_after_points,event_key,note) VALUES(?,?,'redeem',?,?,?,'Rewards redeemed on paid order.')");
            $l->execute([$userId,$orderId,-$points,$newBalance,'redeem:'.$orderId]);
            $this->db->commit();return $points;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function earnForOrder(int $orderId): int
    {
        $s=$this->db->prepare("SELECT id,user_id,subtotal_cents,discount_cents,status FROM orders WHERE id=? AND user_id IS NOT NULL AND status IN ('paid','preparing','ready','shipped','delivered','completed')");
        $s->execute([$orderId]);$order=$s->fetch();if(!$order)return 0;
        $settings=$this->settings();if($settings['enabled']!=='1'||(int)$settings['points_per_dollar']<=0)return 0;
        $eligible=max(0,(int)$order['subtotal_cents']-(int)$order['discount_cents']);
        $points=intdiv($eligible*(int)$settings['points_per_dollar'],100);if($points<=0)return 0;
        $userId=(int)$order['user_id'];$event='earn:'.$orderId;
        $existing=$this->db->prepare('SELECT points FROM loyalty_ledger WHERE event_key=?');$existing->execute([$event]);$done=$existing->fetchColumn();if($done!==false)return (int)$done;

        $this->db->beginTransaction();
        try{
            $account=$this->account($userId);$newBalance=(int)$account['points_balance']+$points;
            $u=$this->db->prepare('UPDATE loyalty_accounts SET points_balance=?,lifetime_earned_points=lifetime_earned_points+?,updated_at=CURRENT_TIMESTAMP WHERE user_id=?');$u->execute([$newBalance,$points,$userId]);
            $q=$this->db->prepare("INSERT INTO order_loyalty_redemptions(order_id,user_id,earned_points,status) VALUES(?,?,?,'none') ON CONFLICT(order_id) DO UPDATE SET earned_points=excluded.earned_points,updated_at=CURRENT_TIMESTAMP");$q->execute([$orderId,$userId,$points]);
            $l=$this->db->prepare("INSERT INTO loyalty_ledger(user_id,order_id,entry_type,points,balance_after_points,event_key,note) VALUES(?,?,'earn',?,?,?,'Rewards earned from paid merchandise after discounts.')");$l->execute([$userId,$orderId,$points,$newBalance,$event]);
            $this->db->commit();return $points;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function applyRefund(int $orderId,int $refundId): array
    {
        $row=$this->orderRow($orderId);if(!$row)return ['restored'=>0,'reversed'=>0];
        $o=$this->db->prepare('SELECT total_cents FROM orders WHERE id=?');$o->execute([$orderId]);$total=(int)$o->fetchColumn();if($total<=0)return ['restored'=>0,'reversed'=>0];
        $r=$this->db->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM refund_records WHERE order_id=? AND status='succeeded'");$r->execute([$orderId]);$refunded=min($total,(int)$r->fetchColumn());

        $targetRestore=$refunded>=$total?(int)$row['redeemed_points']:intdiv((int)$row['redeemed_points']*$refunded,$total);
        $targetReverse=$refunded>=$total?(int)$row['earned_points']:intdiv((int)$row['earned_points']*$refunded,$total);
        $restore=max(0,$targetRestore-(int)$row['restored_points']);$reverse=max(0,$targetReverse-(int)$row['reversed_points']);
        if($restore===0&&$reverse===0)return ['restored'=>0,'reversed'=>0];

        $this->db->beginTransaction();
        try{
            $account=$this->account((int)$row['user_id']);$balance=(int)$account['points_balance'];
            if($restore>0){
                $balance+=$restore;
                $l=$this->db->prepare("INSERT OR IGNORE INTO loyalty_ledger(user_id,order_id,refund_id,entry_type,points,balance_after_points,event_key,note) VALUES(?,?,?,'refund_restore',?,?,?,'Redeemed rewards restored after refund.')");
                $l->execute([(int)$row['user_id'],$orderId,$refundId,$restore,$balance,'refund_restore:'.$refundId]);
            }
            if($reverse>0){
                $balance-=$reverse;
                $l=$this->db->prepare("INSERT OR IGNORE INTO loyalty_ledger(user_id,order_id,refund_id,entry_type,points,balance_after_points,event_key,note) VALUES(?,?,?,'refund_reversal',?,?,?,'Earned rewards reversed after refund.')");
                $l->execute([(int)$row['user_id'],$orderId,$refundId,-$reverse,$balance,'refund_reversal:'.$refundId]);
            }
            $u=$this->db->prepare('UPDATE loyalty_accounts SET points_balance=?,updated_at=CURRENT_TIMESTAMP WHERE user_id=?');$u->execute([$balance,(int)$row['user_id']]);
            $q=$this->db->prepare('UPDATE order_loyalty_redemptions SET restored_points=restored_points+?,reversed_points=reversed_points+?,updated_at=CURRENT_TIMESTAMP WHERE order_id=?');$q->execute([$restore,$reverse,$orderId]);
            $this->db->commit();return ['restored'=>$restore,'reversed'=>$reverse];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function adjustByEmail(string $email,int $points,string $reason,int $adminId): array
    {
        $email=strtolower(trim($email));if($points===0||abs($points)>100000)throw new \InvalidArgumentException('Adjustment must be between -100000 and 100000 points.');
        $u=$this->db->prepare('SELECT id FROM users WHERE email=?');$u->execute([$email]);$userId=$u->fetchColumn();if($userId===false)throw new \InvalidArgumentException('Customer account not found.');
        $account=$this->account((int)$userId);$newBalance=(int)$account['points_balance']+$points;
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('UPDATE loyalty_accounts SET points_balance=?,updated_at=CURRENT_TIMESTAMP WHERE user_id=?');$s->execute([$newBalance,(int)$userId]);
            $key='adjust:'.$adminId.':'.bin2hex(random_bytes(8));
            $l=$this->db->prepare("INSERT INTO loyalty_ledger(user_id,entry_type,points,balance_after_points,event_key,note) VALUES(?,'adjustment',?,?,?,?)");$l->execute([(int)$userId,$points,$newBalance,$key,mb_substr(trim($reason),0,500)]);
            $this->db->commit();return $this->account((int)$userId);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function ledgerForUser(int $userId,int $limit=100): array
    {
        $limit=max(1,min(500,$limit));$s=$this->db->prepare("SELECT * FROM loyalty_ledger WHERE user_id=? ORDER BY id DESC LIMIT {$limit}");$s->execute([$userId]);return $s->fetchAll();
    }

    public function programStats(): array
    {
        $settings=$this->settings();$centsPerPoint=(int)$settings['cents_per_point'];
        $row=$this->db->query("SELECT COUNT(*) members,COALESCE(SUM(CASE WHEN points_balance>0 THEN points_balance ELSE 0 END),0) positive_points,COALESCE(SUM(reserved_points),0) reserved_points,COALESCE(SUM(lifetime_earned_points),0) earned_points,COALESCE(SUM(lifetime_redeemed_points),0) redeemed_points FROM loyalty_accounts")->fetch()?:[];
        return [
            'members'=>(int)($row['members']??0),'positive_points'=>(int)($row['positive_points']??0),'reserved_points'=>(int)($row['reserved_points']??0),
            'earned_points'=>(int)($row['earned_points']??0),'redeemed_points'=>(int)($row['redeemed_points']??0),
            'liability_cents'=>(int)($row['positive_points']??0)*$centsPerPoint,
        ];
    }

    public function orderRow(int $orderId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM order_loyalty_redemptions WHERE order_id=?');$s->execute([$orderId]);return $s->fetch()?:null;
    }

    private function ensureOrderRow(int $userId,int $orderId): array
    {
        $s=$this->db->prepare("INSERT OR IGNORE INTO order_loyalty_redemptions(order_id,user_id,status) VALUES(?,?,'none')");$s->execute([$orderId,$userId]);
        return $this->orderRow($orderId)??[];
    }
}
