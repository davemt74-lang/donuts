<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dbFile=$root.'/storage/ci-installer.sqlite';
@unlink($dbFile);@unlink($dbFile.'-wal');@unlink($dbFile.'-shm');
putenv('DB_DSN=sqlite:storage/ci-installer.sqlite');
putenv('OBSERVABILITY_ENABLED=0');
require $root.'/src/bootstrap.php';

use FudgeDonuts\{AdminAuthService,Database,InstallerService};

$installer=new InstallerService($root);
$requirements=$installer->requirements();
assert(count(array_filter($requirements,static fn(array $row): bool => !$row['ok']))===0);

$result=$installer->prepareDatabase();
assert($result['pending']===0);
assert($result['drift']===0);
assert($result['total']>=50);
assert(is_file($dbFile));

$db=Database::connection();
$auth=new AdminAuthService($db);
assert($auth->isInstalled()===false);

$id=$auth->createFirstAdmin([
    'first_name'=>'First',
    'last_name'=>'Admin',
    'email'=>'owner@example.com',
    'password'=>'InstallerPass123',
    'password_confirmation'=>'InstallerPass123',
]);
assert($id>0);
assert($auth->isInstalled()===true);
assert((string)$db->query("SELECT role FROM admin_users WHERE id=".(int)$id)->fetchColumn()==='super_admin');

$locked=false;
try{
    $auth->createFirstAdmin([
        'first_name'=>'Second',
        'last_name'=>'Admin',
        'email'=>'second@example.com',
        'password'=>'InstallerPass123',
        'password_confirmation'=>'InstallerPass123',
    ]);
}catch(RuntimeException $e){
    $locked=str_contains($e->getMessage(),'already complete');
}
assert($locked===true);

$page=(string)file_get_contents($root.'/public/install.php');
assert(str_contains($page,'Create the first administrator'));
assert(!str_contains(strtolower($page),'stripe'));
assert(!str_contains(strtolower($page),'app_key'));
assert(!str_contains(strtolower($page),'security key'));

$adminPage=(string)file_get_contents($root.'/public/admin.php');
assert(str_contains($adminPage,"Location: /install.php"));
$legacyPage=(string)file_get_contents($root.'/public/setup-admin.php');
assert(str_contains($legacyPage,"Location: /install.php"));

Database::disconnect();
@unlink($dbFile);@unlink($dbFile.'-wal');@unlink($dbFile.'-shm');
echo "Basic web installer checks passed\n";
