<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,SupportService};

$db=Database::connection();foreach(['003_accounts.sql','006_orders.sql','013_admin_accounts.sql','028_customer_support.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO users(email,password_hash,first_name,last_name) VALUES('buyer@example.com','x','Buyer','One')");$uid=(int)$db->lastInsertId();
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-SUP','sup1',{$uid},'paid','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$svc=new SupportService($db);
$t=$svc->create($uid,['email'=>'buyer@example.com','customer_name'=>'Buyer One','subject'=>'Order question','message'=>'Where is my order right now?','order_number'=>'FD-SUP']);
assert(str_starts_with($t['ticket_number'],'SUP-'));assert((int)$t['order_id']>0);assert(count($t['messages'])===1);assert($svc->stats()['active']===1);
$svc->update((int)$t['id'],'in_progress','high',$aid);$reply=$svc->reply((int)$t['id'],$aid,'We are checking this for you.');assert($reply['status']==='waiting_customer');assert(count($reply['messages'])===2);
$bad=false;try{$svc->create(null,['email'=>'other@example.com','customer_name'=>'Other','subject'=>'Wrong order','message'=>'This is a long enough message.','order_number'=>'FD-SUP']);}catch(InvalidArgumentException){$bad=true;}assert($bad);
assert(count($svc->forUser($uid))===1);
echo "Section 48 checks passed\n";
