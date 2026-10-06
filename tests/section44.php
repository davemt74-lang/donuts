<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,FulfillmentSettingsService,ShippingService};

$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/005_shipping.sql'));$db->exec((string)file_get_contents($root.'/database/026_shipping_admin.sql'));
$admin=new FulfillmentSettingsService($db);$shipping=new ShippingService($db);
$methods=$admin->methods();assert(count($methods)===2);
$standard=array_values(array_filter($methods,fn($m)=>$m['code']==='standard'))[0];
$admin->saveMethod(['id'=>$standard['id'],'code'=>'standard','name'=>'Ground Shipping','type'=>'shipping','price_cents'=>999,'free_over_cents'=>5000,'description'=>'Fresh packed','eta_min_days'=>2,'eta_max_days'=>4,'checkout_message'=>'Ships cold','active'=>1,'sort_order'=>10]);
$m=$shipping->quote('standard','90210',4000);assert($m['price_cents']===999);assert($m['eta_label']==='2–4 days');assert($m['description']==='Fresh packed');
$m=$shipping->quote('standard','90210',5000);assert($m['price_cents']===0);
$admin->setPickupZip('85001-1234',true);assert($shipping->pickupAllowed('85001')===true);assert(count($shipping->methodsFor('85001',1000))===2);
$admin->setPickupZip('85001',false);assert($shipping->pickupAllowed('85001')===false);
$admin->saveSettings(['pickup_location_name'=>'Kitchen Pickup','pickup_address'=>'123 Main St','pickup_hours'=>'Fri 2–6','pickup_instructions'=>'Bring confirmation','shipping_notice'=>'Made fresh']);
$s=$shipping->settings();assert($s['pickup_location_name']==='Kitchen Pickup');assert($s['shipping_notice']==='Made fresh');
$bad=false;try{$admin->saveMethod(['code'=>'bad code','name'=>'Bad','type'=>'shipping']);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 44 checks passed\n";
