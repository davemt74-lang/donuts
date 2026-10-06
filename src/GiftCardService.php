<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class GiftCardService
{
    public function __construct(private readonly PDO $db,private readonly string $appKey) {}

    public function allowedAmounts(): array
    {
        $raw=explode(',',(string)\env('GIFT_CARD_AMOUNTS_CENTS','2500,5000,10000'));
        $values=array_values(array_unique(array_filter(array_map('intval',$raw),fn($v)=>$v>=500&&$v<=100000)));
        sort($values);return $values?:[2500,5000,10000];
    }

    public function createPurchase(int $amountCents,string $purchaserEmail,string $recipientEmail,string $recipientName='',string $message=''): array
    {
        if(!in_array($amountCents,$this->allowedAmounts(),true)) throw new \InvalidArgumentException('Choose an available gift card amount.');
        $purchaserEmail=strtolower(trim($purchaserEmail));$recipientEmail=strtolower(trim($recipientEmail));
        if(!filter_var($purchaserEmail,FILTER_VALIDATE_EMAIL)||!filter_var($recipientEmail,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter valid purchaser and recipient email addresses.');
        $recipientName=mb_substr(trim($recipientName),0,190);$message=mb_substr(trim($message),0,500);
        $token=bin2hex(random_bytes(24));
        $s=$this->db->prepare("INSERT INTO gift_card_purchases(public_token,amount_cents,purchaser_email,recipient_email,recipient_name,message) VALUES(?,?,?,?,?,?)");
        $s->execute([$token,$amountCents,$purchaserEmail,$recipientEmail,$recipientName,$message]);
        return $this->purchase((int)$this->db->lastInsertId());
    }

    public function purchase(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM gift_card_purchases WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Gift card purchase not found.');
        return $row;
    }

    public function purchaseByStripeSession(string $sessionId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM gift_card_purchases WHERE stripe_session_id=?');$s->execute([$sessionId]);return $s->fetch()?:null;
    }

    public function attachPurchaseStripeSession(int $purchaseId,string $sessionId): void
    {
        $s=$this->db->prepare("UPDATE gift_card_purchases SET stripe_session_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
        $s->execute([$sessionId,$purchaseId]);
        if($s->rowCount()!==1) throw new \RuntimeException('Gift card purchase changed before payment session attachment.');
    }

    public function failPurchaseByStripeSession(string $sessionId): void
    {
        $s=$this->db->prepare("UPDATE gift_card_purchases SET status='failed',updated_at=CURRENT_TIMESTAMP WHERE stripe_session_id=? AND status='pending'");$s->execute([$sessionId]);
    }

    public function failPurchase(int $purchaseId): void
    {
        $s=$this->db->prepare("UPDATE gift_card_purchases SET status='failed',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
        $s->execute([$purchaseId]);
    }

    public function activatePurchase(int $purchaseId,string $sessionId,int $paidCents,string $currency): array
    {
        if(strtolower($currency)!=='usd') throw new \RuntimeException('Gift card currency mismatch.');
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT * FROM gift_card_purchases WHERE id=?');$s->execute([$purchaseId]);$p=$s->fetch();
            if(!$p) throw new \InvalidArgumentException('Gift card purchase not found.');
            if((int)$p['amount_cents']!==$paidCents) throw new \RuntimeException('Gift card payment amount mismatch.');
            if($p['stripe_session_id']!==null && (string)$p['stripe_session_id']!==$sessionId) throw new \RuntimeException('Gift card payment session mismatch.');
            if($p['status']==='paid' && !empty($p['gift_card_id'])){
                $code=$this->decryptedCode((int)$p['gift_card_id']);$card=$this->card((int)$p['gift_card_id']);$this->db->commit();return ['card'=>$card,'code'=>$code,'purchase'=>$p];
            }
            if($p['status']!=='pending') throw new \RuntimeException('Gift card purchase is not payable.');

            $code=$this->generateCode();[$cipher,$iv,$tag]=$this->encryptCode($code);
            $normalized=$this->normalizeCode($code);$hash=hash('sha256',$normalized);$last4=substr($normalized,-4);
            $g=$this->db->prepare("INSERT INTO gift_cards(code_hash,code_cipher,code_iv,code_tag,code_last4,initial_balance_cents,balance_cents,status,recipient_email,issued_from_purchase_id) VALUES(?,?,?,?,?,?,?,'active',?,?)");
            $g->execute([$hash,$cipher,$iv,$tag,$last4,$paidCents,$paidCents,(string)$p['recipient_email'],$purchaseId]);
            $cardId=(int)$this->db->lastInsertId();
            $l=$this->db->prepare("INSERT INTO gift_card_ledger(gift_card_id,purchase_id,entry_type,amount_cents,balance_after_cents,event_key,note) VALUES(?,?,'issue',?,?,?,'Gift card issued after verified Stripe payment.')");
            $l->execute([$cardId,$purchaseId,$paidCents,$paidCents,'issue:'.$purchaseId]);
            $u=$this->db->prepare("UPDATE gift_card_purchases SET status='paid',stripe_session_id=?,gift_card_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
            $u->execute([$sessionId,$cardId,$purchaseId]);
            $this->db->commit();
            return ['card'=>$this->card($cardId),'code'=>$code,'purchase'=>$this->purchase($purchaseId)];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function findByCode(string $code): ?array
    {
        $normalized=$this->normalizeCode($code);if($normalized==='')return null;
        $s=$this->db->prepare('SELECT * FROM gift_cards WHERE code_hash=?');$s->execute([hash('sha256',$normalized)]);$row=$s->fetch();
        if(!$row)return null;$row['available_cents']=max(0,(int)$row['balance_cents']-(int)$row['reserved_cents']);return $row;
    }

    public function card(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM gift_cards WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row)throw new \InvalidArgumentException('Gift card not found.');
        $row['available_cents']=max(0,(int)$row['balance_cents']-(int)$row['reserved_cents']);return $row;
    }

    public function reserveForOrder(int $giftCardId,int $orderId,int $grossCents): array
    {
        if($grossCents<=0) throw new \InvalidArgumentException('Order total must be positive.');
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT * FROM order_gift_card_applications WHERE order_id=?');$q->execute([$orderId]);$existing=$q->fetch();
            if($existing){
                if((int)$existing['gift_card_id']!==$giftCardId) throw new \RuntimeException('Order already has a different gift card tender.');
                $this->db->commit();return $existing;
            }
            $card=$this->card($giftCardId);
            if($card['status']!=='active') throw new \InvalidArgumentException('Gift card is not active.');
            $available=(int)$card['available_cents'];$apply=min($available,$grossCents);
            if($apply<=0) throw new \InvalidArgumentException('Gift card has no available balance.');
            $u=$this->db->prepare('UPDATE gift_cards SET reserved_cents=reserved_cents+?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=\'active\' AND balance_cents-reserved_cents>=?');
            $u->execute([$apply,$giftCardId,$apply]);if($u->rowCount()!==1) throw new \RuntimeException('Gift card balance changed before reservation.');
            $i=$this->db->prepare("INSERT INTO order_gift_card_applications(order_id,gift_card_id,reserved_cents,status) VALUES(?,?,?,'reserved')");
            $i->execute([$orderId,$giftCardId,$apply]);
            $this->db->commit();return ['order_id'=>$orderId,'gift_card_id'=>$giftCardId,'reserved_cents'=>$apply,'redeemed_cents'=>0,'refunded_cents'=>0,'status'=>'reserved'];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function applicationForOrder(int $orderId): ?array
    {
        $s=$this->db->prepare('SELECT a.*,g.code_last4,g.balance_cents,g.reserved_cents card_reserved_cents,g.status card_status FROM order_gift_card_applications a JOIN gift_cards g ON g.id=a.gift_card_id WHERE a.order_id=?');
        $s->execute([$orderId]);return $s->fetch()?:null;
    }

    public function releaseForOrder(int $orderId): void
    {
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM order_gift_card_applications WHERE order_id=? AND status='reserved'");$s->execute([$orderId]);$a=$s->fetch();
            if(!$a){$this->db->rollBack();return;}
            $u=$this->db->prepare('UPDATE gift_cards SET reserved_cents=MAX(0,reserved_cents-?),updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([(int)$a['reserved_cents'],(int)$a['gift_card_id']]);
            $x=$this->db->prepare("UPDATE order_gift_card_applications SET status='released',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='reserved'");$x->execute([$orderId]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function redeemForOrder(int $orderId): int
    {
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM order_gift_card_applications WHERE order_id=?");$s->execute([$orderId]);$a=$s->fetch();
            if(!$a){$this->db->rollBack();return 0;}
            if($a['status']==='redeemed'){$this->db->rollBack();return (int)$a['redeemed_cents'];}
            if($a['status']!=='reserved') throw new \RuntimeException('Gift card reservation is not redeemable.');
            $amount=(int)$a['reserved_cents'];
            $c=$this->db->prepare('SELECT balance_cents,reserved_cents FROM gift_cards WHERE id=?');$c->execute([(int)$a['gift_card_id']]);$card=$c->fetch();
            if(!$card || (int)$card['balance_cents']<$amount || (int)$card['reserved_cents']<$amount) throw new \RuntimeException('Gift card reservation no longer matches the balance.');
            $newBalance=(int)$card['balance_cents']-$amount;
            $u=$this->db->prepare("UPDATE gift_cards SET balance_cents=?,reserved_cents=reserved_cents-?,status=CASE WHEN ?=0 THEN 'depleted' ELSE 'active' END,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $u->execute([$newBalance,$amount,$newBalance,(int)$a['gift_card_id']]);
            $x=$this->db->prepare("UPDATE order_gift_card_applications SET redeemed_cents=?,status='redeemed',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='reserved'");$x->execute([$amount,$orderId]);
            $l=$this->db->prepare("INSERT OR IGNORE INTO gift_card_ledger(gift_card_id,order_id,entry_type,amount_cents,balance_after_cents,event_key,note) VALUES(?,?,'redeem',?,?,?,'Gift card tender redeemed for order.')");
            $l->execute([(int)$a['gift_card_id'],$orderId,-$amount,$newBalance,'redeem:'.$orderId]);
            $this->db->commit();return $amount;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function refundForOrder(int $orderId,int $amountCents,string $eventKey): int
    {
        if($amountCents<=0)return 0;
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM order_gift_card_applications WHERE order_id=? AND status='redeemed'");$s->execute([$orderId]);$a=$s->fetch();
            if(!$a) throw new \InvalidArgumentException('Order has no redeemed gift card tender.');
            $remaining=(int)$a['redeemed_cents']-(int)$a['refunded_cents'];
            if($amountCents>$remaining) throw new \InvalidArgumentException('Gift card refund exceeds original gift card tender.');
            $c=$this->db->prepare('SELECT balance_cents FROM gift_cards WHERE id=?');$c->execute([(int)$a['gift_card_id']]);$balance=(int)$c->fetchColumn();
            $newBalance=$balance+$amountCents;
            $u=$this->db->prepare("UPDATE gift_cards SET balance_cents=?,status='active',updated_at=CURRENT_TIMESTAMP WHERE id=?");$u->execute([$newBalance,(int)$a['gift_card_id']]);
            $x=$this->db->prepare('UPDATE order_gift_card_applications SET refunded_cents=refunded_cents+?,updated_at=CURRENT_TIMESTAMP WHERE order_id=?');$x->execute([$amountCents,$orderId]);
            $l=$this->db->prepare("INSERT OR IGNORE INTO gift_card_ledger(gift_card_id,order_id,entry_type,amount_cents,balance_after_cents,event_key,note) VALUES(?,?,'refund',?,?,?,'Refund restored to original gift card.')");
            $l->execute([(int)$a['gift_card_id'],$orderId,$amountCents,$newBalance,$eventKey]);
            $this->db->commit();return $amountCents;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function liability(): array
    {
        $row=$this->db->query("SELECT COUNT(*) cards,COALESCE(SUM(balance_cents),0) balance_cents,COALESCE(SUM(reserved_cents),0) reserved_cents FROM gift_cards WHERE status IN ('active','depleted')")->fetch()?:[];
        return ['cards'=>(int)($row['cards']??0),'balance_cents'=>(int)($row['balance_cents']??0),'reserved_cents'=>(int)($row['reserved_cents']??0),'available_cents'=>max(0,(int)($row['balance_cents']??0)-(int)($row['reserved_cents']??0))];
    }

    public function recentCards(int $limit=100): array
    {
        $limit=max(1,min(500,$limit));
        return $this->db->query("SELECT id,code_last4,initial_balance_cents,balance_cents,reserved_cents,status,recipient_email,created_at FROM gift_cards ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    public function ledger(int $giftCardId,int $limit=100): array
    {
        $limit=max(1,min(500,$limit));
        $s=$this->db->prepare("SELECT * FROM gift_card_ledger WHERE gift_card_id=? ORDER BY id DESC LIMIT {$limit}");
        $s->execute([$giftCardId]);return $s->fetchAll();
    }

    public function setStatus(int $giftCardId,string $status): void
    {
        if(!in_array($status,['active','disabled'],true)) throw new \InvalidArgumentException('Invalid gift card status.');
        $card=$this->card($giftCardId);
        if($status==='active' && (int)$card['balance_cents']<=0) throw new \InvalidArgumentException('A zero-balance gift card cannot be reactivated.');
        $s=$this->db->prepare("UPDATE gift_cards SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $s->execute([$status,$giftCardId]);
    }

    public function purchaseStats(): array
    {
        $out=['pending'=>0,'paid'=>0,'failed'=>0];
        foreach($this->db->query("SELECT status,COUNT(*) count FROM gift_card_purchases GROUP BY status")->fetchAll() as $row){
            $out[(string)$row['status']]=(int)$row['count'];
        }
        return $out;
    }

    public function decryptedCode(int $cardId): string
    {
        $s=$this->db->prepare('SELECT code_cipher,code_iv,code_tag FROM gift_cards WHERE id=?');$s->execute([$cardId]);$row=$s->fetch();
        if(!$row)throw new \InvalidArgumentException('Gift card not found.');
        return $this->decryptCode((string)$row['code_cipher'],(string)$row['code_iv'],(string)$row['code_tag']);
    }

    private function generateCode(): string
    {
        $hex=strtoupper(bin2hex(random_bytes(8)));return 'FDGC-'.substr($hex,0,4).'-'.substr($hex,4,4).'-'.substr($hex,8,4).'-'.substr($hex,12,4);
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]/i','',trim($code)));
    }

    private function cryptoKey(): string
    {
        if(strlen($this->appKey)<24) throw new \RuntimeException('APP_KEY is required for gift card encryption.');
        return hash('sha256',$this->appKey,true);
    }

    private function encryptCode(string $code): array
    {
        if(!function_exists('openssl_encrypt')) throw new \RuntimeException('OpenSSL is required for gift card encryption.');
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($code,'aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false) throw new \RuntimeException('Gift card encryption failed.');
        return [base64_encode($cipher),base64_encode($iv),base64_encode($tag)];
    }

    private function decryptCode(string $cipher,string $iv,string $tag): string
    {
        $plain=openssl_decrypt(base64_decode($cipher,true)?:'','aes-256-gcm',$this->cryptoKey(),OPENSSL_RAW_DATA,base64_decode($iv,true)?:'',base64_decode($tag,true)?:'');
        if($plain===false) throw new \RuntimeException('Gift card decryption failed.');
        return $plain;
    }
}
