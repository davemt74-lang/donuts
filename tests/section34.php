<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,OrderService,PackBuilderService,PresetPackService};

$db=Database::connection();
foreach(['001_catalog.sql','002_discounts.sql','006_orders.sql','014_preset_defaults.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$orders=new OrderService($db);
$cart=['items'=>[['quantity'=>1,'line_total_cents'=>1500,'box'=>['type'=>'custom','size'=>3,'total_cents'=>1500,'items'=>[]]]],'subtotal_cents'=>1500,'discount_cents'=>0,'discounts'=>[],'total_cents'=>1500];
$checkout=['email'=>'repeat@example.com','first_name'=>'Repeat','last_name'=>'Buyer','line1'=>'1 Main','line2'=>'','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'US','phone'=>'','is_gift'=>false,'gift_message'=>''];
$fulfillment=['code'=>'standard','name'=>'Standard','type'=>'shipping','price_cents'=>500];

$tokenA='checkout_attempt_A_1234567890abcdef1234567890';
$first=$orders->create(null,$cart,$checkout,$fulfillment,$tokenA);
$retry=$orders->create(null,$cart,$checkout,$fulfillment,$tokenA);
assert((int)$retry['id']===(int)$first['id']);

$tokenB='checkout_attempt_B_1234567890abcdef1234567890';
$repeatPurchase=$orders->create(null,$cart,$checkout,$fulfillment,$tokenB);
assert((int)$repeatPurchase['id']!==(int)$first['id']);
assert($repeatPurchase['order_number']!==$first['order_number']);

$bad=false;
try{$orders->create(null,$cart,$checkout,$fulfillment,'too-short');}catch(InvalidArgumentException){$bad=true;}
assert($bad);

$catalog=new CatalogRepository($db);
$cartService=new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog));
$session=[
  'checkout_attempt_token'=>'keep-me',
  'active_order_id'=>(int)$first['id'],
  'fulfillment'=>$fulfillment,
  'checkout'=>$checkout,
];
$cartService->invalidateCheckoutAttempt($session);
assert(!isset($session['checkout_attempt_token'],$session['active_order_id'],$session['fulfillment']));
assert(isset($session['checkout']));

echo "Section 34 checks passed\n";
