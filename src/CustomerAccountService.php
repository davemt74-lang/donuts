<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CustomerAccountService
{
    public function __construct(private readonly PDO $db) {}

    public function orders(int $userId,int $limit=50): array
    {
        $limit=max(1,min(100,$limit));
        $s=$this->db->prepare("SELECT id,order_number,status,fulfillment_name,total_cents,created_at FROM orders WHERE user_id=? ORDER BY id DESC LIMIT {$limit}");
        $s->execute([$userId]);return $s->fetchAll();
    }

    public function order(int $userId,int $orderId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM orders WHERE id=? AND user_id=?');
        $s->execute([$orderId,$userId]);$order=$s->fetch();
        if(!$order) return null;
        $i=$this->db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
        $i->execute([$orderId]);$order['items']=$i->fetchAll();
        try{$f=$this->db->prepare('SELECT * FROM order_fulfillment_details WHERE order_id=?');$f->execute([$orderId]);$order['fulfillment_details']=$f->fetch()?:null;}catch(\Throwable){$order['fulfillment_details']=null;}
        return $order;
    }

    public function saveBox(int $userId,string $name,array $configuration): int
    {
        $name=trim($name);
        if($name==='') throw new \InvalidArgumentException('Give this box a name.');
        $type=(string)($configuration['type']??'');
        $size=(int)($configuration['size']??0);
        if(!in_array($type,['custom','preset'],true) || $size<=0) throw new \InvalidArgumentException('Invalid box configuration.');
        $s=$this->db->prepare('INSERT INTO saved_boxes(user_id,name,box_type,pack_size,configuration_json) VALUES(?,?,?,?,?)');
        $s->execute([$userId,mb_substr($name,0,120),$type,$size,json_encode($configuration,JSON_THROW_ON_ERROR)]);
        return (int)$this->db->lastInsertId();
    }

    public function savedBoxes(int $userId): array
    {
        $s=$this->db->prepare('SELECT * FROM saved_boxes WHERE user_id=? ORDER BY updated_at DESC,id DESC');
        $s->execute([$userId]);return $s->fetchAll();
    }

    public function savedBox(int $userId,int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM saved_boxes WHERE id=? AND user_id=?');
        $s->execute([$id,$userId]);$row=$s->fetch();
        if(!$row) return null;
        $row['configuration']=json_decode((string)$row['configuration_json'],true,512,JSON_THROW_ON_ERROR);
        return $row;
    }

    public function deleteSavedBox(int $userId,int $id): void
    {
        $s=$this->db->prepare('DELETE FROM saved_boxes WHERE id=? AND user_id=?');
        $s->execute([$id,$userId]);
    }

    public function restoreConfigurationToCart(array &$session,array $configuration,CartService $cart,int $quantity=1): string
    {
        $type=(string)($configuration['type']??'');
        if($type==='preset'){
            $slug=(string)($configuration['preset_slug']??'');
            if($slug==='') throw new \InvalidArgumentException('This saved preset is no longer valid.');
            return $cart->addPresetBox($session,$slug,$quantity);
        }
        if($type==='custom'){
            $size=(int)($configuration['size']??0);$selections=[];
            foreach((array)($configuration['items']??[]) as $item){
                $id=(int)($item['flavor_id']??0);$qty=(int)($item['quantity']??0);
                if($id>0 && $qty>0)$selections[$id]=$qty;
            }
            return $cart->addCustomBox($session,$size,$selections,$quantity);
        }
        throw new \InvalidArgumentException('Unsupported box configuration.');
    }
}
