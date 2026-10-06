<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,SecurityService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/012_security.sql'));
$s=new SecurityService($db);
for($i=0;$i<4;$i++)$s->recordLoginFailure('account','user@example.com');
$s->assertLoginAllowed('account','user@example.com');
$s->recordLoginFailure('account','user@example.com');
$blocked=false;try{$s->assertLoginAllowed('account','user@example.com');}catch(RuntimeException){$blocked=true;}assert($blocked);
$s->clearLoginFailures('account','user@example.com');$s->assertLoginAllowed('account','user@example.com');
echo "Section 17 checks passed\n";
