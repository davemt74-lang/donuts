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
        try{
            $food=(new FoodComplianceService($this->db))->summary();
            $checks[]=['name'=>'food_compliance:published','ok'=>$food['incomplete']===0 && $food['published']===$food['active'],'required'=>false,'message'=>'All active flavors have complete published food compliance profiles'];
        }catch(\Throwable){
            $checks[]=['name'=>'food_compliance:published','ok'=>false,'required'=>false,'message'=>'Food compliance readiness could not be evaluated'];
        }
        try{
            $recipes=(new RecipeService($this->db))->summary();
            $checks[]=['name'=>'recipe_bom:active_recipes','ok'=>$recipes['missing_recipes']===0,'required'=>false,'message'=>'All active flavors have an active production recipe'];
        }catch(\Throwable){
            $checks[]=['name'=>'recipe_bom:active_recipes','ok'=>false,'required'=>false,'message'=>'Recipe/BOM readiness could not be evaluated'];
        }
        try{
            $procurement=(new SupplierPurchasingService($this->db))->summary();
            $checks[]=['name'=>'procurement:active_suppliers','ok'=>$procurement['active_suppliers']>0,'required'=>false,'message'=>'At least one active supplier is configured for procurement'];
            $checks[]=['name'=>'procurement:overdue_po','ok'=>$procurement['overdue_po']===0,'required'=>false,'message'=>'No overdue open purchase orders'];
        }catch(\Throwable){
            $checks[]=['name'=>'procurement:active_suppliers','ok'=>false,'required'=>false,'message'=>'Supplier procurement readiness could not be evaluated'];
        }
        try{
            $plan=(new IngredientProcurementPlanningService($this->db))->plan((int)\env('PRODUCTION_HISTORY_DAYS','28'),7,(int)\env('PRODUCTION_SAFETY_DAYS','2'));
            $checks[]=['name'=>'procurement_plan:critical_shortages','ok'=>$plan['totals']['critical']===0,'required'=>false,'message'=>'No forecast ingredient shortage is missing an approved supplier'];
            $checks[]=['name'=>'procurement_plan:recipe_coverage','ok'=>count($plan['missing_recipe_flavors'])===0,'required'=>false,'message'=>'All forecast production demand has active recipe coverage'];
        }catch(\Throwable){
            $checks[]=['name'=>'procurement_plan:critical_shortages','ok'=>false,'required'=>false,'message'=>'Ingredient procurement forecast could not be evaluated'];
        }
        try{
            $qa=(new ProductionQaService($this->db))->summary();
            $checks[]=['name'=>'production_qa:failed','ok'=>$qa['failed_qa_work_orders']===0,'required'=>false,'message'=>'No production work order has an active failed QA check'];
        }catch(\Throwable){
            $checks[]=['name'=>'production_qa:failed','ok'=>false,'required'=>false,'message'=>'Production QA readiness could not be evaluated'];
        }
        try{
            $schedule=(new ProductionSchedulingService($this->db))->summary();
            $checks[]=['name'=>'production_schedule:overdue','ok'=>$schedule['overdue']===0,'required'=>false,'message'=>'No overdue production work orders'];
        }catch(\Throwable){
            $checks[]=['name'=>'production_schedule:overdue','ok'=>false,'required'=>false,'message'=>'Production scheduling readiness could not be evaluated'];
        }
        try{
            $ingredients=(new IngredientTraceabilityService($this->db))->summary();
            $checks[]=['name'=>'ingredient_traceability:linked','ok'=>$ingredients['unlinked_batches']===0,'required'=>false,'message'=>'All production batches have at least one supplier ingredient lot linked'];
        }catch(\Throwable){
            $checks[]=['name'=>'ingredient_traceability:linked','ok'=>false,'required'=>false,'message'=>'Ingredient lot traceability readiness could not be evaluated'];
        }

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
            'public/index.php','public/robots.php','public/robots.txt','public/cart.php','public/checkout.php','public/pay.php','public/stripe-webhook.php',
            'public/admin.php','public/admin-audit.php','public/admin-operations.php','public/admin-shipping.php','public/admin-tax.php','public/admin-costs.php','public/admin-backups.php','public/setup-admin.php','public/health.php','public/sitemap.php','public/order-status.php','public/unsubscribe.php','public/admin-marketing.php','public/account-privacy.php','public/reorder.php','public/gift-cards.php','public/gift-card-success.php','public/gift-card-balance.php','public/contact.php','public/review.php','public/admin-support.php','public/admin-reviews.php','public/admin-gift-cards.php','public/admin-disputes.php','public/admin-customers.php','public/admin-customer.php','public/recover-checkout.php','public/admin-checkout-recovery.php','public/admin-food-compliance.php','public/admin-food-label.php','public/admin-batches.php','public/admin-order-batches.php','public/admin-batch-affected.csv.php','public/admin-ingredient-lots.php','public/admin-recipes.php','public/admin-suppliers.php','public/admin-purchase-orders.php','public/admin-procurement-plan.php','public/admin-production-schedule.php','public/admin-production-qa.php','public/admin-procurement-plan.csv.php','public/admin-ingredient-affected.csv.php','public/admin-packing-slip.php','public/admin-pickup-sheet.php','public/admin-fulfillment.csv.php','public/admin-production-plan.csv.php',
            'public/.htaccess','public/assets/app.css','public/assets/builder.js','public/assets/analytics.js','public/analytics.php','src/PerformanceService.php','src/HttpResponseService.php','src/GiftCardService.php','src/DisputeService.php','src/CustomerCrmService.php','src/CheckoutRecoveryService.php','src/ProductionPlanningService.php','src/ProductionSchedulingService.php','src/ProductionQaService.php','src/RecipeService.php','src/SupplierPurchasingService.php','src/IngredientProcurementPlanningService.php','src/ReorderService.php','scripts/migrate.php','scripts/post-deploy-check.php','scripts/http-smoke.php','scripts/preflight.php','scripts/backup-database.php','scripts/restore-database.php','scripts/send-notifications.php','scripts/check-operations.php','scripts/recover-reservations.php','scripts/process-checkout-recovery.php','DEPLOYMENT.md',
        ];
    }

    private function requiredTables(): array
    {
        return [
            'pack_sizes','flavors','users','addresses','orders','order_items','payment_sessions','payment_events',
            'shipping_methods','pickup_zip_codes','flavor_inventory','inventory_reservations','notification_outbox',
            'discount_rules','site_content','admin_users','password_reset_tokens','saved_boxes','order_payment_details',
            'refund_records','cancellation_requests','order_fulfillment_details','order_consents','order_payment_reconciliation','inventory_reservation_leases','newsletter_consent_events','admin_audit_log','customer_privacy_events','operational_events','scheduled_jobs','scheduled_job_runs','schema_migrations','fulfillment_settings','tax_settings','order_tax_details','support_tickets','support_messages','analytics_visitors','analytics_events','order_attribution','order_cost_snapshots','product_reviews','gift_card_purchases','gift_cards','gift_card_ledger','order_gift_card_applications','stripe_disputes','customer_admin_notes','customer_tags','checkout_recoveries','flavor_compliance_profiles','production_batches','order_batch_assignments','ingredient_lots','production_batch_ingredients','flavor_recipes','flavor_recipe_components','batch_recipe_requirements','suppliers','supplier_items','purchase_orders','purchase_order_items','purchase_receipts','production_schedule_settings','production_capacity_overrides','production_work_orders','production_quality_checks','production_waste_events','production_qa_settings',
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
