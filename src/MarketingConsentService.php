<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class MarketingConsentService
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $secret,
        private readonly string $baseUrl
    ) {}

    public function subscribe(string $email,string $source,bool $explicitConsent): void
    {
        $email=$this->normalizeEmail($email);
        if(!$explicitConsent) throw new \InvalidArgumentException('Marketing consent is required.');
        $source=$this->cleanSource($source);
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("INSERT INTO newsletter_subscribers(email,status) VALUES(?,'subscribed') ON CONFLICT(email) DO UPDATE SET status='subscribed',updated_at=CURRENT_TIMESTAMP");
            $s->execute([$email]);
            $id=$this->subscriberId($email);
            $this->record($id,'subscribe',$source);
            $this->syncAccountPreference($email,true);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function unsubscribe(string $email,string $source='unsubscribe_link'): void
    {
        $email=$this->normalizeEmail($email);$source=$this->cleanSource($source);
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("INSERT INTO newsletter_subscribers(email,status) VALUES(?,'unsubscribed') ON CONFLICT(email) DO UPDATE SET status='unsubscribed',updated_at=CURRENT_TIMESTAMP");
            $s->execute([$email]);
            $id=$this->subscriberId($email);
            $this->record($id,'unsubscribe',$source);
            $this->syncAccountPreference($email,false);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function unsubscribeLink(string $email): string
    {
        $email=$this->normalizeEmail($email);
        if(strlen($this->secret)<32) throw new \RuntimeException('APP_KEY must be at least 32 characters for unsubscribe links.');
        $payload=$this->encode($email);
        $sig=$this->encode(hash_hmac('sha256',$payload,$this->secret,true));
        return rtrim($this->baseUrl,'/').'/unsubscribe.php?token='.rawurlencode($payload.'.'.$sig);
    }

    public function emailFromToken(string $token): ?string
    {
        if(strlen($this->secret)<32 || strlen($token)>1000 || !str_contains($token,'.')) return null;
        [$payload,$sig]=array_pad(explode('.',$token,2),2,'');
        if($payload==='' || $sig==='') return null;
        $expected=$this->encode(hash_hmac('sha256',$payload,$this->secret,true));
        if(!hash_equals($expected,$sig)) return null;
        $decoded=$this->decode($payload);
        if($decoded===null || !filter_var($decoded,FILTER_VALIDATE_EMAIL)) return null;
        return strtolower($decoded);
    }

    public function stats(): array
    {
        $stats=['subscribed'=>0,'unsubscribed'=>0,'total'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count FROM newsletter_subscribers GROUP BY status')->fetchAll() as $row){
            $status=(string)$row['status'];$count=(int)$row['count'];
            if(array_key_exists($status,$stats))$stats[$status]=$count;
            $stats['total']+=$count;
        }
        return $stats;
    }

    public function subscribers(?string $status=null,int $limit=500): array
    {
        $limit=max(1,min(1000,$limit));
        if($status!==null && $status!==''){
            if(!in_array($status,['subscribed','unsubscribed'],true)) throw new \InvalidArgumentException('Invalid newsletter status.');
            $s=$this->db->prepare("SELECT * FROM newsletter_subscribers WHERE status=? ORDER BY updated_at DESC,id DESC LIMIT {$limit}");
            $s->execute([$status]);return $s->fetchAll();
        }
        return $this->db->query("SELECT * FROM newsletter_subscribers ORDER BY updated_at DESC,id DESC LIMIT {$limit}")->fetchAll();
    }

    public function events(int $subscriberId): array
    {
        $s=$this->db->prepare('SELECT * FROM newsletter_consent_events WHERE subscriber_id=? ORDER BY id DESC');
        $s->execute([$subscriberId]);return $s->fetchAll();
    }

    private function normalizeEmail(string $email): string
    {
        $email=strtolower(trim($email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid email address.');
        return $email;
    }

    private function cleanSource(string $source): string
    {
        $source=preg_replace('/[^a-zA-Z0-9_.:-]+/','_',trim($source))??'';
        return substr($source!==''?$source:'unknown',0,80);
    }

    private function syncAccountPreference(string $email,bool $enabled): void
    {
        try{
            $s=$this->db->prepare('UPDATE users SET marketing_opt_in=?,updated_at=CURRENT_TIMESTAMP WHERE lower(email)=?');
            $s->execute([$enabled?1:0,$email]);
        }catch(\Throwable){
            // Newsletter-only installs/tests may not have the account table loaded.
        }
    }

    private function subscriberId(string $email): int
    {
        $s=$this->db->prepare('SELECT id FROM newsletter_subscribers WHERE email=?');$s->execute([$email]);
        $id=$s->fetchColumn();
        if($id===false) throw new \RuntimeException('Subscriber record was not created.');
        return (int)$id;
    }

    private function record(int $subscriberId,string $action,string $source): void
    {
        $s=$this->db->prepare('INSERT INTO newsletter_consent_events(subscriber_id,action,source) VALUES(?,?,?)');
        $s->execute([$subscriberId,$action,$source]);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    }

    private function decode(string $value): ?string
    {
        if(!preg_match('/^[A-Za-z0-9_-]+$/',$value)) return null;
        $pad=strlen($value)%4;if($pad)$value.=str_repeat('=',4-$pad);
        $decoded=base64_decode(strtr($value,'-_','+/'),true);
        return $decoded===false?null:$decoded;
    }
}
