<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{ContentService,Database};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/010_content.sql'));$db->exec((string)file_get_contents($root.'/database/022_marketing_consent.sql'));$c=new ContentService($db);assert(str_contains($c->get('hero_title'),'donut'));$c->set('hero_title','Fresh Fudge Donuts');assert($c->get('hero_title')==='Fresh Fudge Donuts');$c->subscribe('TEST@example.com',true,'section14');$c->subscribe('test@example.com',true,'section14');assert((int)$db->query('SELECT COUNT(*) FROM newsletter_subscribers')->fetchColumn()===1);
echo "Section 14 checks passed\n";
