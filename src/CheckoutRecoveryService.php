<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CheckoutRecoveryService
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $secret,
        private readonly string $baseUrl,
        private readonly int $days=7
    ) {}

    public function capture(?int $existingId,?int $userId,string $email,array $cart,?string $coupon): int
    {
        $email=strtolower(trim($email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid checkout email.');
        if(!$cart) throw new \InvalidArgumentException('Cannot recover an empty cart.');
        $payload=json_encode(['cart'=>$cart,'coupon'=>$coupon],JSON_THROW_ON_ERROR);
        if(strlen($payload)>150000) throw new \InvalidArgumentException('Checkout recovery snapshot is too large.');
        $expires=gmdate('Y-m-d H:i:s',time()+(max(1,min(30,$this->days))*86400));

        if($existingId){
            $s=$this->db->prepare("UPDATE checkout_recoveries SET user_id=?,email=?,cart_json=?,status='active',linked_order_id=NULL,expires_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','recovered')");
            $s->execute([$userId,$email,$payload,$expires,$existingId]);
            if($s->rowCount()===1)return $existingId;
        }
        $s=$this->db->prepare("INSERT INTO checkout_recoveries(user_id,email,cart_json,status,expires_at) VALUES(?,?,?,'active',?)");
        $s->execute([$userId,$email,$payload,$expires]);return (int)$this->db->lastInsertId();
    }

    public function link(array $row): string
    {
        $this->requireSecret();
        $id=(int)$row['id'];$expires=strtotime((string)$row['expires_at'])?:0;
        $sig=$this->signature($id,(string)$row['email'],$expires);
        return rtrim($this->baseUrl,'/').'/recover-checkout.php?id='.$id.'&expires='.$expires.'&sig='.rawurlencode($sig);
    }

    public function resolve(int $id,int $expires,string $sig): array
    {
        $this->requireSecret();
        if($id<=0 || $expires<time() || $expires>time()+2678400) throw new \InvalidArgumentException('Recovery link has expired.');
        $s=$this->db->prepare("SELECT * FROM checkout_recoveries WHERE id=? AND status IN ('active','recovered')");$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Recovery link is unavailable.');
        $storedExpires=strtotime((string)$row['expires_at'])?:0;
        if($storedExpires<$expires-2 || $storedExpires>$expires+2 || $storedExpires<time()) throw new \InvalidArgumentException('Recovery link has expired.');
        if(!hash_equals($this->signature($id,(string)$row['email'],$expires),$sig)) throw new \InvalidArgumentException('Recovery link is invalid.');
        $payload=json_decode((string)$row['cart_json'],true);
        if(!is_array($payload) || !is_array($payload['cart']??null)) throw new \RuntimeException('Recovery snapshot is invalid.');
        return ['row'=>$row,'cart'=>$payload['cart'],'coupon'=>$payload['coupon']??null];
    }

    public function markRecovered(int $id): void
    {
        $s=$this->db->prepare("UPDATE checkout_recoveries SET status='recovered',recovered_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'");$s->execute([$id]);
    }

    public function attachOrder(int $id,int $orderId): void
    {
        $s=$this->db->prepare("UPDATE checkout_recoveries SET linked_order_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','recovered')");
        $s->execute([$orderId,$id]);
    }

    public function markConvertedByOrder(int $orderId): void
    {
        $s=$this->db->prepare("UPDATE checkout_recoveries SET status='converted',converted_order_id=?,updated_at=CURRENT_TIMESTAMP WHERE linked_order_id=? AND status IN ('active','recovered')");
        $s->execute([$orderId,$orderId]);
    }

    public function markConverted(int $id,int $orderId): void
    {
        $s=$this->db->prepare("UPDATE checkout_recoveries SET status='converted',converted_order_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','recovered')");
        $s->execute([$orderId,$id]);
    }

    public function dueForReminder(int $hours=2,int $limit=100): array
    {
        $hours=max(1,min(72,$hours));$limit=max(1,min(500,$limit));
        $s=$this->db->prepare("SELECT r.* FROM checkout_recoveries r JOIN newsletter_subscribers n ON lower(n.email)=lower(r.email) AND n.status='subscribed' WHERE r.status='active' AND r.reminder_sent_at IS NULL AND r.expires_at>CURRENT_TIMESTAMP AND r.created_at<=datetime('now',?) ORDER BY r.id LIMIT {$limit}");
        $s->execute(['-'.$hours.' hours']);return $s->fetchAll();
    }

    public function markReminderSent(int $id): void
    {
        $s=$this->db->prepare("UPDATE checkout_recoveries SET reminder_sent_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active' AND reminder_sent_at IS NULL");$s->execute([$id]);
    }

    public function expire(): int
    {
        $s=$this->db->prepare("UPDATE checkout_recoveries SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE status IN ('active','recovered') AND expires_at<=CURRENT_TIMESTAMP");
        $s->execute();return $s->rowCount();
    }

    public function stats(): array
    {
        $out=['active'=>0,'recovered'=>0,'converted'=>0,'expired'=>0,'reminders'=>0,'total'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count FROM checkout_recoveries GROUP BY status')->fetchAll() as $row){$out[(string)$row['status']]=(int)$row['count'];$out['total']+=(int)$row['count'];}
        $out['reminders']=(int)$this->db->query('SELECT COUNT(*) FROM checkout_recoveries WHERE reminder_sent_at IS NOT NULL')->fetchColumn();return $out;
    }

    public function recent(int $limit=200): array
    {
        $limit=max(1,min(500,$limit));return $this->db->query("SELECT * FROM checkout_recoveries ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    private function signature(int $id,string $email,int $expires): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256',$id.'|'.strtolower($email).'|'.$expires,$this->secret,true)),'+/','-_'),'=');
    }

    private function requireSecret(): void
    {
        if(strlen($this->secret)<32) throw new \RuntimeException('APP_KEY must be at least 32 characters for checkout recovery links.');
    }
}
