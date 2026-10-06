<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,TaxService};

$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/006_orders.sql'));$db->exec((string)file_get_contents($root.'/database/027_tax_configuration.sql'));
$tax=new TaxService($db);$s=$tax->settings();assert($s['automatic_tax_enabled']==='1');assert($s['tax_behavior']==='exclusive');
$tax->save(['automatic_tax_enabled'=>1,'product_tax_code'=>'txcd_99999999','checkout_notice'=>'Calculated at checkout','tax_behavior'=>'exclusive']);
$p=$tax->checkoutParams();assert($p['automatic_tax[enabled]']==='true');assert($p['line_items[0][price_data][product_data][tax_code]']==='txcd_99999999');
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents,tax_cents) VALUES('FD-TAX','tax1','paid','x@y.com','X','Y','1','Phoenix','AZ','85001','standard','Standard','shipping',1000,1080,80)");
$id=(int)$db->lastInsertId();$tax->snapshotOrder($id);$tax->recordCollected($id,80);$detail=$tax->orderDetail($id);assert((int)$detail['stripe_tax_cents']===80);assert($detail['product_tax_code']==='txcd_99999999');
$regions=$tax->byRegion();assert(count($regions)===1);assert($regions[0]['region']==='AZ');assert((int)$regions[0]['tax_cents']===80);
$bad=false;try{$tax->save(['automatic_tax_enabled'=>1,'product_tax_code'=>'bad','tax_behavior'=>'exclusive']);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 47 checks passed\n";
