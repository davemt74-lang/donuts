<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FulfillmentSettingsService
{
    public function __construct(private readonly PDO $db) {}

    public function methods(): array
    {
        return $this->db->query('SELECT * FROM shipping_methods ORDER BY sort_order,id')->fetchAll();
    }

    public function method(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM shipping_methods WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;
    }

    public function saveMethod(array $data): int
    {
        $id=(int)($data['id']??0);
        $code=strtolower(trim((string)($data['code']??'')));
        $name=trim((string)($data['name']??''));
        $type=(string)($data['type']??'shipping');
        if(!preg_match('/^[a-z0-9-]{2,64}$/',$code)) throw new \InvalidArgumentException('Method code must use lowercase letters, numbers, and hyphens.');
        if($name==='') throw new \InvalidArgumentException('Method name is required.');
        if(!in_array($type,['shipping','pickup'],true)) throw new \InvalidArgumentException('Invalid fulfillment method type.');
        $price=max(0,(int)($data['price_cents']??0));
        $free=trim((string)($data['free_over_cents']??''));$free=$free===''?null:max(0,(int)$free);
        $etaMin=trim((string)($data['eta_min_days']??''));$etaMin=$etaMin===''?null:max(0,(int)$etaMin);
        $etaMax=trim((string)($data['eta_max_days']??''));$etaMax=$etaMax===''?null:max(0,(int)$etaMax);
        if($etaMin!==null&&$etaMax!==null&&$etaMax<$etaMin) throw new \InvalidArgumentException('Maximum ETA must be greater than or equal to minimum ETA.');
        if($type==='pickup'){$price=0;$free=null;}
        $values=[
            $code,$name,$type,$price,$free,
            mb_substr(trim((string)($data['description']??'')),0,1000),
            $etaMin,$etaMax,mb_substr(trim((string)($data['checkout_message']??'')),0,1000),
            !empty($data['active'])?1:0,(int)($data['sort_order']??0)
        ];
        if($id>0){
            $s=$this->db->prepare('UPDATE shipping_methods SET code=?,name=?,type=?,price_cents=?,free_over_cents=?,description=?,eta_min_days=?,eta_max_days=?,checkout_message=?,active=?,sort_order=? WHERE id=?');
            $s->execute([...$values,$id]);if($s->rowCount()===0 && !$this->method($id)) throw new \InvalidArgumentException('Fulfillment method not found.');
            return $id;
        }
        $s=$this->db->prepare('INSERT INTO shipping_methods(code,name,type,price_cents,free_over_cents,description,eta_min_days,eta_max_days,checkout_message,active,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute($values);return (int)$this->db->lastInsertId();
    }

    public function setPickupZip(string $postalCode,bool $active): void
    {
        $postalCode=$this->normalizeZip($postalCode);
        $s=$this->db->prepare('INSERT INTO pickup_zip_codes(postal_code,active) VALUES(?,?) ON CONFLICT(postal_code) DO UPDATE SET active=excluded.active');
        $s->execute([$postalCode,$active?1:0]);
    }

    public function pickupZips(): array
    {
        return $this->db->query('SELECT * FROM pickup_zip_codes ORDER BY active DESC,postal_code')->fetchAll();
    }

    public function settings(): array
    {
        $out=[];foreach($this->db->query('SELECT setting_key,setting_value FROM fulfillment_settings')->fetchAll() as $row)$out[(string)$row['setting_key']]=(string)$row['setting_value'];return $out;
    }

    public function saveSettings(array $data): void
    {
        $allowed=['pickup_location_name','pickup_address','pickup_hours','pickup_instructions','shipping_notice'];
        $s=$this->db->prepare('INSERT INTO fulfillment_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP');
        foreach($allowed as $key)$s->execute([$key,mb_substr(trim((string)($data[$key]??'')),0,2000)]);
    }

    private function normalizeZip(string $postalCode): string
    {
        $postalCode=trim($postalCode);
        if(!preg_match('/^\d{5}(?:-\d{4})?$/',$postalCode)) throw new \InvalidArgumentException('Enter a valid five-digit ZIP code.');
        return substr($postalCode,0,5);
    }
}
