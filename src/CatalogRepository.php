<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CatalogRepository
{
    public function __construct(private readonly PDO $db) {}

    public function packs(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM pack_sizes' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, size';
        return $this->db->query($sql)->fetchAll();
    }

    public function flavors(bool $availableOnly = true): array
    {
        $where = $availableOnly ? ' WHERE active = 1 AND sold_out = 0' : '';
        return $this->db->query('SELECT * FROM flavors' . $where . ' ORDER BY sort_order, name')->fetchAll();
    }

    public function flavorById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM flavors WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function flavorBySlug(string $slug,bool $activeOnly=true): ?array
    {
        $sql='SELECT * FROM flavors WHERE slug=?'.($activeOnly?' AND active=1':'');
        $s=$this->db->prepare($sql);$s->execute([$slug]);return $s->fetch()?:null;
    }

    public function packBySize(int $size): ?array
    {
        $s = $this->db->prepare('SELECT * FROM pack_sizes WHERE size = ? AND active = 1');
        $s->execute([$size]);
        return $s->fetch() ?: null;
    }

    public function eligibleFlavorIds(int $packId): array
    {
        $s = $this->db->prepare('SELECT flavor_id FROM pack_flavor_eligibility WHERE pack_size_id = ? AND enabled = 1');
        $s->execute([$packId]);
        return array_map('intval', array_column($s->fetchAll(), 'flavor_id'));
    }


    public function presets(bool $activeOnly=true): array
    {
        $where=$activeOnly?' WHERE pp.active=1 AND ps.active=1':'';
        return $this->db->query('SELECT pp.*,ps.size,ps.name pack_name,ps.base_price_cents FROM preset_packs pp JOIN pack_sizes ps ON ps.id=pp.pack_size_id'.$where.' ORDER BY pp.sort_order,pp.name')->fetchAll();
    }

    public function presetBySlug(string $slug): ?array
    {
        $s=$this->db->prepare('SELECT pp.*,ps.size,ps.base_price_cents,ps.active pack_active FROM preset_packs pp JOIN pack_sizes ps ON ps.id=pp.pack_size_id WHERE pp.slug=?');
        $s->execute([$slug]);$row=$s->fetch();
        if(!$row || !(int)$row['pack_active']) return null;
        return $row;
    }

    public function presetById(int $id): ?array
    {
        $s=$this->db->prepare('SELECT pp.*,ps.size,ps.base_price_cents FROM preset_packs pp JOIN pack_sizes ps ON ps.id=pp.pack_size_id WHERE pp.id=?');
        $s->execute([$id]);return $s->fetch()?:null;
    }

    public function presetItems(int $presetId): array
    {
        $s=$this->db->prepare('SELECT ppi.flavor_id,ppi.quantity,f.name,f.slug,f.surcharge_cents,f.image_path,f.active,f.sold_out FROM preset_pack_items ppi JOIN flavors f ON f.id=ppi.flavor_id WHERE ppi.preset_pack_id=? ORDER BY f.sort_order,f.name');
        $s->execute([$presetId]);
        return $s->fetchAll();
    }

    public function savePack(array $data): int
    {
        $id=(int)($data['id']??0);$size=(int)($data['size']??0);$name=trim((string)($data['name']??''));$price=(int)($data['base_price_cents']??-1);
        if($id<=0 || $size<=0 || $name==='' || $price<0) throw new \InvalidArgumentException('Valid pack ID, size, name and price are required.');
        $s=$this->db->prepare('UPDATE pack_sizes SET size=?,name=?,base_price_cents=?,customizable=?,active=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([$size,$name,$price,!empty($data['customizable'])?1:0,!empty($data['active'])?1:0,(int)($data['sort_order']??0),$id]);
        if($s->rowCount()===0 && !$this->packById($id)) throw new \InvalidArgumentException('Pack not found.');
        return $id;
    }

    public function packById(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM pack_sizes WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;
    }

    public function setEligibility(int $packId,array $flavorIds): void
    {
        if(!$this->packById($packId)) throw new \InvalidArgumentException('Pack not found.');
        $ids=array_values(array_unique(array_filter(array_map('intval',$flavorIds),fn($v)=>$v>0)));
        $this->db->beginTransaction();
        try{
            $d=$this->db->prepare('DELETE FROM pack_flavor_eligibility WHERE pack_size_id=?');$d->execute([$packId]);
            $i=$this->db->prepare('INSERT INTO pack_flavor_eligibility(pack_size_id,flavor_id,enabled) VALUES(?,?,1)');
            foreach($ids as $id){
                if(!$this->flavorById($id)) throw new \InvalidArgumentException('Flavor not found.');
                $i->execute([$packId,$id]);
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function savePreset(array $data): int
    {
        $id=(int)($data['id']??0);$packId=(int)($data['pack_size_id']??0);
        $name=trim((string)($data['name']??''));$slug=trim((string)($data['slug']??''));
        $pack=$this->packById($packId);
        if(!$pack || $name==='' || !preg_match('/^[a-z0-9-]+$/',$slug)) throw new \InvalidArgumentException('Valid preset name, slug, and pack are required.');
        $items=[];$total=0;$eligible=array_flip($this->eligibleFlavorIds($packId));
        foreach((array)($data['items']??[]) as $flavorId=>$qty){
            $flavorId=(int)$flavorId;$qty=max(0,(int)$qty);if($qty===0)continue;
            if(!isset($eligible[$flavorId]) || !$this->flavorById($flavorId)) throw new \InvalidArgumentException('Preset contains an ineligible flavor.');
            $items[$flavorId]=$qty;$total+=$qty;
        }
        if($total!==(int)$pack['size']) throw new \InvalidArgumentException('Preset quantities must equal the pack size.');

        $this->db->beginTransaction();
        try{
            if($id>0){
                $s=$this->db->prepare('UPDATE preset_packs SET pack_size_id=?,name=?,slug=?,description=?,active=?,image_path=?,sort_order=? WHERE id=?');
                $s->execute([$packId,$name,$slug,trim((string)($data['description']??'')),!empty($data['active'])?1:0,trim((string)($data['image_path']??'')),(int)($data['sort_order']??0),$id]);
                if($s->rowCount()===0){
                    $q=$this->db->prepare('SELECT 1 FROM preset_packs WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn()) throw new \InvalidArgumentException('Preset not found.');
                }
                $d=$this->db->prepare('DELETE FROM preset_pack_items WHERE preset_pack_id=?');$d->execute([$id]);
            }else{
                $s=$this->db->prepare('INSERT INTO preset_packs(pack_size_id,name,slug,description,active,image_path,sort_order) VALUES(?,?,?,?,?,?,?)');
                $s->execute([$packId,$name,$slug,trim((string)($data['description']??'')),!empty($data['active'])?1:0,trim((string)($data['image_path']??'')),(int)($data['sort_order']??0)]);
                $id=(int)$this->db->lastInsertId();
            }
            $i=$this->db->prepare('INSERT INTO preset_pack_items(preset_pack_id,flavor_id,quantity) VALUES(?,?,?)');
            foreach($items as $flavorId=>$qty)$i->execute([$id,$flavorId,$qty]);
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function saveFlavor(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new \InvalidArgumentException('Name and a lowercase URL-safe slug are required.');
        }
        $values = [
            $name, $slug, trim((string)($data['description'] ?? '')),
            max(0, (int)($data['surcharge_cents'] ?? 0)),
            trim((string)($data['image_path'] ?? '')),
            trim((string)($data['ingredients'] ?? '')),
            trim((string)($data['allergens'] ?? '')),
            !empty($data['active']) ? 1 : 0,
            !empty($data['sold_out']) ? 1 : 0,
            !empty($data['seasonal']) ? 1 : 0,
            (int)($data['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            $values[] = $id;
            $s = $this->db->prepare('UPDATE flavors SET name=?,slug=?,description=?,surcharge_cents=?,image_path=?,ingredients=?,allergens=?,active=?,sold_out=?,seasonal=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $s->execute($values);
            return $id;
        }
        $s = $this->db->prepare('INSERT INTO flavors (name,slug,description,surcharge_cents,image_path,ingredients,allergens,active,sold_out,seasonal,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute($values);
        return (int)$this->db->lastInsertId();
    }
}
