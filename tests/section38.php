<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');putenv('APP_KEY=test-audit-key-12345678901234567890');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,Database};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/013_admin_accounts.sql'));$db->exec((string)file_get_contents($root.'/database/022_admin_audit.sql'));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','super_admin')");$id=(int)$db->lastInsertId();
$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_USER_AGENT']='Section37';
$a=new AdminAuditService($db);
$a->record($id,'inventory_updated','flavor',4,'Inventory changed',['password'=>'secret','stock'=>4],['stock'=>10]);
$a->record(null,'login_failed','admin','admin@example.com','Failed sign in',[],[],'admin@example.com');
$rows=$a->recent();assert(count($rows)===2);assert($rows[1]['actor_email']==='admin@example.com');assert(!str_contains($rows[1]['before_json'],'secret'));assert($rows[1]['ip_hash']!=='');
assert(count($a->recent(10,'login_failed'))===1);$stats=$a->stats();assert($stats['today']===2);assert($stats['security']===1);assert($stats['changes']===1);
echo "Section 38 checks passed\n";
