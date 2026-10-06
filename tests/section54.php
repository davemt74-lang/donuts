<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$tmp=$root.'/storage/section54.sqlite';
@unlink($tmp);@unlink($tmp.'-wal');@unlink($tmp.'-shm');
putenv('DB_DSN=sqlite:storage/section54.sqlite');
require $root.'/src/bootstrap.php';

use FudgeDonuts\Database;

$db=Database::connection();
assert((int)$db->query('PRAGMA busy_timeout')->fetchColumn()>=5000);
assert(strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn())==='wal');
assert((int)$db->query('PRAGMA cache_size')->fetchColumn()<=-20000);
assert(in_array((int)$db->query('PRAGMA synchronous')->fetchColumn(),[1,2],true));

$ht=(string)file_get_contents($root.'/public/.htaccess');
assert(str_contains($ht,'mod_deflate.c'));
assert(str_contains($ht,'max-age=31536000'));
assert(str_contains($ht,'max-age=604800'));
assert(str_contains($ht,'immutable'));

$raw=[];
foreach(glob($root.'/public/*.php')?:[] as $file){
    $content=(string)file_get_contents($file);
    if(str_contains($content,'href="/assets/app.css"')) $raw[]=basename($file);
}
assert($raw===[],'Unversioned shared CSS remains in: '.implode(', ',$raw));

$url=asset_url('/assets/app.css');
assert(str_starts_with($url,'/assets/app.css?v='));

Database::disconnect();
@unlink($tmp);@unlink($tmp.'-wal');@unlink($tmp.'-shm');
echo "Section 54 checks passed\n";
