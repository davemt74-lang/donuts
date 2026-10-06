<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require_admin_roles(['super_admin','admin']);
header('Location: /admin-shipping.php',true,302);
exit;
