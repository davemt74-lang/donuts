<?php
declare(strict_types=1);require dirname(__DIR__).'/src/bootstrap.php';use FudgeDonuts\{ContentService,Database};
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /');exit;}verify_csrf($_POST['_csrf']??null);
try{(new ContentService(Database::connection()))->subscribe((string)($_POST['email']??''));$_SESSION['flash']='Thanks for joining the Fudge Donuts list.';}catch(Throwable $e){$_SESSION['flash']=$e->getMessage();}
header('Location: /');exit;
