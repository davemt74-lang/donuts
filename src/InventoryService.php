<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class InventoryService
{
    public function __construct(private readonly PDO $db) {}

    public function availability(int $flavorId): ?int
    {
        $s=$this->db->prepare('SELECT track_inventory,stock_on_hand,reserved FROM flavor_inventory WHERE flavor_id=?');
        $s->execute([$flavorId]);$row=$s->fetch();
        if(!$row || !(int)$row['track_inventory']) return null;
        return max(0,(int)$row['stock_on_hand']-(int)$row['reserved']);
    }

    public function validateCart(array $cart): void
    {
        $needed=$this->flavorNeeds($cart);
        foreach($needed as $flavorId=>$qty){
            $available=$this->availability((int)$flavorId);
            if($available!==null && $qty>$available){
                throw new \InvalidArgumentException('A flavor in your cart no longer has enough inventory. Please update your box.');
            }
        }
    }

    public function reserveOrder(int $orderId,array $cart): void
    {
        $needed=$this->flavorNeeds($cart);
        $this->db->beginTransaction();
        try{
            foreach($needed as $flavorId=>$qty){
                $s=$this->db->prepare('SELECT track_inventory,stock_on_hand,reserved FROM flavor_inventory WHERE flavor_id=?');
                $s->execute([$flavorId]);$row=$s->fetch();
                if(!$row || !(int)$row['track_inventory']) continue;
                $available=(int)$row['stock_on_hand']-(int)$row['reserved'];
                if($available<$qty) throw new \InvalidArgumentException('Inventory changed before payment. Please review your cart.');
                $u=$this->db->prepare('UPDATE flavor_inventory SET reserved=reserved+?,updated_at=CURRENT_TIMESTAMP WHERE flavor_id=?');
                $u->execute([$qty,$flavorId]);
                $i=$this->db->prepare("INSERT INTO inventory_reservations(order_id,flavor_id,quantity,status) VALUES(?,?,?,'reserved') ON CONFLICT(order_id,flavor_id) DO NOTHING");
                $i->execute([$orderId,$flavorId,$qty]);
                if($i->rowCount()!==1) throw new \RuntimeException('Inventory already reserved for this order.');
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function commitOrder(int $orderId): void
    {
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM inventory_reservations WHERE order_id=? AND status='reserved'");$s->execute([$orderId]);
            foreach($s->fetchAll() as $r){
                $u=$this->db->prepare('UPDATE flavor_inventory SET stock_on_hand=MAX(0,stock_on_hand-?),reserved=MAX(0,reserved-?),updated_at=CURRENT_TIMESTAMP WHERE flavor_id=?');
                $u->execute([(int)$r['quantity'],(int)$r['quantity'],(int)$r['flavor_id']]);
            }
            $u=$this->db->prepare("UPDATE inventory_reservations SET status='committed',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='reserved'");$u->execute([$orderId]);
            $this->db->commit();$this->syncSoldOut();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function releaseOrder(int $orderId): void
    {
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT * FROM inventory_reservations WHERE order_id=? AND status='reserved'");$s->execute([$orderId]);
            foreach($s->fetchAll() as $r){
                $u=$this->db->prepare('UPDATE flavor_inventory SET reserved=MAX(0,reserved-?),updated_at=CURRENT_TIMESTAMP WHERE flavor_id=?');$u->execute([(int)$r['quantity'],(int)$r['flavor_id']]);
            }
            $u=$this->db->prepare("UPDATE inventory_reservations SET status='released',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='reserved'");$u->execute([$orderId]);
            $this->db->commit();$this->syncSoldOut();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function setInventory(int $flavorId,bool $track,int $stock,int $lowThreshold): void
    {
        $s=$this->db->prepare('INSERT INTO flavor_inventory(flavor_id,track_inventory,stock_on_hand,reserved,low_stock_threshold) VALUES(?,?,?,?,?) ON CONFLICT(flavor_id) DO UPDATE SET track_inventory=excluded.track_inventory,stock_on_hand=excluded.stock_on_hand,low_stock_threshold=excluded.low_stock_threshold,updated_at=CURRENT_TIMESTAMP');
        $s->execute([$flavorId,$track?1:0,max(0,$stock),0,max(0,$lowThreshold)]);
        $this->syncSoldOut();
    }

    public function rows(): array
    {
        return $this->db->query('SELECT f.id,f.name,f.slug,i.track_inventory,i.stock_on_hand,i.reserved,i.low_stock_threshold,(i.stock_on_hand-i.reserved) available FROM flavors f LEFT JOIN flavor_inventory i ON i.flavor_id=f.id ORDER BY f.sort_order,f.name')->fetchAll();
    }

    private function flavorNeeds(array $cart): array
    {
        $needed=[];
        foreach($cart['items']??[] as $line){
            $mult=max(1,(int)($line['quantity']??1));
            foreach($line['box']['items']??[] as $item){
                $id=(int)$item['flavor_id'];$needed[$id]=($needed[$id]??0)+((int)$item['quantity']*$mult);
            }
        }
        return $needed;
    }

    private function syncSoldOut(): void
    {
        $this->db->exec("UPDATE flavors SET sold_out=CASE WHEN EXISTS(SELECT 1 FROM flavor_inventory i WHERE i.flavor_id=flavors.id AND i.track_inventory=1 AND (i.stock_on_hand-i.reserved)<=0) THEN 1 ELSE sold_out END");
        $this->db->exec("UPDATE flavors SET sold_out=0 WHERE id IN (SELECT flavor_id FROM flavor_inventory WHERE track_inventory=1 AND (stock_on_hand-reserved)>0)");
    }
}
