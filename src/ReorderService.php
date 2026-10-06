<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ReorderService
{
    private CartService $cart;

    public function __construct(private readonly PDO $db)
    {
        $catalog=new CatalogRepository($db);
        $this->cart=new CartService(
            new PackBuilderService($catalog),
            new DiscountService($db),
            new PresetPackService($catalog)
        );
    }

    public function reorder(int $userId,int $orderId,array &$session): array
    {
        $s=$this->db->prepare('SELECT id,order_number,status FROM orders WHERE id=? AND user_id=?');
        $s->execute([$orderId,$userId]);$order=$s->fetch();
        if(!$order) throw new \InvalidArgumentException('Order not found.');
        if(!in_array((string)$order['status'],['paid','preparing','ready','shipped','delivered','completed','refunded'],true)){
            throw new \InvalidArgumentException('This order is not eligible for reorder.');
        }

        $i=$this->db->prepare('SELECT kind,pack_size,quantity,configuration_json FROM order_items WHERE order_id=? ORDER BY id');
        $i->execute([$orderId]);$items=$i->fetchAll();
        if(!$items) throw new \InvalidArgumentException('This order has no reorderable boxes.');

        $working=$session;
        $boxes=0;$lines=0;
        foreach($items as $item){
            $cfg=json_decode((string)$item['configuration_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($cfg)) throw new \InvalidArgumentException('An order item is no longer valid.');
            $qty=max(1,min(24,(int)$item['quantity']));
            $type=(string)($cfg['type']??$item['kind']??'');
            if($type==='preset'){
                $slug=trim((string)($cfg['preset_slug']??''));
                if($slug==='') throw new \InvalidArgumentException('A preset from this order is no longer available.');
                $this->cart->addPresetBox($working,$slug,$qty);
            }elseif($type==='custom'){
                $size=(int)($cfg['size']??$item['pack_size']??0);$selections=[];
                foreach((array)($cfg['items']??[]) as $flavor){
                    $flavorId=(int)($flavor['flavor_id']??0);$flavorQty=(int)($flavor['quantity']??0);
                    if($flavorId>0&&$flavorQty>0)$selections[$flavorId]=$flavorQty;
                }
                if($size<1||!$selections) throw new \InvalidArgumentException('A custom box from this order is no longer valid.');
                $this->cart->addCustomBox($working,$size,$selections,$qty);
            }else{
                throw new \InvalidArgumentException('An order item cannot be reordered.');
            }
            $boxes+=$qty;$lines++;
        }

        $summary=$this->cart->summary($working,$working['coupon']??null);
        $session=$working;
        return [
            'order_number'=>(string)$order['order_number'],
            'lines_added'=>$lines,
            'boxes_added'=>$boxes,
            'cart_total_cents'=>(int)$summary['total_cents'],
        ];
    }
}
