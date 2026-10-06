<?php
declare(strict_types=1);$root=dirname(__DIR__);require $root.'/src/bootstrap.php';use FudgeDonuts\CheckoutService;
$s=new CheckoutService();$v=$s->validate(['email'=>' A@B.COM ','first_name'=>'A','last_name'=>'B','line1'=>'1 Main','city'=>'Phoenix','region'=>'az','postal_code'=>'85001','gift_message'=>'Enjoy!','is_gift'=>1]);assert($v['email']==='a@b.com');assert($v['region']==='AZ');assert($v['is_gift']===true);
$bad=false;try{$s->validate(['email'=>'bad']);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 6 checks passed\n";
