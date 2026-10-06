<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{CatalogRepository,Database,PresetPackService,ReorderService};

$db=Database::connection();
foreach(['001_catalog.sql','002_discounts.sql','003_accounts.sql','006_orders.sql','007_inventory.sql','014_preset_defaults.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO users(email,password_hash,first_name,last_name) VALUES('buyer@example.com','x','Buyer','One')");$uid=(int)$db->lastInsertId();
$db->exec("INSERT INTO users(email,password_hash,first_name,last_name) VALUES('other@example.com','x','Other','One')");$other=(int)$db->lastInsertId();
$flavors=$db->query('SELECT id,surcharge_cents FROM flavors ORDER BY id LIMIT 3')->fetchAll();
$pack=$db->query('SELECT id,base_price_cents FROM pack_sizes WHERE size=3')->fetch();
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-REORDER','ro1',{$uid},'completed','buyer@example.com','Buyer','One','1','Phoenix','AZ','85001','standard','Standard','shipping',1299,1299)");
$orderId=(int)$db->lastInsertId();
$cfg=['type'=>'custom','size'=>3,'name'=>'3 Pack','items'=>[]];
foreach($flavors as $f)$cfg['items'][]=['flavor_id'=>(int)$f['id'],'name'=>'Flavor','quantity'=>1];
$s=$db->prepare('INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)');
$s->execute([$orderId,'custom',3,1,1299,1299,json_encode($cfg,JSON_THROW_ON_ERROR)]);
$preset=(new PresetPackService(new CatalogRepository($db)))->buildBySlug('classic-trio');
$s->execute([$orderId,'preset',3,1,1299,1299,json_encode($preset,JSON_THROW_ON_ERROR)]);

// Current catalog price and surcharge are deliberately different from the historical order.
$db->exec("UPDATE flavors SET surcharge_cents=0");
$db->exec("UPDATE pack_sizes SET base_price_cents=1999 WHERE size=3");
$db->prepare('UPDATE flavors SET surcharge_cents=200 WHERE id=?')->execute([(int)$flavors[0]['id']]);

$session=['cart'=>[],'checkout_attempt_token'=>'old','active_order_id'=>999,'fulfillment'=>['code'=>'standard']];
$result=(new ReorderService($db))->reorder($uid,$orderId,$session);
assert($result['lines_added']===2);assert($result['boxes_added']===2);assert($result['cart_total_cents']===4398);
assert(!isset($session['checkout_attempt_token'],$session['active_order_id'],$session['fulfillment']));
assert(count($session['cart'])===2);

// Wrong account cannot reorder.
$denied=false;try{(new ReorderService($db))->reorder($other,$orderId,$session);}catch(InvalidArgumentException){$denied=true;}assert($denied);

// Atomic failure: sold-out flavor leaves the existing cart untouched.
$before=$session;
$db->prepare('UPDATE flavors SET sold_out=1 WHERE id=?')->execute([(int)$flavors[1]['id']]);
$failed=false;try{(new ReorderService($db))->reorder($uid,$orderId,$session);}catch(InvalidArgumentException){$failed=true;}assert($failed);assert($session===$before);

// Atomic failure also respects current tracked inventory.
$db->prepare('UPDATE flavors SET sold_out=0 WHERE id=?')->execute([(int)$flavors[1]['id']]);
$db->prepare('UPDATE flavor_inventory SET track_inventory=1,stock_on_hand=0,reserved=0 WHERE flavor_id=?')->execute([(int)$flavors[0]['id']]);
$inventoryFailed=false;try{(new ReorderService($db))->reorder($uid,$orderId,$session);}catch(InvalidArgumentException){$inventoryFailed=true;}assert($inventoryFailed);assert($session===$before);

assert(str_contains((string)file_get_contents($root.'/public/account.php'),'Buy again'));
assert(str_contains((string)file_get_contents($root.'/public/account-order.php'),'Buy these boxes again'));
assert(str_contains((string)file_get_contents($root.'/public/reorder.php'),'ReorderService'));
echo "Section 60 checks passed\n";
