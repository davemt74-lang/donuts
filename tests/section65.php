<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,FoodComplianceService};

$db=Database::connection();
foreach(['001_catalog.sql','013_admin_accounts.sql','036_food_compliance.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$id=(int)$db->query("SELECT id FROM flavors ORDER BY id LIMIT 1")->fetchColumn();
$svc=new FoodComplianceService($db);
$p=$svc->profile($id);assert($p['completeness']['complete']===false);assert($svc->publicProfile($id)===null);

$blocked=false;try{$svc->save($id,['published'=>1],$aid);}catch(InvalidArgumentException){$blocked=true;}assert($blocked);

$p=$svc->save($id,[
 'ingredient_statement'=>'Chocolate fudge base, sugar, cocoa.',
 'allergen_statement'=>'Contains: milk, wheat.',
 'shared_kitchen_notice'=>'Prepared in a shared kitchen with peanuts and tree nuts.',
 'storage_instructions'=>'Store cool and dry. Refrigerate after opening.',
 'shelf_life_days'=>7,
 'net_weight_oz'=>3.5,
 'label_version'=>'2026.10',
 'published'=>1,
],$aid);
assert($p['completeness']['score']===100);assert((int)$p['published']===1);assert($svc->publicProfile($id)!==null);
$summary=$svc->summary();assert($summary['published']===1);assert($summary['active']>=1);
$bad=false;try{$svc->save($id,['shelf_life_days'=>999],$aid);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 65 checks passed\n";
