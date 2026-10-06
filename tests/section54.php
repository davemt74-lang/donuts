<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,StoreSettingsService};

$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/010_content.sql'));$db->exec((string)file_get_contents($root.'/database/029_store_settings.sql'));
$svc=new StoreSettingsService($db);
assert($svc->brandName()==='Fudge Donuts');assert($svc->orderPrefix()==='FD');
$svc->save([
 'store_name'=>'Fudge Donuts Bakery','legal_name'=>'Fudge Donuts LLC',
 'contact_email'=>'hello@fudgedonuts.test','support_email'=>'help@fudgedonuts.test',
 'phone'=>'555-0100','address_line1'=>'1 Main St','address_line2'=>'',
 'city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'us',
 'timezone'=>'America/Phoenix','order_prefix'=>'fdb',
 'instagram_url'=>'https://example.com/ig','facebook_url'=>'',
]);
$s=$svc->all();assert($s['store_name']==='Fudge Donuts Bakery');assert($s['country']==='US');assert($svc->orderPrefix()==='FDB');assert($svc->supportEmail()==='help@fudgedonuts.test');
$bad=false;try{$svc->save(['order_prefix'=>'!']);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 54 checks passed\n";
