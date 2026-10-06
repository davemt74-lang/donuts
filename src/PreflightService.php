<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class PreflightService
{
    public function checks(): array
    {
        $checks=[];
        $checks[]=$this->check('php_version',version_compare(PHP_VERSION,'8.1.0','>='),'PHP 8.1+ required');
        foreach(['pdo','curl','mbstring','json'] as $ext){
            $checks[]=$this->check('ext_'.$ext,extension_loaded($ext),'PHP extension '.$ext.' required');
        }

        $storage=dirname(__DIR__).'/storage';
        if(!is_dir($storage)) @mkdir($storage,0775,true);
        $checks[]=$this->check('storage_writable',is_dir($storage)&&is_writable($storage),'storage/ must be writable');

        try{
            $db=Database::connection();
            $db->query('SELECT 1');
            $checks[]=$this->check('database',true,'Database connection');
            $required=['pack_sizes','flavors','users','orders','notification_outbox','auth_rate_limits','admin_users','password_reset_tokens','saved_boxes'];
            foreach($required as $table){
                $checks[]=$this->check('table_'.$table,$this->tableExists($db,$table),'Required table '.$table);
            }
        }catch(\Throwable $e){
            $checks[]=$this->check('database',false,'Database connection: '.$e->getMessage());
        }

        $production=(\env('APP_ENV','development')==='production');
        if($production){
            $checks[]=$this->check('app_key',strlen((string)\env('APP_KEY',''))>=32,'APP_KEY must be at least 32 characters');
            try {
                $adminCount=(int)$db->query("SELECT COUNT(*) FROM admin_users WHERE active=1")->fetchColumn();
                $checks[]=$this->check('admin_account',$adminCount>0,'At least one active administrator is required');
            } catch(\Throwable) {
                $checks[]=$this->check('admin_account',false,'At least one active administrator is required');
            }
            $checks[]=$this->check('stripe_secret',str_starts_with((string)\env('STRIPE_SECRET_KEY',''),'sk_'),'Stripe secret key required');
            $checks[]=$this->check('stripe_webhook',str_starts_with((string)\env('STRIPE_WEBHOOK_SECRET',''),'whsec_'),'Stripe webhook secret required');
            $checks[]=$this->check('app_url',str_starts_with((string)\env('APP_URL',''),'https://'),'Production APP_URL must use HTTPS');
        }
        return $checks;
    }

    public function healthy(): bool
    {
        foreach($this->checks() as $check) if(!$check['ok']) return false;
        return true;
    }

    private function check(string $name,bool $ok,string $message): array
    {
        return ['name'=>$name,'ok'=>$ok,'message'=>$message];
    }

    private function tableExists(PDO $db,string $table): bool
    {
        $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){
            $s=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return (bool)$s->fetchColumn();
        }
        try{$db->query('SELECT 1 FROM '.$table.' LIMIT 1');return true;}catch(\Throwable){return false;}
    }
}
