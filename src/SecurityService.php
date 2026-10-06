<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class SecurityService
{
    public function __construct(private readonly PDO $db) {}

    public static function applyHeaders(): void
    {
        if(headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Cross-Origin-Opener-Policy: same-origin");
    }

    public function assertLoginAllowed(string $scope,string $subject,int $maxAttempts=5,int $windowSeconds=900): void
    {
        $hash=hash('sha256',strtolower(trim($subject)));
        $s=$this->db->prepare('SELECT * FROM auth_rate_limits WHERE scope=? AND subject_hash=?');
        $s->execute([$scope,$hash]);$row=$s->fetch();
        if(!$row) return;

        $now=time();
        $blocked=$row['blocked_until']?strtotime((string)$row['blocked_until']):false;
        if($blocked && $blocked>$now) throw new \RuntimeException('Too many login attempts. Try again later.');

        $started=strtotime((string)$row['window_started_at'])?:$now;
        if($now-$started>$windowSeconds){
            $d=$this->db->prepare('DELETE FROM auth_rate_limits WHERE scope=? AND subject_hash=?');
            $d->execute([$scope,$hash]);
        }
    }

    public function recordLoginFailure(string $scope,string $subject,int $maxAttempts=5,int $windowSeconds=900): void
    {
        $hash=hash('sha256',strtolower(trim($subject)));
        $now=gmdate('Y-m-d H:i:s');
        $s=$this->db->prepare('SELECT attempts,window_started_at FROM auth_rate_limits WHERE scope=? AND subject_hash=?');
        $s->execute([$scope,$hash]);$row=$s->fetch();

        if(!$row || time()-(strtotime((string)$row['window_started_at'])?:0)>$windowSeconds){
            $u=$this->db->prepare('INSERT OR REPLACE INTO auth_rate_limits(scope,subject_hash,attempts,window_started_at,blocked_until) VALUES(?,?,1,?,NULL)');
            $u->execute([$scope,$hash,$now]);
            return;
        }

        $attempts=(int)$row['attempts']+1;
        $blocked=$attempts>=$maxAttempts?gmdate('Y-m-d H:i:s',time()+$windowSeconds):null;
        $u=$this->db->prepare('UPDATE auth_rate_limits SET attempts=?,blocked_until=? WHERE scope=? AND subject_hash=?');
        $u->execute([$attempts,$blocked,$scope,$hash]);
    }

    public function clearLoginFailures(string $scope,string $subject): void
    {
        $s=$this->db->prepare('DELETE FROM auth_rate_limits WHERE scope=? AND subject_hash=?');
        $s->execute([$scope,hash('sha256',strtolower(trim($subject)))]);
    }
}
