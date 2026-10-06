<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class AdminAuditService
{
    public function __construct(private readonly PDO $db) {}

    public function record(?int $adminId,string $action,string $entityType='',string|int|null $entityId=null,string $summary='',array $before=[],array $after=[],string $actorEmail=''): void
    {
        $action=trim($action);if($action==='') throw new \InvalidArgumentException('Audit action is required.');
        if($actorEmail==='' && $adminId){
            $s=$this->db->prepare('SELECT email FROM admin_users WHERE id=?');$s->execute([$adminId]);$actorEmail=(string)($s->fetchColumn()?:'');
        }
        $ip=(string)($_SERVER['REMOTE_ADDR']??'');
        $ipHash=$ip!==''?hash('sha256',(string)\env('APP_KEY','')."|".$ip):'';
        $ua=mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255);
        $s=$this->db->prepare('INSERT INTO admin_audit_log(admin_id,actor_email,action,entity_type,entity_id,summary,before_json,after_json,ip_hash,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $s->execute([
            $adminId,mb_substr(strtolower(trim($actorEmail)),0,190),mb_substr($action,0,80),mb_substr(trim($entityType),0,80),
            mb_substr((string)($entityId??''),0,120),mb_substr(trim($summary),0,500),
            $before?json_encode($this->sanitize($before),JSON_THROW_ON_ERROR):'',
            $after?json_encode($this->sanitize($after),JSON_THROW_ON_ERROR):'',
            $ipHash,$ua
        ]);
    }

    public function recent(int $limit=200,?string $action=null): array
    {
        $limit=max(1,min(500,$limit));
        if($action!==null && $action!==''){
            $s=$this->db->prepare("SELECT * FROM admin_audit_log WHERE action=? ORDER BY id DESC LIMIT {$limit}");
            $s->execute([$action]);return $s->fetchAll();
        }
        return $this->db->query("SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    public function stats(): array
    {
        return [
            'today'=>(int)$this->db->query("SELECT COUNT(*) FROM admin_audit_log WHERE created_at>=datetime('now','start of day')")->fetchColumn(),
            'security'=>(int)$this->db->query("SELECT COUNT(*) FROM admin_audit_log WHERE action IN ('login_failed','login_success','logout','password_changed') AND created_at>=datetime('now','-7 days')")->fetchColumn(),
            'changes'=>(int)$this->db->query("SELECT COUNT(*) FROM admin_audit_log WHERE action NOT IN ('login_failed','login_success','logout') AND created_at>=datetime('now','-7 days')")->fetchColumn(),
        ];
    }

    private function sanitize(array $data): array
    {
        $blocked=['password','password_confirmation','current_password','new_password','stripe_secret_key','stripe_webhook_secret','smtp_password'];
        $out=[];
        foreach($data as $k=>$v){
            if(in_array(strtolower((string)$k),$blocked,true)) continue;
            if(is_array($v))$out[$k]=$this->sanitize($v);
            elseif(is_scalar($v)||$v===null)$out[$k]=$v;
        }
        return $out;
    }
}
