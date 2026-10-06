<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CustomerPrivacyService
{
    private const ACTIVE_ORDER_STATUSES=['pending_payment','payment_review','paid','preparing','ready','shipped'];

    public function __construct(private readonly PDO $db) {}

    public function verifyPassword(int $userId,string $password): bool
    {
        $user=$this->userWithPassword($userId);
        return $user!==null && password_verify($password,(string)$user['password_hash']);
    }

    public function exportData(int $userId): array
    {
        $user=$this->userWithPassword($userId);
        if(!$user) throw new \InvalidArgumentException('Account not found.');

        $addresses=$this->rows('SELECT * FROM addresses WHERE user_id=? ORDER BY id',[$userId]);
        $savedBoxes=$this->safeRows('SELECT id,name,box_type,pack_size,configuration_json,created_at,updated_at FROM saved_boxes WHERE user_id=? ORDER BY id',[$userId]);
        $orders=$this->rows('SELECT id,order_number,status,email,first_name,last_name,line1,line2,city,region,postal_code,country,phone,is_gift,gift_message,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,discount_cents,shipping_cents,tax_cents,total_cents,currency,created_at,updated_at FROM orders WHERE user_id=? ORDER BY id',[$userId]);
        foreach($orders as &$order){
            $order['items']=$this->rows('SELECT kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json FROM order_items WHERE order_id=? ORDER BY id',[(int)$order['id']]);
            $order['events']=$this->rows('SELECT event_type,note,created_at FROM order_events WHERE order_id=? ORDER BY id',[(int)$order['id']]);
        }
        unset($order);

        $loyaltyAccount=$this->safeRow('SELECT points_balance,reserved_points,lifetime_earned_points,lifetime_redeemed_points,created_at,updated_at FROM loyalty_accounts WHERE user_id=?',[$userId]);
        $loyaltyLedger=$this->safeRows('SELECT order_id,refund_id,entry_type,points,balance_after_points,note,created_at FROM loyalty_ledger WHERE user_id=? ORDER BY id',[$userId]);

        $marketing=$this->safeRow('SELECT id,email,status,created_at,updated_at FROM newsletter_subscribers WHERE lower(email)=?',[strtolower((string)$user['email'])]);
        if($marketing){
            $marketing['consent_events']=$this->safeRows('SELECT action,source,created_at FROM newsletter_consent_events WHERE subscriber_id=? ORDER BY id',[(int)$marketing['id']]);
        }

        $export=[
            'generated_at'=>gmdate(DATE_ATOM),
            'account'=>[
                'id'=>(int)$user['id'],
                'email'=>$user['email'],
                'first_name'=>$user['first_name'],
                'last_name'=>$user['last_name'],
                'marketing_opt_in'=>(bool)$user['marketing_opt_in'],
                'created_at'=>$user['created_at'],
                'updated_at'=>$user['updated_at']??null,
            ],
            'addresses'=>$addresses,
            'saved_boxes'=>$savedBoxes,
            'orders'=>$orders,
            'rewards'=>['account'=>$loyaltyAccount,'ledger'=>$loyaltyLedger],
            'marketing'=>$marketing,
        ];
        $this->record($userId,(string)$user['email'],'data_export',['orders'=>count($orders),'addresses'=>count($addresses)]);
        return $export;
    }

    public function closeAccount(int $userId,string $password): void
    {
        $user=$this->userWithPassword($userId);
        if(!$user || !password_verify($password,(string)$user['password_hash'])){
            throw new \InvalidArgumentException('Password is incorrect.');
        }

        $q=$this->db->prepare("SELECT order_number,status FROM orders WHERE user_id=? AND status IN ('pending_payment','payment_review','paid','preparing','ready','shipped') ORDER BY id DESC LIMIT 1");
        $q->execute([$userId]);$active=$q->fetch();
        if($active){
            throw new \RuntimeException('Account closure is unavailable while order '.$active['order_number'].' is still active.');
        }

        $email=strtolower((string)$user['email']);
        try{
            (new MarketingConsentService(
                $this->db,
                (string)\env('APP_KEY',''),
                (string)\env('APP_URL','http://127.0.0.1:8080')
            ))->unsubscribe($email,'account_closed');
        }catch(\Throwable){
            // Account closure must still remove login data if marketing tables are unavailable.
        }

        $this->db->beginTransaction();
        try{
            $this->record($userId,$email,'account_closed',['retained_order_records'=>(int)$this->count('SELECT COUNT(*) FROM orders WHERE user_id=?',[$userId])]);
            $s=$this->db->prepare('UPDATE orders SET user_id=NULL WHERE user_id=?');$s->execute([$userId]);
            $this->safeExecute('DELETE FROM password_reset_tokens WHERE user_id=?',[$userId]);
            $this->safeExecute('DELETE FROM saved_boxes WHERE user_id=?',[$userId]);
            $s=$this->db->prepare('DELETE FROM addresses WHERE user_id=?');$s->execute([$userId]);
            $s=$this->db->prepare('DELETE FROM users WHERE id=?');$s->execute([$userId]);
            if($s->rowCount()!==1) throw new \RuntimeException('Account changed before closure completed.');
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function hasActiveOrders(int $userId): bool
    {
        $placeholders=implode(',',array_fill(0,count(self::ACTIVE_ORDER_STATUSES),'?'));
        $s=$this->db->prepare("SELECT 1 FROM orders WHERE user_id=? AND status IN ({$placeholders}) LIMIT 1");
        $s->execute(array_merge([$userId],self::ACTIVE_ORDER_STATUSES));
        return (bool)$s->fetchColumn();
    }

    private function userWithPassword(int $userId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM users WHERE id=?');$s->execute([$userId]);return $s->fetch()?:null;
    }

    private function record(?int $userId,string $email,string $action,array $details=[]): void
    {
        $s=$this->db->prepare('INSERT INTO customer_privacy_events(user_id,email_hash,action,details) VALUES(?,?,?,?)');
        $s->execute([$userId,hash('sha256',strtolower(trim($email))),$action,$details?json_encode($details,JSON_THROW_ON_ERROR):'']);
    }

    private function rows(string $sql,array $params): array
    {
        $s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    private function safeRows(string $sql,array $params): array
    {
        try{return $this->rows($sql,$params);}catch(\Throwable){return [];}
    }

    private function safeRow(string $sql,array $params): ?array
    {
        try{$s=$this->db->prepare($sql);$s->execute($params);return $s->fetch()?:null;}catch(\Throwable){return null;}
    }

    private function safeExecute(string $sql,array $params): void
    {
        try{$s=$this->db->prepare($sql);$s->execute($params);}catch(\Throwable){}
    }

    private function count(string $sql,array $params): int
    {
        $s=$this->db->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();
    }
}
