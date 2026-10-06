<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class PaymentRepository
{
    public function __construct(private readonly PDO $db) {}

    public function recordEvent(string $provider,string $eventId,string $eventType,string $payload): bool
    {
        $s=$this->db->prepare('INSERT OR IGNORE INTO payment_events(provider,provider_event_id,event_type,payload) VALUES(?,?,?,?)');
        $s->execute([$provider,$eventId,$eventType,$payload]);
        return $s->rowCount()===1;
    }

    public function createSession(?int $userId,int $amountCents,array $snapshot): array
    {
        $token=bin2hex(random_bytes(24));
        $idempotency='checkout_'.bin2hex(random_bytes(24));
        $s=$this->db->prepare('INSERT INTO payment_sessions(public_token,user_id,amount_cents,idempotency_key,snapshot) VALUES(?,?,?,?,?)');
        $s->execute([$token,$userId,$amountCents,$idempotency,json_encode($snapshot,JSON_THROW_ON_ERROR)]);
        return ['id'=>(int)$this->db->lastInsertId(),'public_token'=>$token,'idempotency_key'=>$idempotency];
    }

    public function attachProviderSession(int $id,string $providerSessionId): void
    {
        $s=$this->db->prepare("UPDATE payment_sessions SET provider_session_id=?,status='redirected',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
        $s->execute([$providerSessionId,$id]);
        if($s->rowCount()!==1) throw new \RuntimeException('Payment session changed before Stripe attachment.');
    }
}
