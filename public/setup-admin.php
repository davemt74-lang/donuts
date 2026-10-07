<?php
declare(strict_types=1);

// Backward-compatible entry point. The database and first administrator are
// now created together by the one-page web installer.
header('Location: /install.php');
exit;
