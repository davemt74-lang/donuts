<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;
use Throwable;

final class ObservabilityService
{
    private static ?string $requestId=null;

    public function __construct(private readonly PDO $db) {}

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    public static function installRuntimeHandlers(string $root): void
    {
        $logDir=$root.'/storage/logs';
        if(!is_dir($logDir)) @mkdir($logDir,0770,true);
        if(!headers_sent()) header('X-Request-ID: '.self::requestId());

        set_error_handler(static function(int $severity,string $message,string $file,int $line) use($root): bool {
            if(!(error_reporting()&$severity)) return false;
            self::captureThrowable(new \ErrorException($message,0,$severity,$file,$line),$root,'php_error');
            return false;
        });

        set_exception_handler(static function(Throwable $e) use($root): void {
            self::captureThrowable($e,$root,'uncaught_exception');
            if(PHP_SAPI==='cli'){
                fwrite(STDERR,$e->getMessage()."\n");
                return;
            }
            $production=(\env('APP_ENV','development')??'development')==='production';
            try{
                HttpResponseService::send(
                    500,
                    'Something went wrong.',
                    $production
                        ? 'We hit an unexpected problem while loading this page. Try again, and use the reference below if you contact support.'
                        : $e->getMessage(),
                    [
                        ['label'=>'Return to store','href'=>'/'],
                        ['label'=>'Contact support','href'=>'/contact.php']
                    ],
                    self::requestId()
                );
            }catch(Throwable){
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Something went wrong. Reference: '.self::requestId();
                exit;
            }
        });

        register_shutdown_function(static function() use($root): void {
            $last=error_get_last();
            if(!$last || !in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) return;
            self::captureThrowable(new \ErrorException((string)$last['message'],0,(int)$last['type'],(string)$last['file'],(int)$last['line']),$root,'fatal_error');
        });
    }

    public static function captureThrowable(Throwable $e,string $root,string $type='exception'): void
    {
        $message=mb_substr($e->getMessage(),0,1000);
        $context=[
            'request_id'=>self::requestId(),
            'exception'=>get_class($e),
            'file'=>self::relativePath($e->getFile(),$root),
            'line'=>$e->getLine(),
            'method'=>(string)($_SERVER['REQUEST_METHOD']??'CLI'),
            'path'=>self::requestPath(),
        ];
        try{
            (new self(Database::connection()))->record('error',$type,$message,$context);
        }catch(Throwable){
            self::writeFallback($root,'error',$type,$message,$context);
        }
    }

    public function record(string $severity,string $type,string $message,array $context=[]): int
    {
        if(!in_array($severity,['info','warning','error','critical'],true)) throw new \InvalidArgumentException('Invalid event severity.');
        $type=mb_substr(trim($type),0,80);$message=mb_substr(trim($message),0,1000);
        if($type===''||$message==='') throw new \InvalidArgumentException('Operational event type and message are required.');
        $clean=$this->sanitize($context);
        $fingerprint=hash('sha256',$severity.'|'.$type.'|'.$message.'|'.($clean['path']??'').'|'.($clean['exception']??''));

        $s=$this->db->prepare('SELECT id FROM operational_events WHERE fingerprint=? AND resolved_at IS NULL ORDER BY id DESC LIMIT 1');
        $s->execute([$fingerprint]);$id=$s->fetchColumn();
        if($id){
            $u=$this->db->prepare('UPDATE operational_events SET occurrences=occurrences+1,last_seen_at=CURRENT_TIMESTAMP,context_json=? WHERE id=?');
            $u->execute([$clean?json_encode($clean,JSON_THROW_ON_ERROR):'',(int)$id]);
            return (int)$id;
        }

        $i=$this->db->prepare('INSERT INTO operational_events(severity,event_type,fingerprint,message,context_json) VALUES(?,?,?,?,?)');
        $i->execute([$severity,$type,$fingerprint,$message,$clean?json_encode($clean,JSON_THROW_ON_ERROR):'']);
        return (int)$this->db->lastInsertId();
    }

    public function stats(): array
    {
        $stats=['info'=>0,'warning'=>0,'error'=>0,'critical'=>0,'open'=>0,'today'=>0];
        foreach($this->db->query("SELECT severity,COUNT(*) count FROM operational_events WHERE resolved_at IS NULL GROUP BY severity")->fetchAll() as $row){
            $stats[(string)$row['severity']]=(int)$row['count'];
            $stats['open']+=(int)$row['count'];
        }
        $stats['today']=(int)$this->db->query("SELECT COUNT(*) FROM operational_events WHERE last_seen_at>=datetime('now','start of day')")->fetchColumn();
        return $stats;
    }

    public function recent(int $limit=200,?string $severity=null,bool $openOnly=false): array
    {
        $limit=max(1,min(500,$limit));$where=[];$params=[];
        if($severity!==null&&$severity!==''){
            if(!in_array($severity,['info','warning','error','critical'],true)) throw new \InvalidArgumentException('Invalid severity filter.');
            $where[]='severity=?';$params[]=$severity;
        }
        if($openOnly)$where[]='resolved_at IS NULL';
        $sql='SELECT * FROM operational_events'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY last_seen_at DESC,id DESC LIMIT '.$limit;
        $s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    public function resolve(int $id,int $adminId): void
    {
        $s=$this->db->prepare('UPDATE operational_events SET resolved_at=CURRENT_TIMESTAMP,resolved_by=? WHERE id=? AND resolved_at IS NULL');
        $s->execute([$adminId,$id]);
        if($s->rowCount()!==1) throw new \InvalidArgumentException('Open operational event not found.');
    }

    public function health(): array
    {
        $failedMail=(int)$this->db->query("SELECT COUNT(*) FROM notification_outbox WHERE status='failed'")->fetchColumn();
        $expiredReservations=(int)$this->db->query("SELECT COUNT(*) FROM inventory_reservation_leases WHERE status='active' AND expires_at<=CURRENT_TIMESTAMP")->fetchColumn();
        $paymentReview=(int)$this->db->query("SELECT COUNT(*) FROM orders WHERE status='payment_review'")->fetchColumn();
        $critical=(int)$this->db->query("SELECT COUNT(*) FROM operational_events WHERE resolved_at IS NULL AND severity='critical' AND event_type<>'operations_health'")->fetchColumn();
        $errors=(int)$this->db->query("SELECT COUNT(*) FROM operational_events WHERE resolved_at IS NULL AND severity='error' AND event_type<>'operations_health'")->fetchColumn();
        $jobHealth=['stale'=>0,'failing'=>0,'critical'=>0];
        try{$jobHealth=(new JobMonitorService($this->db))->health();}catch(\Throwable){}
        $status=($critical>0 || (int)$jobHealth['critical']>0)?'unhealthy':(($failedMail+$expiredReservations+$paymentReview+$errors+(int)$jobHealth['stale']+(int)$jobHealth['failing'])>0?'degraded':'ok');
        return ['status'=>$status,'failed_email'=>$failedMail,'expired_reservations'=>$expiredReservations,'payment_review'=>$paymentReview,'open_errors'=>$errors,'open_critical'=>$critical,'stale_jobs'=>(int)$jobHealth['stale'],'failing_jobs'=>(int)$jobHealth['failing'],'critical_jobs'=>(int)$jobHealth['critical']];
    }

    public function resolveSystemType(string $type): int
    {
        $s=$this->db->prepare("UPDATE operational_events SET resolved_at=CURRENT_TIMESTAMP WHERE event_type=? AND resolved_at IS NULL");
        $s->execute([$type]);return $s->rowCount();
    }

    public function prune(int $days=90): int
    {
        $days=max(7,min(730,$days));
        $s=$this->db->prepare("DELETE FROM operational_events WHERE resolved_at IS NOT NULL AND last_seen_at < datetime('now',?)");
        $s->execute(['-'.$days.' days']);return $s->rowCount();
    }

    private function sanitize(array $context): array
    {
        $blocked=['password','token','secret','authorization','cookie','stripe_secret_key','stripe_webhook_secret','smtp_password'];
        $out=[];
        foreach($context as $k=>$v){
            if(in_array(strtolower((string)$k),$blocked,true)) continue;
            if(is_array($v))$out[$k]=$this->sanitize($v);
            elseif(is_scalar($v)||$v===null)$out[$k]=is_string($v)?mb_substr($v,0,1000):$v;
        }
        return $out;
    }

    private static function writeFallback(string $root,string $severity,string $type,string $message,array $context): void
    {
        $dir=$root.'/storage/logs';if(!is_dir($dir))@mkdir($dir,0770,true);
        $line=json_encode(['time'=>gmdate('c'),'severity'=>$severity,'type'=>$type,'message'=>$message,'context'=>$context],JSON_UNESCAPED_SLASHES).PHP_EOL;
        @file_put_contents($dir.'/app.log',$line,FILE_APPEND|LOCK_EX);
    }

    private static function relativePath(string $path,string $root): string
    {
        return str_starts_with($path,$root)?ltrim(substr($path,strlen($root)),'/\\'):basename($path);
    }

    private static function requestPath(): string
    {
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        if($uri==='') return '';
        $path=parse_url($uri,PHP_URL_PATH);return is_string($path)?mb_substr($path,0,500):'';
    }
}
