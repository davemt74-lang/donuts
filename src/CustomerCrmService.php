<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CustomerCrmService
{
    public function __construct(private readonly PDO $db) {}

    public function search(string $query='',int $limit=100): array
    {
        $limit=max(1,min(250,$limit));$query=trim($query);$emails=[];
        if($query===''){
            $sql="SELECT email FROM (
                SELECT lower(email) email,MAX(created_at) last_seen FROM orders GROUP BY lower(email)
                UNION ALL SELECT lower(email) email,created_at last_seen FROM users
                UNION ALL SELECT lower(email) email,MAX(created_at) last_seen FROM support_tickets GROUP BY lower(email)
                UNION ALL SELECT lower(purchaser_email) email,MAX(created_at) last_seen FROM gift_card_purchases GROUP BY lower(purchaser_email)
            ) GROUP BY email ORDER BY MAX(last_seen) DESC LIMIT {$limit}";
            foreach($this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $email)$emails[]=(string)$email;
        }else{
            $needle='%'.strtolower($query).'%';
            $s=$this->db->prepare("SELECT email FROM (
                SELECT lower(email) email FROM users WHERE lower(email) LIKE ? OR lower(first_name||' '||last_name) LIKE ?
                UNION SELECT lower(email) email FROM orders WHERE lower(email) LIKE ? OR lower(first_name||' '||last_name) LIKE ? OR lower(order_number) LIKE ?
                UNION SELECT lower(email) email FROM support_tickets WHERE lower(email) LIKE ? OR lower(customer_name) LIKE ? OR lower(ticket_number) LIKE ?
                UNION SELECT lower(purchaser_email) email FROM gift_card_purchases WHERE lower(purchaser_email) LIKE ? OR lower(recipient_email) LIKE ?
            ) LIMIT {$limit}");
            $s->execute([$needle,$needle,$needle,$needle,$needle,$needle,$needle,$needle,$needle,$needle]);$emails=array_map('strval',$s->fetchAll(PDO::FETCH_COLUMN));
        }
        $out=[];foreach(array_values(array_unique($emails)) as $email)$out[]=$this->summary($email);
        usort($out,fn($a,$b)=>strcmp((string)$b['last_order_at'],(string)$a['last_order_at']));
        return $out;
    }

    public function summary(string $email): array
    {
        $email=$this->normalizeEmail($email);$profile=$this->baseIdentity($email);
        $s=$this->db->prepare("SELECT COUNT(*) orders,COALESCE(SUM(total_cents),0) gross_cents,COALESCE(AVG(total_cents),0) aov_cents,MAX(created_at) last_order_at,MIN(created_at) first_order_at FROM orders WHERE lower(email)=? AND status NOT IN ('cancelled','payment_failed')");
        $s->execute([$email]);$stats=$s->fetch()?:[];
        $refund=$this->db->prepare("SELECT COALESCE(SUM(r.amount_cents),0) FROM refund_records r JOIN orders o ON o.id=r.order_id WHERE lower(o.email)=? AND r.status='succeeded'");
        $refund->execute([$email]);$refunded=(int)$refund->fetchColumn();
        $activeSupport=$this->db->prepare("SELECT COUNT(*) FROM support_tickets WHERE lower(email)=? AND status IN ('open','in_progress','waiting_customer')");$activeSupport->execute([$email]);
        return [
            ...$profile,
            'orders'=>(int)($stats['orders']??0),
            'gross_cents'=>(int)($stats['gross_cents']??0),
            'refunded_cents'=>$refunded,
            'net_cents'=>max(0,(int)($stats['gross_cents']??0)-$refunded),
            'aov_cents'=>(int)($stats['aov_cents']??0),
            'first_order_at'=>(string)($stats['first_order_at']??''),
            'last_order_at'=>(string)($stats['last_order_at']??''),
            'active_support'=>(int)$activeSupport->fetchColumn(),
            'tags'=>$this->tags($email),
        ];
    }

    public function profile(string $email): array
    {
        $email=$this->normalizeEmail($email);$summary=$this->summary($email);
        $orders=$this->rows("SELECT id,order_number,status,total_cents,fulfillment_type,created_at FROM orders WHERE lower(email)=? ORDER BY id DESC LIMIT 100",[$email]);
        $support=$this->rows("SELECT id,ticket_number,subject,status,priority,updated_at FROM support_tickets WHERE lower(email)=? ORDER BY id DESC LIMIT 50",[$email]);
        $giftPurchases=$this->safeRows("SELECT id,amount_cents,recipient_email,status,created_at FROM gift_card_purchases WHERE lower(purchaser_email)=? ORDER BY id DESC LIMIT 50",[$email]);
        $notes=$this->notes($email);
        return [...$summary,'order_history'=>$orders,'support_history'=>$support,'gift_purchases'=>$giftPurchases,'notes'=>$notes];
    }

    public function addNote(string $email,int $adminId,string $note): int
    {
        $email=$this->normalizeEmail($email);$note=trim($note);
        if(mb_strlen($note)<2 || mb_strlen($note)>3000)throw new \InvalidArgumentException('Customer note must be between 2 and 3000 characters.');
        $s=$this->db->prepare('INSERT INTO customer_admin_notes(customer_email_hash,customer_email,admin_id,note) VALUES(?,?,?,?)');
        $s->execute([$this->hash($email),$email,$adminId,$note]);return (int)$this->db->lastInsertId();
    }

    public function addTag(string $email,int $adminId,string $tag): void
    {
        $email=$this->normalizeEmail($email);$tag=strtolower(trim($tag));
        if(!preg_match('/^[a-z0-9][a-z0-9 _-]{1,63}$/',$tag))throw new \InvalidArgumentException('Tag must be 2–64 characters using letters, numbers, spaces, dashes, or underscores.');
        $s=$this->db->prepare('INSERT OR IGNORE INTO customer_tags(customer_email_hash,customer_email,tag,created_by_admin_id) VALUES(?,?,?,?)');
        $s->execute([$this->hash($email),$email,$tag,$adminId]);
    }

    public function removeTag(string $email,string $tag): void
    {
        $email=$this->normalizeEmail($email);$s=$this->db->prepare('DELETE FROM customer_tags WHERE customer_email_hash=? AND tag=?');$s->execute([$this->hash($email),strtolower(trim($tag))]);
    }

    public function purgeInternalData(string $email): int
    {
        $email=$this->normalizeEmail($email);$hash=$this->hash($email);$count=0;
        foreach(['customer_admin_notes','customer_tags'] as $table){$s=$this->db->prepare("DELETE FROM {$table} WHERE customer_email_hash=?");$s->execute([$hash]);$count+=$s->rowCount();}
        return $count;
    }

    private function baseIdentity(string $email): array
    {
        $u=$this->db->prepare('SELECT id,first_name,last_name,created_at FROM users WHERE lower(email)=?');$u->execute([$email]);$user=$u->fetch();
        $o=$this->db->prepare('SELECT first_name,last_name FROM orders WHERE lower(email)=? ORDER BY id DESC LIMIT 1');$o->execute([$email]);$order=$o->fetch();
        $support=$this->db->prepare('SELECT customer_name FROM support_tickets WHERE lower(email)=? ORDER BY id DESC LIMIT 1');$support->execute([$email]);$supportName=(string)($support->fetchColumn()?:'');
        $gift=$this->db->prepare('SELECT purchaser_email FROM gift_card_purchases WHERE lower(purchaser_email)=? ORDER BY id DESC LIMIT 1');$gift->execute([$email]);$giftMatch=$gift->fetchColumn();
        if(!$user && !$order && $supportName==='' && $giftMatch===false) throw new \InvalidArgumentException('Customer not found.');
        $first=(string)($user['first_name']??$order['first_name']??'');$last=(string)($user['last_name']??$order['last_name']??'');
        if($first==='' && $supportName!==''){
            $parts=preg_split('/\s+/',trim($supportName),2)?:[];$first=(string)($parts[0]??'');$last=(string)($parts[1]??'');
        }
        return [
            'email'=>$email,
            'user_id'=>$user?(int)$user['id']:null,
            'registered'=>$user!==false,
            'first_name'=>$first,
            'last_name'=>$last,
            'account_created_at'=>(string)($user['created_at']??''),
        ];
    }

    private function notes(string $email): array
    {
        try{$s=$this->db->prepare('SELECT n.*,a.email admin_email FROM customer_admin_notes n JOIN admin_users a ON a.id=n.admin_id WHERE n.customer_email_hash=? ORDER BY n.id DESC');$s->execute([$this->hash($email)]);return $s->fetchAll();}catch(\Throwable){return [];}
    }

    private function tags(string $email): array
    {
        try{$s=$this->db->prepare('SELECT tag FROM customer_tags WHERE customer_email_hash=? ORDER BY tag');$s->execute([$this->hash($email)]);return array_map('strval',$s->fetchAll(PDO::FETCH_COLUMN));}catch(\Throwable){return [];}
    }

    private function normalizeEmail(string $email): string
    {
        $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid customer email.');return $email;
    }

    private function hash(string $email): string { return hash('sha256',$email); }
    private function rows(string $sql,array $params): array {$s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll();}
    private function safeRows(string $sql,array $params): array {try{return $this->rows($sql,$params);}catch(\Throwable){return [];}}
}
