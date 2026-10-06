<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class NotificationService
{
    public function __construct(private readonly PDO $db) {}

    public function queueOrderConfirmation(array $order): void
    {
        $url=$this->orderUrl($order);[$subject,$body,$html]=(new EmailTemplateService())->orderConfirmation($order,$url);
        $this->queue((string)$order['email'],$subject,$body,'order-confirmation:'.$order['id'],$html);
    }

    public function queueStatusUpdate(array $order,string $status): void
    {
        $url=$this->orderUrl($order);[$subject,$body,$html]=(new EmailTemplateService())->statusUpdate($order,$status,$url);
        $this->queue((string)$order['email'],$subject,$body,'order-status:'.$order['id'].':'.$status,$html);
    }

    public function queueFulfillmentUpdate(array $order,array $details): void
    {
        $url=$this->orderUrl($order);[$subject,$body,$html]=(new EmailTemplateService())->fulfillmentUpdate($order,$details,$url);
        $this->queue((string)$order['email'],$subject,$body,'fulfillment:'.$order['id'].':'.$order['status'].':'.hash('sha256',json_encode($details)),$html);
    }

    public function queueShipmentUpdate(array $order,array $shipment): void
    {
        $url=$this->orderUrl($order);
        $status=ucwords(str_replace('_',' ',(string)$shipment['status']));
        $subject=$status.' · '.$order['order_number'];
        $body="Shipment {$shipment['shipment_number']} for order {$order['order_number']} is now {$shipment['status']}.";
        if(trim((string)$shipment['carrier'])!=='')$body.="\nCarrier: ".$shipment['carrier'];
        if(trim((string)$shipment['tracking_number'])!=='')$body.="\nTracking: ".$shipment['tracking_number'];
        if(trim((string)$shipment['tracking_url'])!=='')$body.="\nTrack package: ".$shipment['tracking_url'];
        if($url!=='')$body.="\n\nOrder status: ".$url;
        $this->queue((string)$order['email'],$subject,$body,'shipment:'.$shipment['id'].':'.$shipment['status']);
    }

    public function queuePasswordReset(string $email,string $firstName,string $url,string $expiresAt): void
    {
        [$subject,$body,$html]=(new EmailTemplateService())->passwordReset($firstName,$url,$expiresAt);
        $this->queue($email,$subject,$body,'password-reset:'.hash('sha256',$url),$html);
    }

    private function orderUrl(array $order): string
    {
        try{
            return (new GuestOrderAccessService(
                (string)\env('APP_KEY',''),
                (string)\env('APP_URL','http://127.0.0.1:8080'),
                (int)\env('ORDER_TRACKING_LINK_DAYS','90')
            ))->link($order);
        }catch(\Throwable){
            return '';
        }
    }

    public function queue(string $recipient,string $subject,string $body,string $idempotencyKey,string $htmlBody=''): void
    {
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid notification recipient.');
        $s=$this->db->prepare('INSERT OR IGNORE INTO notification_outbox(recipient,subject,body,idempotency_key) VALUES(?,?,?,?)');
        $s->execute([$recipient,$subject,$body,$idempotencyKey]);
        $q=$this->db->prepare('SELECT id FROM notification_outbox WHERE idempotency_key=?');$q->execute([$idempotencyKey]);$id=$q->fetchColumn();
        if($id!==false && $htmlBody!==''){
            $h=$this->db->prepare('INSERT INTO notification_email_content(outbox_id,html_body) VALUES(?,?) ON CONFLICT(outbox_id) DO UPDATE SET html_body=excluded.html_body');
            $h->execute([(int)$id,$htmlBody]);
        }
    }

    public function pending(int $limit=25): array
    {
        $limit=max(1,min(100,$limit));
        return $this->db->query("SELECT o.*,COALESCE(c.html_body,'') html_body FROM notification_outbox o LEFT JOIN notification_email_content c ON c.outbox_id=o.id WHERE o.status='pending' AND (o.next_attempt_at IS NULL OR o.next_attempt_at<=CURRENT_TIMESTAMP) ORDER BY o.id LIMIT {$limit}")->fetchAll();
    }

    public function stats(): array
    {
        $rows=$this->db->query("SELECT status,COUNT(*) count FROM notification_outbox GROUP BY status")->fetchAll();
        $stats=['pending'=>0,'sent'=>0,'failed'=>0,'total'=>0];
        foreach($rows as $row){
            $status=(string)$row['status'];$count=(int)$row['count'];
            if(array_key_exists($status,$stats))$stats[$status]=$count;
            $stats['total']+=$count;
        }
        return $stats;
    }

    public function recent(int $limit=100,?string $status=null): array
    {
        $limit=max(1,min(500,$limit));
        if($status!==null && $status!==''){
            if(!in_array($status,['pending','sent','failed'],true)) throw new \InvalidArgumentException('Invalid notification status filter.');
            $s=$this->db->prepare("SELECT o.*,COALESCE(c.html_body,'') html_body FROM notification_outbox o LEFT JOIN notification_email_content c ON c.outbox_id=o.id WHERE o.status=? ORDER BY o.id DESC LIMIT {$limit}");
            $s->execute([$status]);return $s->fetchAll();
        }
        return $this->db->query("SELECT o.*,COALESCE(c.html_body,'') html_body FROM notification_outbox o LEFT JOIN notification_email_content c ON c.outbox_id=o.id ORDER BY o.id DESC LIMIT {$limit}")->fetchAll();
    }

    public function retry(int $id): void
    {
        $s=$this->db->prepare("UPDATE notification_outbox SET status='pending',attempts=0,next_attempt_at=NULL,last_error='' WHERE id=? AND status='failed'");
        $s->execute([$id]);
        if($s->rowCount()!==1) throw new \InvalidArgumentException('Only failed notifications can be retried.');
    }

    public function retryAllFailed(): int
    {
        $s=$this->db->prepare("UPDATE notification_outbox SET status='pending',attempts=0,next_attempt_at=NULL,last_error='' WHERE status='failed'");
        $s->execute();return $s->rowCount();
    }

    public function markSent(int $id): void
    {
        $s=$this->db->prepare("UPDATE notification_outbox SET status='sent',sent_at=CURRENT_TIMESTAMP,last_error='' WHERE id=?");$s->execute([$id]);
    }

    public function markFailed(int $id,string $error): void
    {
        $s=$this->db->prepare("UPDATE notification_outbox SET attempts=attempts+1,last_error=?,status=CASE WHEN attempts+1>=5 THEN 'failed' ELSE 'pending' END,next_attempt_at=CASE WHEN attempts+1>=5 THEN NULL ELSE datetime(CURRENT_TIMESTAMP,'+15 minutes') END WHERE id=?");
        $s->execute([substr($error,0,1000),$id]);
    }
}
