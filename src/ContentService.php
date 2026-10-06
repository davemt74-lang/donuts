<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ContentService
{
    public function __construct(private readonly PDO $db) {}

    public function get(string $key,string $default=''): string
    {
        $s=$this->db->prepare('SELECT content_value FROM site_content WHERE content_key=?');$s->execute([$key]);
        $v=$s->fetchColumn();return $v===false?$default:(string)$v;
    }

    public function set(string $key,string $value,string $type='text'): void
    {
        if(!preg_match('/^[a-z0-9_\-]{2,120}$/',$key))throw new \InvalidArgumentException('Invalid content key.');
        $s=$this->db->prepare('INSERT INTO site_content(content_key,content_value,content_type) VALUES(?,?,?) ON CONFLICT(content_key) DO UPDATE SET content_value=excluded.content_value,content_type=excluded.content_type,updated_at=CURRENT_TIMESTAMP');
        $s->execute([$key,$value,$type]);
    }

    public function all(): array
    {
        return $this->db->query('SELECT * FROM site_content ORDER BY content_key')->fetchAll();
    }

    public function subscribe(string $email,bool $explicitConsent=false,string $source='legacy'): void
    {
        (new MarketingConsentService(
            $this->db,
            (string)\env('APP_KEY',''),
            (string)\env('APP_URL','http://127.0.0.1:8080')
        ))->subscribe($email,$source,$explicitConsent);
    }
}
