<?php
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('DB_DSN=sqlite::memory:');
putenv('ADMIN_PASSWORD=test-password');
require $root . '/src/bootstrap.php';

use FudgeDonuts\CatalogRepository;
use FudgeDonuts\Database;
use FudgeDonuts\PricingService;

$db = Database::connection();
$db->exec((string)file_get_contents($root . '/database/001_catalog.sql'));
$catalog = new CatalogRepository($db);

assert(count($catalog->packs()) === 3);
assert(count($catalog->flavors()) === 4);
$p = $catalog->packBySize(12);
assert($p && (int)$p['base_price_cents'] === 4299);

$ids = array_column($catalog->flavors(), 'id', 'slug');
$price = (new PricingService($catalog))->customPackTotal(12, [
    $ids['smores'] => 9,
    $ids['caramel-pretzel'] => 3,
]);
assert($price['base_cents'] === 4299);
assert($price['surcharge_cents'] === 300);
assert($price['total_cents'] === 4599);

$failed = false;
try { (new PricingService($catalog))->customPackTotal(12, [$ids['smores'] => 11]); } catch (InvalidArgumentException) { $failed = true; }
assert($failed === true);

echo "Section 2 checks passed\n";
