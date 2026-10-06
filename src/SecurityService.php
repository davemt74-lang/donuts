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
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self)');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Content-Security-Policy: '.self::contentSecurityPolicy());
        $production=(\env('APP_ENV','development')??'development')==='production';
        if($production && self::isHttps()) header('Strict-Transport-Security: max-age=15552000');
    }

    public static function contentSecurityPolicy(): string
    {
        return "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-src https://js.stripe.com https://hooks.stripe.com";
    }

    public static function isHttps(): bool
    {
        if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') return true;
        if((\env('TRUST_PROXY_HEADERS','0')??'0')==='1'){
            return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
        }
        return false;
    }

    public static function validateCustomerPassword(string $password): void
    {
        $length=strlen($password);
        if($length<12) throw new \InvalidArgumentException('Password must be at least 12 characters.');
        if($length>128) throw new \InvalidArgumentException('Password must be 128 characters or fewer.');
    }

    public static function initializeAuthSession(array &$session,string $prefix): void
    {
        $now=time();$session[$prefix.'_authenticated_at']=$now;$session[$prefix.'_last_activity']=$now;
    }

    public static function touchAuthSession(array &$session,string $identityKey,string $prefix,int $idleMinutes,int $maxHours,?int $now=null): bool
    {
        if(empty($session[$identityKey])) return true;
        $now??=time();$idleMinutes=max(1,$idleMinutes);$maxHours=max(1,$maxHours);
        $auth=(int)($session[$prefix.'_authenticated_at']??$now);
        $last=(int)($session[$prefix.'_last_activity']??$now);
        if(($now-$last)>($idleMinutes*60) || ($now-$auth)>($maxHours*3600)) return false;
        $session[$prefix.'_authenticated_at']=$auth;$session[$prefix.'_last_activity']=$now;return true;
    }

    public static function applyPrivateCacheHeaders(): void
    {
        if(headers_sent()) return;
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
    }

    public static function clientIdentifier(): string
    {
        $ip=trim((string)($_SERVER['REMOTE_ADDR']??'unknown'));
        return $ip!==''?$ip:'unknown';
    }

    public function assertLoginAllowed(string $scope,string $subject,int $maxAttempts=5,int $windowSeconds=900): void
    {
        $hash=hash('sha256',strtolower(trim($subject)));
        $s=$this->db->prepare('SELECT * FROM auth_rate_limits WHERE scope=? AND subject_hash=?');
        $s->execute([$scope,$hash]);$row=$s->fetch();
        if(!$row) return;

        $now=time();
        $blocked=$row['blocked_until']?strtotime((string)$row['blocked_until']):false;
        if($blocked && $blocked>$now) throw new \RuntimeException('Too many attempts. Try again later.');

        $started=strtotime((string)$row['window_started_at'])?:$now;
        if($now-$started>$windowSeconds){
            $d=$this->db->prepare('DELETE FROM auth_rate_limits WHERE scope=? AND subject_hash=?');$d->execute([$scope,$hash]);
        }
    }

    public function recordLoginFailure(string $scope,string $subject,int $maxAttempts=5,int $windowSeconds=900): void
    {
        $hash=hash('sha256',strtolower(trim($subject)));$now=gmdate('Y-m-d H:i:s');
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
