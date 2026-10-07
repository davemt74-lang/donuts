<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

// Backward-compatible entry point. The database and first administrator are
// now created together by the one-page web installer.
header('Location: /install.php');
exit;
