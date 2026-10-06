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
        foreach(['pdo','curl','mbstring','json','openssl'] as $ext){
            $checks[]=$this->check('ext_'.$ext,extension_loaded($ext),'PHP extension '.$ext.' required');
        }

        $storage=dirname(__DIR__).'/storage';
        if(!is_dir($storage)) @mkdir($storage,0775,true);
        $checks[]=$this->check('storage_writable',is_dir($storage)&&is_writable($storage),'storage/ must be writable');

        try{
            $db=Database::connection();
            $db->query('SELECT 1');
            $checks[]=$this->check('database',true,'Database connection');
            if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
                $busy=(int)$db->query('PRAGMA busy_timeout')->fetchColumn();
                $journal=strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn());
                $cache=(int)$db->query('PRAGMA cache_size')->fetchColumn();
                $checks[]=$this->check('sqlite_busy_timeout',$busy>=5000,'SQLite busy timeout must be at least 5000ms');
                $checks[]=$this->check('sqlite_cache_size',$cache<=-20000,'SQLite cache size must reserve at least about 20MB');
                if((string)\env('DB_DSN','')!=='sqlite::memory:'){
                    $checks[]=$this->check('sqlite_wal',$journal==='wal','File-backed SQLite must use WAL journal mode');
                }
            }
            $required=['pack_sizes','flavors','users','orders','notification_outbox','notification_email_content','auth_rate_limits','admin_users','password_reset_tokens','saved_boxes','order_payment_details','refund_records','cancellation_requests','order_fulfillment_details','order_consents','order_payment_reconciliation','inventory_reservation_leases','newsletter_consent_events','admin_audit_log','customer_privacy_events','operational_events','scheduled_jobs','scheduled_job_runs','schema_migrations','shipping_methods','pickup_zip_codes','fulfillment_settings','tax_settings','order_tax_details','support_tickets','support_messages','analytics_visitors','analytics_events','order_attribution','order_cost_snapshots','product_reviews','gift_card_purchases','gift_cards','gift_card_ledger','order_gift_card_applications','stripe_disputes','customer_admin_notes','customer_tags','checkout_recoveries','production_batches','production_batch_flavors','order_production_batches'];
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
            $trustProxy=(string)\env('TRUST_PROXY_HEADERS','0');
            $checks[]=$this->check('trust_proxy_headers',in_array($trustProxy,['0','1'],true),'TRUST_PROXY_HEADERS must be 0 or 1');
            $adminIdle=(int)\env('ADMIN_SESSION_IDLE_MINUTES','30');$adminMax=(int)\env('ADMIN_SESSION_MAX_HOURS','12');
            $userIdle=(int)\env('USER_SESSION_IDLE_MINUTES','120');$userMax=(int)\env('USER_SESSION_MAX_HOURS','168');
            $checks[]=$this->check('admin_session_idle',$adminIdle>=5 && $adminIdle<=240,'ADMIN_SESSION_IDLE_MINUTES must be between 5 and 240');
            $checks[]=$this->check('admin_session_max',$adminMax>=1 && $adminMax<=24,'ADMIN_SESSION_MAX_HOURS must be between 1 and 24');
            $checks[]=$this->check('user_session_idle',$userIdle>=15 && $userIdle<=1440,'USER_SESSION_IDLE_MINUTES must be between 15 and 1440');
            $checks[]=$this->check('user_session_max',$userMax>=1 && $userMax<=720,'USER_SESSION_MAX_HOURS must be between 1 and 720');
            $holdMinutes=(int)\env('CHECKOUT_HOLD_MINUTES','30');
            $checks[]=$this->check('checkout_hold_minutes',$holdMinutes>=30 && $holdMinutes<=120,'CHECKOUT_HOLD_MINUTES must be between 30 and 120');
            $recoveryDays=(int)\env('CHECKOUT_RECOVERY_DAYS','7');$recoveryHours=(int)\env('CHECKOUT_RECOVERY_REMINDER_HOURS','2');$recoveryInterval=(int)\env('JOB_CHECKOUT_RECOVERY_INTERVAL_MINUTES','60');
            $checks[]=$this->check('checkout_recovery_days',$recoveryDays>=1 && $recoveryDays<=30,'CHECKOUT_RECOVERY_DAYS must be between 1 and 30');
            $checks[]=$this->check('checkout_recovery_reminder_hours',$recoveryHours>=1 && $recoveryHours<=72,'CHECKOUT_RECOVERY_REMINDER_HOURS must be between 1 and 72');
            $checks[]=$this->check('job_checkout_recovery_interval',$recoveryInterval>=15 && $recoveryInterval<=1440,'JOB_CHECKOUT_RECOVERY_INTERVAL_MINUTES must be between 15 and 1440');
            $trackingDays=(int)\env('ORDER_TRACKING_LINK_DAYS','90');
            $checks[]=$this->check('order_tracking_link_days',$trackingDays>=1 && $trackingDays<=365,'ORDER_TRACKING_LINK_DAYS must be between 1 and 365');
            $backupDays=(int)\env('BACKUP_RETENTION_DAYS','14');$backupMax=(int)\env('BACKUP_MAX_FILES','60');
            $checks[]=$this->check('backup_retention_days',$backupDays>=1 && $backupDays<=365,'BACKUP_RETENTION_DAYS must be between 1 and 365');
            $checks[]=$this->check('backup_max_files',$backupMax>=2 && $backupMax<=500,'BACKUP_MAX_FILES must be between 2 and 500');
            $backupDir=dirname(__DIR__).'/storage/backups';if(!is_dir($backupDir))@mkdir($backupDir,0770,true);
            $checks[]=$this->check('backup_dir',is_dir($backupDir)&&is_writable($backupDir),'storage/backups must be writable');
            $obsDays=(int)\env('OBSERVABILITY_RETENTION_DAYS','90');
            $checks[]=$this->check('observability_retention_days',$obsDays>=7 && $obsDays<=730,'OBSERVABILITY_RETENTION_DAYS must be between 7 and 730');
            $logDir=dirname(__DIR__).'/storage/logs';if(!is_dir($logDir))@mkdir($logDir,0770,true);
            $checks[]=$this->check('observability_log_dir',is_dir($logDir)&&is_writable($logDir),'storage/logs must be writable');
            $alertEmail=trim((string)\env('ALERT_EMAIL',''));
            $checks[]=$this->check('alert_email',$alertEmail===''||filter_var($alertEmail,FILTER_VALIDATE_EMAIL)!==false,'ALERT_EMAIL must be blank or a valid email address');
            foreach([
                'job_notifications_interval'=>(int)\env('JOB_NOTIFICATIONS_INTERVAL_MINUTES','5'),
                'job_reservations_interval'=>(int)\env('JOB_RESERVATIONS_INTERVAL_MINUTES','5'),
                'job_operations_interval'=>(int)\env('JOB_OPERATIONS_INTERVAL_MINUTES','5'),
                'job_backup_interval'=>(int)\env('JOB_BACKUP_INTERVAL_MINUTES','1440'),
            ] as $name=>$minutes){
                $checks[]=$this->check($name,$minutes>=1 && $minutes<=10080,strtoupper(str_replace('_',' ',$name)).' must be between 1 and 10080 minutes');
            }
            try{
                $tax=(new TaxService($db))->settings();
                $checks[]=$this->check('tax_behavior',($tax['tax_behavior']??'')==='exclusive','Tax behavior must remain exclusive for payment reconciliation');
                $code=(string)($tax['product_tax_code']??'');
                $checks[]=$this->check('tax_code',$code===''||preg_match('/^txcd_\d+$/',$code)===1,'Stripe product tax code must be blank or txcd_########');
            }catch(\Throwable $e){
                $checks[]=$this->check('tax_settings',false,'Tax settings: '.$e->getMessage());
            }
            $productionHistory=(int)\env('PRODUCTION_HISTORY_DAYS','28');
            $productionSafety=(int)\env('PRODUCTION_SAFETY_DAYS','2');
            $checks[]=$this->check('production_history_days',$productionHistory>=7 && $productionHistory<=180,'PRODUCTION_HISTORY_DAYS must be between 7 and 180');
            $checks[]=$this->check('production_safety_days',$productionSafety>=0 && $productionSafety<=30,'PRODUCTION_SAFETY_DAYS must be between 0 and 30');
            $analyticsEnabled=(string)\env('ANALYTICS_ENABLED','0');
            $checks[]=$this->check('analytics_enabled',in_array($analyticsEnabled,['0','1'],true),'ANALYTICS_ENABLED must be 0 or 1');
            $analyticsDays=(int)\env('ANALYTICS_RETENTION_DAYS','180');
            $checks[]=$this->check('analytics_retention_days',$analyticsDays>=30 && $analyticsDays<=730,'ANALYTICS_RETENTION_DAYS must be between 30 and 730');
            $giftAmounts=array_values(array_filter(array_map('intval',explode(',',(string)\env('GIFT_CARD_AMOUNTS_CENTS','2500,5000,10000'))),fn($v)=>$v>=500&&$v<=100000));
            $checks[]=$this->check('gift_card_amounts',count($giftAmounts)>0,'GIFT_CARD_AMOUNTS_CENTS must include at least one denomination between 500 and 100000 cents');
            $supportEmail=trim((string)\env('SUPPORT_EMAIL',''));
            $checks[]=$this->check('support_email',$supportEmail===''||filter_var($supportEmail,FILTER_VALIDATE_EMAIL)!==false,'SUPPORT_EMAIL must be blank or a valid email address');
            $transport=strtolower((string)\env('MAIL_TRANSPORT','log'));
            $checks[]=$this->check('mail_transport',in_array($transport,['smtp','mail'],true),'Production MAIL_TRANSPORT must be smtp or mail');
            $checks[]=$this->check('mail_from',filter_var((string)\env('MAIL_FROM',''),FILTER_VALIDATE_EMAIL)!==false,'MAIL_FROM must be a valid email address');
            if($transport==='smtp'){
                $checks[]=$this->check('smtp_host',trim((string)\env('SMTP_HOST',''))!=='','SMTP_HOST is required for SMTP delivery');
            }
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
