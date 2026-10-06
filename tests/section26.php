<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AuthService,Database};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/003_accounts.sql'));$auth=new AuthService($db);
$uid=$auth->register('one@example.com','Password123','One','User');
$auth->updateProfile($uid,['email'=>'updated@example.com','first_name'=>'Updated','last_name'=>'User','marketing_opt_in'=>1]);$u=$auth->user($uid);assert($u['email']==='updated@example.com');assert((int)$u['marketing_opt_in']===1);
$a1=$auth->saveAddress($uid,['label'=>'Home','first_name'=>'Updated','last_name'=>'User','line1'=>'1 Main','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001']);assert((int)$auth->addresses($uid)[0]['id']===$a1);assert((int)$auth->addresses($uid)[0]['is_default']===1);
$a2=$auth->saveAddress($uid,['label'=>'Work','first_name'=>'Updated','last_name'=>'User','line1'=>'2 Main','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85002']);
$auth->setDefaultAddress($uid,$a2);assert((int)$auth->addresses($uid)[0]['id']===$a2);
$auth->updateAddress($uid,$a2,['label'=>'Studio','first_name'=>'Updated','last_name'=>'User','line1'=>'3 Main','city'=>'Tempe','region'=>'AZ','postal_code'=>'85281','is_default'=>1]);assert($auth->addresses($uid)[0]['label']==='Studio');
$auth->deleteAddress($uid,$a2);$remaining=$auth->addresses($uid);assert(count($remaining)===1);assert((int)$remaining[0]['is_default']===1);
$blocked=false;try{$auth->deleteAddress($uid+1,$a1);$blocked=count($auth->addresses($uid))===1;}catch(Throwable){$blocked=true;}assert($blocked);
echo "Section 26 checks passed\n";
