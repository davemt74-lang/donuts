<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,ReviewService};

$db=Database::connection();foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','031_verified_reviews.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO users(email,password_hash,first_name,last_name) VALUES('buyer@example.com','x','Buyer','One')");$uid=(int)$db->lastInsertId();
$db->exec("INSERT INTO users(email,password_hash,first_name,last_name) VALUES('other@example.com','x','Other','One')");$other=(int)$db->lastInsertId();
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$flavor=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-R1','r1',{$uid},'paid','buyer@example.com','Buyer','One','1','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$order=(int)$db->lastInsertId();$cfg=json_encode(['items'=>[['flavor_id'=>$flavor,'name'=>'Flavor','quantity'=>3]]],JSON_THROW_ON_ERROR);
$s=$db->prepare('INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)');$s->execute([$order,'custom',3,1,1000,1000,$cfg]);
$reviews=new ReviewService($db);assert($reviews->canReview($uid,$flavor)===true);assert($reviews->canReview($other,$flavor)===false);
$id=$reviews->submit($uid,$flavor,5,'Amazing','Rich and fudgy with a great topping.');assert($id>0);assert($reviews->aggregate($flavor)['count']===0);assert($reviews->stats()['pending']===1);
$reviews->moderate($id,'approved',$aid);$agg=$reviews->aggregate($flavor);assert($agg['count']===1);assert($agg['average']===5.0);assert(count($reviews->approvedForFlavor($flavor))===1);
$reviews->submit($uid,$flavor,4,'Still great','Updated review after another box.');assert($reviews->aggregate($flavor)['count']===0);assert($reviews->stats()['pending']===1);
$blocked=false;try{$reviews->submit($other,$flavor,5,'Fake','This should never be accepted.');}catch(InvalidArgumentException){$blocked=true;}assert($blocked);
$flavorPage=(string)file_get_contents($root.'/public/flavor.php');assert(str_contains($flavorPage,'aggregateRating'));assert(str_contains($flavorPage,'Verified customer'));assert(!str_contains($flavorPage,"review['first_name']"));
echo "Section 59 checks passed\n";
