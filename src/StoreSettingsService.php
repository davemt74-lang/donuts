<?php
declare(strict_types=1);

namespace FudgeDonuts;

use DateTimeZone;
use PDO;

final class StoreSettingsService
{
    private const KEYS=[
        'store_name','legal_name','contact_email','support_email','phone',
        'address_line1','address_line2','city','region','postal_code','country',
        'timezone','order_prefix','instagram_url','facebook_url'
    ];

    public function __construct(private readonly PDO $db) {}

    public function all(): array
    {
        $defaults=[
            'store_name'=>'Fudge Donuts','legal_name'=>'Fudge Donuts',
            'contact_email'=>'hello@example.com','support_email'=>'','phone'=>'',
            'address_line1'=>'','address_line2'=>'','city'=>'','region'=>'',
            'postal_code'=>'','country'=>'US','timezone'=>'UTC','order_prefix'=>'FD',
            'instagram_url'=>'','facebook_url'=>'',
        ];
        try{
            foreach($this->db->query('SELECT setting_key,setting_value FROM store_settings')->fetchAll() as $row){
                $key=(string)$row['setting_key'];
                if(array_key_exists($key,$defaults))$defaults[$key]=(string)$row['setting_value'];
            }
        }catch(\Throwable){}
        return $defaults;
    }

    public function get(string $key,string $default=''): string
    {
        if(!in_array($key,self::KEYS,true)) return $default;
        return $this->all()[$key]??$default;
    }

    public function save(array $data): void
    {
        $values=$this->normalize($data);
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP');
            foreach($values as $key=>$value)$s->execute([$key,$value]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function orderPrefix(): string
    {
        $prefix=strtoupper(trim($this->get('order_prefix','FD')));
        return preg_match('/^[A-Z0-9]{2,8}$/',$prefix)?$prefix:'FD';
    }

    public function brandName(): string
    {
        $name=trim($this->get('store_name','Fudge Donuts'));
        return $name!==''?$name:'Fudge Donuts';
    }

    public function supportEmail(): string
    {
        $support=trim($this->get('support_email',''));
        if($support!=='' && filter_var($support,FILTER_VALIDATE_EMAIL))return $support;
        $env=trim((string)\env('SUPPORT_EMAIL',''));
        if($env!=='' && filter_var($env,FILTER_VALIDATE_EMAIL))return $env;
        return trim($this->get('contact_email',''));
    }

    private function normalize(array $data): array
    {
        $current=$this->all();$out=[];
        foreach(self::KEYS as $key)$out[$key]=mb_substr(trim((string)($data[$key]??$current[$key]??'')),0,1000);

        if($out['store_name']==='' || mb_strlen($out['store_name'])>120) throw new \InvalidArgumentException('Store name is required and must be 120 characters or fewer.');
        if($out['legal_name']==='' || mb_strlen($out['legal_name'])>190) throw new \InvalidArgumentException('Legal business name is required.');
        if(!filter_var($out['contact_email'],FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid contact email.');
        if($out['support_email']!=='' && !filter_var($out['support_email'],FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid support email.');
        if(!preg_match('/^[A-Z0-9]{2,8}$/',strtoupper($out['order_prefix']))) throw new \InvalidArgumentException('Order prefix must use 2–8 letters or numbers.');
        $out['order_prefix']=strtoupper($out['order_prefix']);
        $out['country']=strtoupper($out['country']);
        if($out['country']==='' || strlen($out['country'])>2) throw new \InvalidArgumentException('Country must be a two-letter code.');
        if(!in_array($out['timezone'],DateTimeZone::listIdentifiers(),true) && $out['timezone']!=='UTC') throw new \InvalidArgumentException('Choose a valid timezone.');
        foreach(['instagram_url','facebook_url'] as $key){
            if($out[$key]!=='' && (!filter_var($out[$key],FILTER_VALIDATE_URL) || !preg_match('#^https?://#i',$out[$key]))) throw new \InvalidArgumentException(ucwords(str_replace('_',' ',$key)).' must be a valid HTTP(S) URL.');
        }
        if(mb_strlen($out['phone'])>40) throw new \InvalidArgumentException('Phone number is too long.');
        if(mb_strlen($out['postal_code'])>20) throw new \InvalidArgumentException('Postal code is too long.');
        return $out;
    }
}
