<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AdminAuthService,Database};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/013_admin_accounts.sql'));
$a=new AdminAuthService($db);assert($a->isInstalled()===false);
$id=$a->createFirstAdmin(['email'=>'OWNER@EXAMPLE.COM','first_name'=>'Dave','last_name'=>'Owner','password'=>'StrongPassword123','password_confirmation'=>'StrongPassword123']);
assert($a->isInstalled()===true);$user=$a->authenticate('owner@example.com','StrongPassword123');assert($user['role']==='super_admin');
$second=$a->createAdmin(['email'=>'ops@example.com','first_name'=>'Ops','last_name'=>'User','password'=>'AnotherPassword123','password_confirmation'=>'AnotherPassword123'],'fulfillment',$id);assert($second===2);
$a->setActive($second,false,$id);assert($a->authenticate('ops@example.com','AnotherPassword123')===null);
$blocked=false;try{$a->setActive($id,false,$id);}catch(InvalidArgumentException){$blocked=true;}assert($blocked);
$duplicate=false;try{$a->createFirstAdmin(['email'=>'x@y.com','first_name'=>'X','last_name'=>'Y','password'=>'StrongPassword123','password_confirmation'=>'StrongPassword123']);}catch(RuntimeException){$duplicate=true;}assert($duplicate);
echo "Section 19 checks passed\n";
