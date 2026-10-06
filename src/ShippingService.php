<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ShippingService
{
    public function __construct(private readonly PDO $db) {}

    public function methodsFor(string $postalCode,int $subtotalCents): array
    {
        $postalCode=$this->normalizePostalCode($postalCode);
        $rows=$this->db->query("SELECT * FROM shipping_methods WHERE active=1 ORDER BY sort_order,id")->fetchAll();
        $pickupAllowed=$this->pickupAllowed($postalCode);
        $out=[];
        foreach($rows as $row){
            if($row['type']==='pickup' && !$pickupAllowed) continue;
            $price=(int)$row['price_cents'];
            if($row['type']==='shipping' && $row['free_over_cents']!==null && $subtotalCents>=(int)$row['free_over_cents']) $price=0;
            $etaMin=array_key_exists('eta_min_days',$row)&&$row['eta_min_days']!==null?(int)$row['eta_min_days']:null;
            $etaMax=array_key_exists('eta_max_days',$row)&&$row['eta_max_days']!==null?(int)$row['eta_max_days']:null;
            $out[]=[
                'code'=>$row['code'],'name'=>$row['name'],'type'=>$row['type'],
                'price_cents'=>$price,'free_over_cents'=>$row['free_over_cents']!==null?(int)$row['free_over_cents']:null,
                'description'=>(string)($row['description']??''),
                'checkout_message'=>(string)($row['checkout_message']??''),
                'eta_min_days'=>$etaMin,'eta_max_days'=>$etaMax,'eta_label'=>$this->etaLabel($etaMin,$etaMax),
                'settings'=>$row['type']==='pickup'?$this->settings():[],
            ];
        }
        return $out;
    }

    public function quote(string $methodCode,string $postalCode,int $subtotalCents): array
    {
        foreach($this->methodsFor($postalCode,$subtotalCents) as $method) if(hash_equals($method['code'],$methodCode)) return $method;
        throw new \InvalidArgumentException('Selected fulfillment method is unavailable for this ZIP code.');
    }

    public function pickupAllowed(string $postalCode): bool
    {
        $s=$this->db->prepare('SELECT 1 FROM pickup_zip_codes WHERE postal_code=? AND active=1');
        $s->execute([$this->normalizePostalCode($postalCode)]);
        return (bool)$s->fetchColumn();
    }

    public function settings(): array
    {
        try{
            $out=[];foreach($this->db->query('SELECT setting_key,setting_value FROM fulfillment_settings')->fetchAll() as $row)$out[(string)$row['setting_key']]=(string)$row['setting_value'];return $out;
        }catch(\Throwable){return [];}
    }

    private function etaLabel(?int $min,?int $max): string
    {
        if($min===null&&$max===null)return '';
        if($min!==null&&$max!==null)return $min===$max?$min.' day'.($min===1?'':'s'):$min.'–'.$max.' days';
        $days=$min??$max;return (string)$days.' day'.($days===1?'':'s');
    }

    private function normalizePostalCode(string $postalCode): string
    {
        $postalCode=strtoupper(trim($postalCode));
        if(preg_match('/^\d{5}(?:-\d{4})?$/',$postalCode)) return substr($postalCode,0,5);
        return $postalCode;
    }
}
