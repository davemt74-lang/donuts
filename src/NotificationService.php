<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class NotificationService
{
    public function __construct(private readonly PDO $db) {}

    public function queueOrderConfirmation(array $order): void
    {
        $subject='Your Fudge Donuts order '.$order['order_number'];
        $body="Thanks for your order, {$order['first_name']}.\n\nOrder: {$order['order_number']}\nTotal: ".\money((int)$order['total_cents'])."\nFulfillment: {$order['fulfillment_name']}\n\nWe’ll let you know when it moves to the next step.";
        $this->queue((string)$order['email'],$subject,$body,'order-confirmation:'.$order['id']);
    }

    public function queueStatusUpdate(array $order,string $status): void
    {
        $label=ucwords(str_replace('_',' ',$status));
        $subject='Order '.$order['order_number'].': '.$label;
        $body="Your Fudge Donuts order {$order['order_number']} is now {$label}.";
        $this->queue((string)$order['email'],$subject,$body,'order-status:'.$order['id'].':'.$status);
    }

    public function queuePasswordReset(string $email,string $firstName,string $url,string $expiresAt): void
    {
        $subject='Reset your Fudge Donuts password';
        $name=trim($firstName)!==''?$firstName:'there';
        $body="Hi {$name},\n\nUse this secure link to reset your Fudge Donuts password:\n{$url}\n\nThis link expires at {$expiresAt} UTC. If you did not request a reset, you can ignore this email.";
        $this->queue($email,$subject,$body,'password-reset:'.hash('sha256',$url));
    }

    public function queue(string $recipient,string $subject,string $body,string $idempotencyKey): void
    {
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid notification recipient.');
        $s=$this->db->prepare('INSERT OR IGNORE INTO notification_outbox(recipient,subject,body,idempotency_key) VALUES(?,?,?,?)');
        $s->execute([$recipient,$subject,$body,$idempotencyKey]);
    }

    public function pending(int $limit=25): array
    {
        $limit=max(1,min(100,$limit));
        return $this->db->query("SELECT * FROM notification_outbox WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=CURRENT_TIMESTAMP) ORDER BY id LIMIT {$limit}")->fetchAll();
    }

    public function markSent(int $id): void
    {
        $s=$this->db->prepare("UPDATE notification_outbox SET status='sent',sent_at=CURRENT_TIMESTAMP,last_error='' WHERE id=?");$s->execute([$id]);
    }

    public function markFailed(int $id,string $error): void
    {
        $s=$this->db->prepare("UPDATE notification_outbox SET attempts=attempts+1,last_error=?,next_attempt_at=datetime(CURRENT_TIMESTAMP,'+15 minutes') WHERE id=?");
        $s->execute([substr($error,0,1000),$id]);
    }
}
