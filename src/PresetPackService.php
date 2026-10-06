<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class PresetPackService
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function buildBySlug(string $slug): array
    {
        $preset=$this->catalog->presetBySlug($slug);
        if(!$preset || !(int)$preset['active']) throw new \InvalidArgumentException('Preset pack is unavailable.');
        $items=$this->catalog->presetItems((int)$preset['id']);
        $size=(int)$preset['size'];
        $count=0;$surcharge=0;$normalized=[];
        foreach($items as $item){
            if(!(int)$item['active'] || (int)$item['sold_out']) throw new \InvalidArgumentException('A flavor in this preset is unavailable.');
            $qty=(int)$item['quantity'];$count+=$qty;
            $line=$qty*(int)$item['surcharge_cents'];$surcharge+=$line;
            $normalized[]=[
                'flavor_id'=>(int)$item['flavor_id'],'slug'=>$item['slug'],'name'=>$item['name'],
                'quantity'=>$qty,'unit_surcharge_cents'=>(int)$item['surcharge_cents'],
                'line_surcharge_cents'=>$line,'image_path'=>$item['image_path'],
            ];
        }
        if($count!==$size) throw new \RuntimeException('Preset pack configuration does not match its pack size.');
        return [
            'type'=>'preset','preset_id'=>(int)$preset['id'],'preset_slug'=>$preset['slug'],
            'pack_size_id'=>(int)$preset['pack_size_id'],'size'=>$size,'name'=>$preset['name'],
            'base_price_cents'=>(int)$preset['base_price_cents'],'surcharge_cents'=>$surcharge,
            'total_cents'=>(int)$preset['base_price_cents']+$surcharge,'image_path'=>$preset['image_path'],
            'items'=>$normalized,
        ];
    }
}
