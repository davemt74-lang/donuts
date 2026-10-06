<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{CheckoutService,ContentService,Database,OrderService};
$db=Database::connection();foreach(['001_catalog.sql','002_discounts.sql','003_accounts.sql','006_orders.sql','010_content.sql','018_policy_consents.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$c=new ContentService($db);assert($c->get('terms_version')==='2026-10');
$svc=new CheckoutService();$bad=false;try{$svc->validate(['email'=>'x@y.com','first_name'=>'X','last_name'=>'Y','line1'=>'1','city'=>'P','region'=>'AZ','postal_code'=>'85001']);}catch(InvalidArgumentException){$bad=true;}assert($bad);
$v=$svc->validate(['email'=>'x@y.com','first_name'=>'X','last_name'=>'Y','line1'=>'1','city'=>'P','region'=>'AZ','postal_code'=>'85001','terms_accepted'=>1]);assert($v['terms_accepted']===true);
$cart=['items'=>[['quantity'=>1,'line_total_cents'=>1000,'box'=>['type'=>'custom','size'=>3,'total_cents'=>1000,'items'=>[]]]],'subtotal_cents'=>1000,'discount_cents'=>0,'discounts'=>[],'total_cents'=>1000];
$f=['code'=>'standard','name'=>'Standard','type'=>'shipping','price_cents'=>0];
$o=(new OrderService($db))->create(null,$cart,$v,$f);$consent=$db->query('SELECT * FROM order_consents WHERE order_id='.(int)$o['id'])->fetch();assert((int)$consent['terms_accepted']===1);assert($consent['terms_version']==='2026-10');
echo "Section 28 checks passed\n";
