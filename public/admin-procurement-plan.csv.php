<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,FulfillmentOperationsService,IngredientProcurementPlanningService};
require_admin_roles(['super_admin','admin']);

$history=max(7,min(180,(int)($_GET['history']??28)));$days=max(1,min(60,(int)($_GET['days']??7)));$safety=max(0,min(30,(int)($_GET['safety']??2)));
$plan=(new IngredientProcurementPlanningService(Database::connection()))->plan($history,$days,$safety);
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="ingredient-procurement-plan-'.gmdate('Ymd-His').'.csv"');
$out=fopen('php://output','wb');fputcsv($out,['Ingredient','Unit','Required','Usable Stock','Draft PO','On Order','Net Shortage','Recommended Supplier','Suggested Order','Unit Cost Cents','Lead Time Days','Risk']);
foreach($plan['rows'] as $r)fputcsv($out,array_map(fn($v)=>FulfillmentOperationsService::csvCell((string)$v),[
 $r['ingredient_name'],$r['quantity_unit'],$r['required_quantity'],$r['available_quantity'],$r['draft_po_quantity'],$r['ordered_po_quantity'],$r['net_shortage'],$r['recommended_supplier_name'],$r['suggested_order_quantity'],$r['unit_cost_cents']??'',$r['lead_time_days']??'',$r['risk']
]));
fclose($out);exit;
