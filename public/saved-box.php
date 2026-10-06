<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CartService,CatalogRepository,CustomerAccountService,Database,DiscountService,PackBuilderService,PresetPackService};
if(empty($_SESSION['user_id'])){header('Location: /account.php');exit;}
$db=Database::connection();$svc=new CustomerAccountService($db);$id=(int)($_POST['id']??0);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /account.php');exit;}
verify_csrf($_POST['_csrf']??null);
try{
 if(($_POST['action']??'')==='delete'){$svc->deleteSavedBox((int)$_SESSION['user_id'],$id);header('Location: /account.php');exit;}
 $box=$svc->savedBox((int)$_SESSION['user_id'],$id);if(!$box)throw new InvalidArgumentException('Saved box not found.');
 $catalog=new CatalogRepository($db);$cart=new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog));
 $svc->restoreConfigurationToCart($_SESSION,$box['configuration'],$cart,1);header('Location: /cart.php');exit;
}catch(Throwable $e){$_SESSION['account_flash']=$e->getMessage();header('Location: /account.php');exit;}
