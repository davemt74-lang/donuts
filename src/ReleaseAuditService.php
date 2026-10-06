<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ReleaseAuditService
{
    public function __construct(private readonly PDO $db,private readonly string $root) {}

    public function audit(): array
    {
        $checks=[];
        foreach($this->requiredFiles() as $path){
            $checks[]=$this->check('file:'.$path,is_file($this->root.'/'.$path),'Required file '.$path);
        }

        foreach($this->requiredTables() as $table){
            $checks[]=$this->check('table:'.$table,$this->tableExists($table),'Required table '.$table);
        }

        $images=['public/images/hero.png','public/images/gift-box.png','public/images/footer.png'];
        foreach($images as $path){
            $checks[]=['name'=>'asset:'.$path,'ok'=>is_file($this->root.'/'.$path),'required'=>false,'message'=>'Storefront asset '.$path];
        }

        $packs=(int)$this->db->query('SELECT COUNT(*) FROM pack_sizes WHERE active=1')->fetchColumn();
        $flavors=(int)$this->db->query('SELECT COUNT(*) FROM flavors WHERE active=1')->fetchColumn();
        $checks[]=$this->check('catalog:packs',$packs>0,'At least one active pack');
        $checks[]=$this->check('catalog:flavors',$flavors>0,'At least one active flavor');
        $shipping=(int)$this->db->query("SELECT COUNT(*) FROM shipping_methods WHERE active=1 AND type='shipping'")->fetchColumn();
        $checks[]=$this->check('fulfillment:shipping_method',$shipping>0,'At least one active shipping method');

        return $checks;
    }

    public function healthy(): bool
    {
        foreach($this->audit() as $check){
            if(($check['required']??true) && !$check['ok']) return false;
        }
        return true;
    }

    public function warnings(): array
    {
        return array_values(array_filter($this->audit(),fn($c)=>!($c['required']??true) && !$c['ok']));
    }

    private function requiredFiles(): array
    {
        return [
            'public/index.php','public/cart.php','public/checkout.php','public/pay.php','public/stripe-webhook.php',
            'public/admin.php','public/admin-audit.php','public/admin-operations.php','public/admin-shipping.php','public/admin-backups.php','public/setup-admin.php','public/health.php','public/sitemap.php','public/order-status.php','public/unsubscribe.php','public/admin-marketing.php','public/account-privacy.php',
            'scripts/migrate.php','scripts/post-deploy-check.php','scripts/preflight.php','scripts/backup-database.php','scripts/restore-database.php','scripts/send-notifications.php','scripts/check-operations.php','scripts/recover-reservations.php','DEPLOYMENT.md',
        ];
    }

    private function requiredTables(): array
    {
        return [
            'pack_sizes','flavors','users','addresses','orders','order_items','payment_sessions','payment_events',
            'shipping_methods','pickup_zip_codes','flavor_inventory','inventory_reservations','notification_outbox',
            'discount_rules','site_content','admin_users','password_reset_tokens','saved_boxes','order_payment_details',
            'refund_records','cancellation_requests','order_fulfillment_details','order_consents','order_payment_reconciliation','inventory_reservation_leases','newsletter_consent_events','admin_audit_log','customer_privacy_events','operational_events','scheduled_jobs','scheduled_job_runs','schema_migrations','fulfillment_settings',
        ];
    }

    private function tableExists(string $table): bool
    {
        $s=$this->db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);
        return (bool)$s->fetchColumn();
    }

    private function check(string $name,bool $ok,string $message): array
    {
        return ['name'=>$name,'ok'=>$ok,'required'=>true,'message'=>$message];
    }
}
