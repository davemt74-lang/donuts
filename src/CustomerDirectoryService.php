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
            $where="WHERE lower(d.email) LIKE ? OR lower(COALESCE(d.first_name,'')) LIKE ? OR lower(COALESCE(d.last_name,'')) LIKE ? OR lower(COALESCE(lo.first_name,'')) LIKE ? OR lower(COALESCE(lo.last_name,'')) LIKE ?";
            $needle='%'.$query.'%';$params=[$needle,$needle,$needle,$needle,$needle];
        }
        $statusSql="'".implode("','",self::REVENUE_STATUSES)."'";
        $sql="WITH account_stats AS (
                SELECT u.id user_id,lower(u.email) email,u.first_name,u.last_name,u.created_at account_created_at,
                       COUNT(o.id) order_count,
                       COALESCE(SUM(CASE WHEN o.status IN ({$statusSql}) THEN o.total_cents ELSE 0 END),0) lifetime_cents,
                       MIN(o.created_at) first_order_at,MAX(o.created_at) last_order_at,MAX(o.id) latest_order_id
                FROM users u
                LEFT JOIN orders o ON o.user_id=u.id OR (o.user_id IS NULL AND lower(o.email)=lower(u.email))
                GROUP BY u.id,u.email,u.first_name,u.last_name,u.created_at
              ),
              guest_stats AS (
                SELECT NULL user_id,lower(o.email) email,'' first_name,'' last_name,NULL account_created_at,
                       COUNT(*) order_count,
                       COALESCE(SUM(CASE WHEN o.status IN ({$statusSql}) THEN o.total_cents ELSE 0 END),0) lifetime_cents,
                       MIN(o.created_at) first_order_at,MAX(o.created_at) last_order_at,MAX(o.id) latest_order_id
                FROM orders o
                WHERE o.user_id IS NULL
                  AND NOT EXISTS(SELECT 1 FROM users u WHERE lower(u.email)=lower(o.email))
                GROUP BY lower(o.email)
              ),
              directory AS (
                SELECT * FROM account_stats
                UNION ALL
                SELECT * FROM guest_stats
              )
              SELECT d.*,trim(COALESCE(lo.first_name,'')||' '||COALESCE(lo.last_name,'')) latest_name,
                     COALESCE(n.status,'none') marketing_status,
                     (SELECT COUNT(*) FROM support_tickets s WHERE (d.user_id IS NOT NULL AND s.user_id=d.user_id) OR (s.user_id IS NULL AND lower(s.email)=d.email)) support_count,
                     (SELECT COUNT(*) FROM support_tickets s WHERE ((d.user_id IS NOT NULL AND s.user_id=d.user_id) OR (s.user_id IS NULL AND lower(s.email)=d.email)) AND s.status IN ('open','in_progress','waiting_customer')) active_support
              FROM directory d
              LEFT JOIN orders lo ON lo.id=d.latest_order_id
              LEFT JOIN newsletter_subscribers n ON lower(n.email)=d.email
              {$where}
              ORDER BY COALESCE(d.last_order_at,d.account_created_at) DESC,d.email
              LIMIT {$limit}";
        $s=$this->db->prepare($sql);$s->execute($params);$rows=$s->fetchAll();
        foreach($rows as &$row){
            $row['customer_key']=$row['user_id']!==null?'u'.(int)$row['user_id']:'g'.(int)$row['latest_order_id'];
            $row['customer_type']=$row['user_id']!==null?'account':'guest';
            $name=trim((string)$row['first_name'].' '.(string)$row['last_name']);
            if($name==='')$name=trim((string)$row['latest_name']);
            $row['display_name']=$name!==''?$name:'Guest customer';
        }
        unset($row);return $rows;
    }

    public function stats(): array
    {
        $statusSql="'".implode("','",self::REVENUE_STATUSES)."'";
        $accounts=(int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $guests=(int)$this->db->query("SELECT COUNT(*) FROM (SELECT lower(o.email) email FROM orders o WHERE o.user_id IS NULL AND NOT EXISTS(SELECT 1 FROM users u WHERE lower(u.email)=lower(o.email)) GROUP BY lower(o.email))")->fetchColumn();
        $repeat=(int)$this->db->query("SELECT COUNT(*) FROM (
            SELECT 'u'||u.id customer_key FROM users u LEFT JOIN orders o ON o.user_id=u.id OR (o.user_id IS NULL AND lower(o.email)=lower(u.email)) GROUP BY u.id HAVING COUNT(o.id)>1
            UNION ALL
            SELECT 'g'||lower(o.email) customer_key FROM orders o WHERE o.user_id IS NULL AND NOT EXISTS(SELECT 1 FROM users u WHERE lower(u.email)=lower(o.email)) GROUP BY lower(o.email) HAVING COUNT(*)>1
        )")->fetchColumn();
        $lifetime=(int)$this->db->query("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE status IN ({$statusSql})")->fetchColumn();
        return ['customers'=>$accounts+$guests,'accounts'=>$accounts,'guests'=>$guests,'repeat'=>$repeat,'lifetime_cents'=>$lifetime];
    }

    public function profile(string $key): array
    {
        $email=$this->emailForKey($key);
        $s=$this->db->prepare('SELECT id,email,first_name,last_name,marketing_opt_in,created_at,updated_at FROM users WHERE lower(email)=?');
        $s->execute([$email]);$account=$s->fetch()?:null;

        $orders=$this->rows('SELECT id,order_number,status,total_cents,fulfillment_name,first_name,last_name,created_at FROM orders WHERE lower(email)=? ORDER BY id DESC LIMIT 100',[$email]);
        $addresses=$account?$this->rows('SELECT id,label,first_name,last_name,line1,line2,city,region,postal_code,country,phone,is_default FROM addresses WHERE user_id=? ORDER BY is_default DESC,id DESC',[(int)$account['id']]):[];
        $support=$this->safeRows('SELECT id,ticket_number,subject,status,priority,updated_at FROM support_tickets WHERE lower(email)=? ORDER BY id DESC LIMIT 100',[$email]);
        $marketing=$this->safeRow('SELECT email,status,created_at,updated_at FROM newsletter_subscribers WHERE lower(email)=?',[$email]);
        $privacy=$this->safeRows('SELECT action,details,created_at FROM customer_privacy_events WHERE email_hash=? ORDER BY id DESC LIMIT 100',[hash('sha256',$email)]);

        $lifetime=0;$revenueOrders=0;
        foreach($orders as $order){
            if(in_array((string)$order['status'],self::REVENUE_STATUSES,true)){$lifetime+=(int)$order['total_cents'];$revenueOrders++;}
        }
        $displayName=$account?trim((string)$account['first_name'].' '.(string)$account['last_name']):trim((string)($orders[0]['first_name']??'').' '.(string)($orders[0]['last_name']??''));
        if($displayName==='')$displayName='Guest customer';
        return [
            'key'=>$key,'email'=>$email,'display_name'=>$displayName,'account'=>$account,'customer_type'=>$account?'account':'guest',
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
