<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AuthService,CartService,CatalogRepository,CustomerAccountService,Database,DiscountService,PackBuilderService,PasswordResetService,PresetPackService};
$db=Database::connection();foreach(['001_catalog.sql','002_discounts.sql','003_accounts.sql','006_orders.sql','015_customer_account.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$auth=new AuthService($db);$uid=$auth->register('customer@example.com','Password1234','Test','Customer');
$reset=new PasswordResetService($db);$token=$reset->create('customer@example.com',3600);assert($token!==null);$rid=$reset->consume($token['token'],'NewPassword456','NewPassword456');assert($rid===$uid);assert($auth->login('customer@example.com','NewPassword456')!==null);
$again=false;try{$reset->consume($token['token'],'Another12345','Another12345');}catch(InvalidArgumentException){$again=true;}assert($again);
$catalog=new CatalogRepository($db);$ids=array_column($catalog->flavors(false),'id','slug');$cart=new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog));$session=[];$cart->addCustomBox($session,3,[$ids['smores']=>3],1);$summary=$cart->summary($session);
$c=['email'=>'customer@example.com','first_name'=>'Test','last_name'=>'Customer','line1'=>'1 Main','line2'=>'','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'US','phone'=>'','is_gift'=>false,'gift_message'=>''];$f=['code'=>'standard','name'=>'Standard','type'=>'shipping','price_cents'=>0];
$order=(new FudgeDonuts\OrderService($db))->create($uid,$summary,$c,$f);
$account=new CustomerAccountService($db);assert(count($account->orders($uid))===1);assert($account->order($uid,(int)$order['id'])!==null);assert($account->order($uid+1,(int)$order['id'])===null);
$cfg=json_decode((string)$order['items'][0]['configuration_json'],true);$saved=$account->saveBox($uid,'My favorite',$cfg);assert($saved>0);$box=$account->savedBox($uid,$saved);assert($box['configuration']['size']===3);
$session2=[];$account->restoreConfigurationToCart($session2,$box['configuration'],$cart);assert($cart->summary($session2)['units']===1);$account->deleteSavedBox($uid,$saved);assert($account->savedBoxes($uid)===[]);
echo "Section 22 checks passed\n";
