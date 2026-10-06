<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');putenv('APP_KEY=test-observability-key-123456789012345');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,ObservabilityService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','007_inventory.sql','008_notifications.sql','013_admin_accounts.sql','021_inventory_reservation_leases.sql','024_observability.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$obs=new ObservabilityService($db);
$id=$obs->record('error','test_failure','Something failed',['path'=>'/test','password'=>'secret']);
$id2=$obs->record('error','test_failure','Something failed',['path'=>'/test']);
assert($id===$id2);
$row=$db->query('SELECT * FROM operational_events WHERE id='.$id)->fetch();assert((int)$row['occurrences']===2);assert(!str_contains($row['context_json'],'secret'));
$stats=$obs->stats();assert($stats['error']===1);assert($stats['open']===1);
$health=$obs->health();assert($health['status']==='degraded');assert($health['open_errors']===1);
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','super_admin')");$adminId=(int)$db->lastInsertId();
$obs->resolve($id,$adminId);assert($obs->stats()['open']===0);assert($obs->health()['status']==='ok');
$critical=$obs->record('critical','database_integrity','Integrity failed',['path'=>'database']);assert($critical>0);assert($obs->health()['status']==='unhealthy');
$self=$obs->record('critical','operations_health','Store operational health is unhealthy.',['status'=>'unhealthy']);assert($self>0);$obs->resolve($critical,$adminId);assert($obs->health()['status']==='ok');assert($obs->resolveSystemType('operations_health')===1);
echo "Section 41 checks passed\n";
