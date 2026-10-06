<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CustomerDirectoryService
{
    private const REVENUE_STATUSES=['paid','preparing','ready','shipped','delivered','completed'];

    public function __construct(private readonly PDO $db) {}

    public function customers(string $query='',int $limit=200): array
    {
        $limit=max(1,min(500,$limit));$query=mb_strtolower(trim($query));
        $params=[];$where='';
        if($query!==''){
            $where="WHERE lower(e.email) LIKE ? OR lower(COALESCE(u.first_name,'')) LIKE ? OR lower(COALESCE(u.last_name,'')) LIKE ? OR lower(COALESCE(o.latest_name,'')) LIKE ?";
            $needle='%'.$query.'%';$params=[$needle,$needle,$needle,$needle];
        }
        $statusSql="'".implode("','",self::REVENUE_STATUSES)."'";
        $sql="WITH emails AS (
                SELECT lower(email) email FROM users
                UNION
                SELECT lower(email) email FROM orders
              ),
              order_stats AS (
                SELECT lower(email) email,
                       COUNT(*) order_count,
                       SUM(CASE WHEN status IN ({$statusSql}) THEN total_cents ELSE 0 END) lifetime_cents,
                       MIN(created_at) first_order_at,
                       MAX(created_at) last_order_at,
                       MAX(id) latest_order_id,
                       MAX(trim(first_name||' '||last_name)) latest_name
                FROM orders GROUP BY lower(email)
              ),
              support_stats AS (
                SELECT lower(email) email,
                       SUM(CASE WHEN status IN ('open','in_progress','waiting_customer') THEN 1 ELSE 0 END) active_support,
                       COUNT(*) support_count
                FROM support_tickets GROUP BY lower(email)
              )
              SELECT e.email,u.id user_id,u.first_name,u.last_name,u.created_at account_created_at,
                     COALESCE(o.order_count,0) order_count,COALESCE(o.lifetime_cents,0) lifetime_cents,
                     o.first_order_at,o.last_order_at,o.latest_order_id,o.latest_name,
                     COALESCE(s.active_support,0) active_support,COALESCE(s.support_count,0) support_count
              FROM emails e
              LEFT JOIN users u ON lower(u.email)=e.email
              LEFT JOIN order_stats o ON o.email=e.email
              LEFT JOIN support_stats s ON s.email=e.email
              {$where}
              ORDER BY COALESCE(o.last_order_at,u.created_at) DESC,e.email
              LIMIT {$limit}";
        $s=$this->db->prepare($sql);$s->execute($params);$rows=$s->fetchAll();
        foreach($rows as &$row){
            $row['customer_key']=$row['user_id']!==null?'u'.(int)$row['user_id']:'g'.(int)$row['latest_order_id'];
            $row['customer_type']=$row['user_id']!==null?'account':'guest';
            $name=trim((string)$row['first_name'].' '.(string)$row['last_name']);
            if($name==='')$name=trim((string)$row['latest_name']);
            $row['display_name']=$name!==''?$name:'Guest customer';
            $row['marketing_status']=$this->marketingStatus((string)$row['email']);
        }
        unset($row);return $rows;
    }

    public function stats(): array
    {
        $rows=$this->customers('',500);
        $stats=['customers'=>count($rows),'accounts'=>0,'guests'=>0,'repeat'=>0,'lifetime_cents'=>0];
        foreach($rows as $row){
            $stats[$row['customer_type']==='account'?'accounts':'guests']++;
            if((int)$row['order_count']>1)$stats['repeat']++;
            $stats['lifetime_cents']+=(int)$row['lifetime_cents'];
        }
        return $stats;
    }

    public function profile(string $key): array
    {
        $email=$this->emailForKey($key);
        $s=$this->db->prepare('SELECT id,email,first_name,last_name,marketing_opt_in,created_at,updated_at FROM users WHERE lower(email)=?');
        $s->execute([$email]);$account=$s->fetch()?:null;

        $orders=$this->rows('SELECT id,order_number,status,total_cents,fulfillment_name,created_at FROM orders WHERE lower(email)=? ORDER BY id DESC LIMIT 100',[$email]);
        $addresses=$account?$this->rows('SELECT id,label,first_name,last_name,line1,line2,city,region,postal_code,country,phone,is_default FROM addresses WHERE user_id=? ORDER BY is_default DESC,id DESC',[(int)$account['id']]):[];
        $support=$this->safeRows('SELECT id,ticket_number,subject,status,priority,updated_at FROM support_tickets WHERE lower(email)=? ORDER BY id DESC LIMIT 100',[$email]);
        $marketing=$this->safeRow('SELECT email,status,created_at,updated_at FROM newsletter_subscribers WHERE lower(email)=?',[$email]);
        $privacy=$this->safeRows('SELECT action,details,created_at FROM customer_privacy_events WHERE email_hash=? ORDER BY id DESC LIMIT 100',[hash('sha256',$email)]);

        $lifetime=0;$revenueOrders=0;
        foreach($orders as $order){
            if(in_array((string)$order['status'],self::REVENUE_STATUSES,true)){$lifetime+=(int)$order['total_cents'];$revenueOrders++;}
        }
        return [
            'key'=>$key,'email'=>$email,'account'=>$account,'customer_type'=>$account?'account':'guest',
            'orders'=>$orders,'addresses'=>$addresses,'support'=>$support,'marketing'=>$marketing,'privacy'=>$privacy,
            'order_count'=>count($orders),'revenue_order_count'=>$revenueOrders,'lifetime_cents'=>$lifetime,
            'last_order_at'=>$orders[0]['created_at']??null,
        ];
    }

    private function emailForKey(string $key): string
    {
        if(preg_match('/^u(\d+)$/',$key,$m)){
            $s=$this->db->prepare('SELECT lower(email) FROM users WHERE id=?');$s->execute([(int)$m[1]]);
        }elseif(preg_match('/^g(\d+)$/',$key,$m)){
            $s=$this->db->prepare('SELECT lower(email) FROM orders WHERE id=?');$s->execute([(int)$m[1]]);
        }else throw new \InvalidArgumentException('Invalid customer reference.');
        $email=$s->fetchColumn();
        if($email===false) throw new \InvalidArgumentException('Customer not found.');
        return (string)$email;
    }

    private function marketingStatus(string $email): string
    {
        try{$s=$this->db->prepare('SELECT status FROM newsletter_subscribers WHERE lower(email)=?');$s->execute([strtolower($email)]);return (string)($s->fetchColumn()?:'none');}
        catch(\Throwable){return 'none';}
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
}
