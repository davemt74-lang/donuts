<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,NotificationService};

$svc=new NotificationService(Database::connection());
$transport=strtolower((string)env('MAIL_TRANSPORT','log'));
$from=(string)env('MAIL_FROM','orders@example.com');
foreach($svc->pending() as $m){
 try{
   if($transport==='mail'){
     $ok=mail($m['recipient'],$m['subject'],$m['body'],"From: {$from}\r\nContent-Type: text/plain; charset=UTF-8");
     if(!$ok) throw new RuntimeException('mail() returned false');
   }else{
     fwrite(STDOUT,"[mail] {$m['recipient']} | {$m['subject']}\n");
   }
   $svc->markSent((int)$m['id']);
 }catch(Throwable $e){$svc->markFailed((int)$m['id'],$e->getMessage());}
}
