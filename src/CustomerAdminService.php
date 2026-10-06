<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CustomerAdminService
{
    public function __construct(private readonly PDO $db) {}

    public function customers(string $query='',int $limit=200): array
    {
        $limit=max(1,min(500,$limit));$query=trim($query);
        $params=[];$where='';
        if($query!==''){
            $where="WHERE lower(u.email) LIKE ? OR lower(u.first_name||' '||u.last_name) LIKE ?";
            $needle='%'.strtolower($query).'%';$params=[$needle,$needle];
        }
        $sql="SELECT u.id,u.email,u.first_name,u.last_name,u.marketing_opt_in,u.created_at,
            COUNT(o.id) order_count,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','payment_failed') THEN o.total_cents ELSE 0 END),0) lifetime_spend_cents,
            MAX(o.created_at) last_order_at
            FROM users u LEFT JOIN orders o ON o.user_id=u.id
            {$where}
            GROUP BY u.id,u.email,u.first_name,u.last_name,u.marketing_opt_in,u.created_at
            ORDER BY COALESCE(MAX(o.created_at),u.created_at) DESC,u.id DESC LIMIT {$limit}";
        $s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    public function customer(int $userId): ?array
    {
        $s=$this->db->prepare("SELECT u.id,u.email,u.first_name,u.last_name,u.marketing_opt_in,u.created_at,u.updated_at,
            COUNT(o.id) order_count,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','payment_failed') THEN o.total_cents ELSE 0 END),0) lifetime_spend_cents,
            MAX(o.created_at) last_order_at
            FROM users u LEFT JOIN orders o ON o.user_id=u.id
            WHERE u.id=? GROUP BY u.id,u.email,u.first_name,u.last_name,u.marketing_opt_in,u.created_at,u.updated_at");
        $s->execute([$userId]);$customer=$s->fetch();
        if(!$customer)return null;
        $customer['addresses']=$this->addresses($userId);
        $customer['orders']=$this->orders($userId);
        $customer['notes']=$this->notes($userId);
        $customer['marketing_status']=$this->marketingStatus((string)$customer['email']);
        $customer['privacy_events']=$this->privacyEvents($userId);
        return $customer;
    }

    public function addresses(int $userId): array
    {
        $s=$this->db->prepare('SELECT id,label,first_name,last_name,line1,line2,city,region,postal_code,country,phone,is_default,created_at FROM addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');
        $s->execute([$userId]);return $s->fetchAll();
    }

    public function orders(int $userId,int $limit=100): array
    {
        $limit=max(1,min(250,$limit));
        $s=$this->db->prepare("SELECT id,order_number,status,fulfillment_name,fulfillment_type,total_cents,currency,created_at,updated_at FROM orders WHERE user_id=? ORDER BY id DESC LIMIT {$limit}");
        $s->execute([$userId]);return $s->fetchAll();
    }

    public function addNote(int $userId,int $adminId,string $note): int
    {
        $note=trim($note);
        if($note==='')throw new \InvalidArgumentException('Support note cannot be empty.');
        if(mb_strlen($note)>4000)throw new \InvalidArgumentException('Support note is too long.');
        if(!$this->customerExists($userId))throw new \InvalidArgumentException('Customer not found.');
        $s=$this->db->prepare('INSERT INTO customer_support_notes(user_id,admin_id,note) VALUES(?,?,?)');
        $s->execute([$userId,$adminId,$note]);return (int)$this->db->lastInsertId();
    }

    public function notes(int $userId): array
    {
        $s=$this->db->prepare("SELECT n.id,n.note,n.created_at,a.email admin_email,a.first_name admin_first_name,a.last_name admin_last_name
            FROM customer_support_notes n LEFT JOIN admin_users a ON a.id=n.admin_id
            WHERE n.user_id=? ORDER BY n.id DESC");
        $s->execute([$userId]);return $s->fetchAll();
    }

    public function orderForCustomer(int $userId,int $orderId): ?array
    {
        $s=$this->db->prepare('SELECT id FROM orders WHERE id=? AND user_id=?');$s->execute([$orderId,$userId]);
        $id=$s->fetchColumn();
        if($id===false)return null;
        return (new OrderService($this->db))->find((int)$id);
    }

    private function marketingStatus(string $email): string
    {
        try{
            $s=$this->db->prepare('SELECT status FROM newsletter_subscribers WHERE lower(email)=lower(?)');$s->execute([$email]);
            return (string)($s->fetchColumn()?:'not_subscribed');
        }catch(\Throwable){return 'unknown';}
    }

    private function privacyEvents(int $userId): array
    {
        try{
            $s=$this->db->prepare('SELECT action,created_at FROM customer_privacy_events WHERE user_id=? ORDER BY id DESC LIMIT 20');$s->execute([$userId]);
            return $s->fetchAll();
        }catch(\Throwable){return [];}
    }

    private function customerExists(int $userId): bool
    {
        $s=$this->db->prepare('SELECT 1 FROM users WHERE id=?');$s->execute([$userId]);return (bool)$s->fetchColumn();
    }
}
