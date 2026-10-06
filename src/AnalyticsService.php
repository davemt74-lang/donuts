<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class AnalyticsService
{
    private const EVENTS=['page_view','builder_view','cart_view','checkout_view','purchase'];

    public function __construct(private readonly PDO $db) {}

    public function record(string $visitorId,string $event,string $path,array $attribution=[]): void
    {
        $visitorId=$this->visitorId($visitorId);
        if(!in_array($event,self::EVENTS,true)) throw new \InvalidArgumentException('Invalid analytics event.');
        $path=$this->path($path);
        $attr=$this->attribution($attribution);

        $this->db->beginTransaction();
        try{
            $this->upsertVisitor($visitorId,$path,$attr);
            $s=$this->db->prepare('INSERT INTO analytics_events(visitor_id,event_name,path) VALUES(?,?,?)');
            $s->execute([$visitorId,$event,$path]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function attributeOrder(int $orderId,string $visitorId): void
    {
        if($orderId<1)return;
        try{$visitorId=$this->visitorId($visitorId);}catch(\Throwable){return;}
        $s=$this->db->prepare('SELECT * FROM analytics_visitors WHERE visitor_id=?');$s->execute([$visitorId]);$v=$s->fetch();
        if(!$v)return;
        $q=$this->db->prepare("INSERT INTO order_attribution(order_id,visitor_id,landing_path,referrer_host,utm_source,utm_medium,utm_campaign,utm_content,utm_term)
          VALUES(?,?,?,?,?,?,?,?,?)
          ON CONFLICT(order_id) DO NOTHING");
        $q->execute([
            $orderId,$visitorId,(string)$v['last_landing_path'],(string)$v['last_referrer_host'],
            (string)$v['last_utm_source'],(string)$v['last_utm_medium'],(string)$v['last_utm_campaign'],
            (string)$v['last_utm_content'],(string)$v['last_utm_term']
        ]);
    }

    public function recordPurchase(int $orderId): void
    {
        $s=$this->db->prepare("SELECT a.visitor_id,o.total_cents,o.currency FROM order_attribution a JOIN orders o ON o.id=a.order_id WHERE a.order_id=?");
        $s->execute([$orderId]);$row=$s->fetch();
        if(!$row || !$row['visitor_id'])return;
        $q=$this->db->prepare("INSERT OR IGNORE INTO analytics_events(visitor_id,event_name,event_key,path,metadata_json) VALUES(?,'purchase',?,'/payment-success.php',?)");
        $q->execute([
            (string)$row['visitor_id'],
            'purchase:'.$orderId,
            json_encode(['order_id'=>$orderId,'revenue_cents'=>(int)$row['total_cents'],'currency'=>(string)$row['currency']],JSON_THROW_ON_ERROR)
        ]);
    }

    public function funnel(int $days=30): array
    {
        $days=max(1,min(365,$days));$since='-'.$days.' days';
        $eventCounts=[];
        $s=$this->db->prepare("SELECT event_name,COUNT(DISTINCT visitor_id) visitors FROM analytics_events WHERE created_at>=datetime('now',?) GROUP BY event_name");
        $s->execute([$since]);foreach($s->fetchAll() as $row)$eventCounts[(string)$row['event_name']]=(int)$row['visitors'];

        $visitors=(int)($eventCounts['page_view']??0);
        $builders=(int)($eventCounts['builder_view']??0);
        $carts=(int)($eventCounts['cart_view']??0);
        $checkout=(int)($eventCounts['checkout_view']??0);
        $purchases=(int)($eventCounts['purchase']??0);

        $r=$this->db->prepare("SELECT COUNT(DISTINCT event_key) purchases,COALESCE(SUM(CAST(json_extract(metadata_json,'$.revenue_cents') AS INTEGER)),0) revenue_cents FROM analytics_events WHERE event_name='purchase' AND created_at>=datetime('now',?)");
        $r->execute([$since]);$purchaseRow=$r->fetch()?:['purchases'=>0,'revenue_cents'=>0];

        return [
            'visitors'=>$visitors,
            'builder_visitors'=>$builders,
            'cart_visitors'=>$carts,
            'checkout_visitors'=>$checkout,
            'purchases'=>(int)$purchaseRow['purchases'],
            'revenue_cents'=>(int)$purchaseRow['revenue_cents'],
            'conversion_rate'=>$visitors>0?round(((int)$purchaseRow['purchases']/$visitors)*100,2):0.0,
        ];
    }

    public function sources(int $days=30,int $limit=25): array
    {
        $days=max(1,min(365,$days));$limit=max(1,min(100,$limit));
        $s=$this->db->prepare("SELECT
          CASE WHEN a.utm_source<>'' THEN a.utm_source WHEN a.referrer_host<>'' THEN a.referrer_host ELSE 'direct' END source,
          a.utm_medium medium,a.utm_campaign campaign,
          COUNT(*) orders,COALESCE(SUM(o.total_cents),0) revenue_cents
          FROM order_attribution a JOIN orders o ON o.id=a.order_id
          WHERE o.status NOT IN ('cancelled','payment_failed') AND o.created_at>=datetime('now',?)
          GROUP BY source,medium,campaign ORDER BY revenue_cents DESC,orders DESC LIMIT {$limit}");
        $s->execute(['-'.$days.' days']);return $s->fetchAll();
    }

    public function prune(int $days=180): int
    {
        $days=max(30,min(730,$days));$cutoff='-'.$days.' days';
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("DELETE FROM analytics_events WHERE created_at<datetime('now',?)");$s->execute([$cutoff]);$count=$s->rowCount();
            $v=$this->db->prepare("DELETE FROM analytics_visitors WHERE last_seen_at<datetime('now',?) AND visitor_id NOT IN (SELECT visitor_id FROM order_attribution WHERE visitor_id IS NOT NULL)");
            $v->execute([$cutoff]);$this->db->commit();return $count+$v->rowCount();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function upsertVisitor(string $visitorId,string $path,array $a): void
    {
        $s=$this->db->prepare("INSERT INTO analytics_visitors(
          visitor_id,first_landing_path,first_referrer_host,first_utm_source,first_utm_medium,first_utm_campaign,first_utm_content,first_utm_term,
          last_landing_path,last_referrer_host,last_utm_source,last_utm_medium,last_utm_campaign,last_utm_content,last_utm_term
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(visitor_id) DO UPDATE SET
          last_seen_at=CURRENT_TIMESTAMP,
          last_landing_path=CASE WHEN excluded.last_landing_path<>'' THEN excluded.last_landing_path ELSE analytics_visitors.last_landing_path END,
          last_referrer_host=CASE WHEN excluded.last_referrer_host<>'' THEN excluded.last_referrer_host ELSE analytics_visitors.last_referrer_host END,
          last_utm_source=CASE WHEN excluded.last_utm_source<>'' THEN excluded.last_utm_source ELSE analytics_visitors.last_utm_source END,
          last_utm_medium=CASE WHEN excluded.last_utm_medium<>'' THEN excluded.last_utm_medium ELSE analytics_visitors.last_utm_medium END,
          last_utm_campaign=CASE WHEN excluded.last_utm_campaign<>'' THEN excluded.last_utm_campaign ELSE analytics_visitors.last_utm_campaign END,
          last_utm_content=CASE WHEN excluded.last_utm_content<>'' THEN excluded.last_utm_content ELSE analytics_visitors.last_utm_content END,
          last_utm_term=CASE WHEN excluded.last_utm_term<>'' THEN excluded.last_utm_term ELSE analytics_visitors.last_utm_term END");
        $s->execute([
          $visitorId,$path,$a['referrer_host'],$a['utm_source'],$a['utm_medium'],$a['utm_campaign'],$a['utm_content'],$a['utm_term'],
          $path,$a['referrer_host'],$a['utm_source'],$a['utm_medium'],$a['utm_campaign'],$a['utm_content'],$a['utm_term']
        ]);
    }

    private function visitorId(string $id): string
    {
        $id=strtolower(trim($id));
        if(!preg_match('/^[a-f0-9]{32}$/',$id)) throw new \InvalidArgumentException('Invalid analytics visitor ID.');
        return $id;
    }

    private function path(string $path): string
    {
        $path=parse_url(trim($path),PHP_URL_PATH)?:'/';
        return mb_substr($path,0,500);
    }

    private function attribution(array $a): array
    {
        $out=[];
        foreach(['referrer_host','utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $key){
            $value=trim((string)($a[$key]??''));
            if($key==='referrer_host'){
                $value=strtolower($value);
                if($value!=='' && !preg_match('/^[a-z0-9.-]+$/',$value))$value='';
            }
            $out[$key]=mb_substr($value,0,190);
        }
        return $out;
    }
}
