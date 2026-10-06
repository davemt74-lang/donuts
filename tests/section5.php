<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AuthService,Database};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/003_accounts.sql'));$auth=new AuthService($db);
$id=$auth->register('TEST@Example.com','very-secure-password','Dave','E');
assert($id===1);assert($auth->login('test@example.com','very-secure-password')['email']==='test@example.com');assert($auth->login('test@example.com','wrong')===null);
$address=$auth->saveAddress($id,['first_name'=>'Dave','last_name'=>'E','line1'=>'1 Main St','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','is_default'=>1]);
assert($address===1);assert(count($auth->addresses($id))===1);assert((int)$auth->addresses($id)[0]['is_default']===1);
$bad=false;try{$auth->register('bad','short');}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 5 checks passed\n";
