<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class CartService
{
    public function __construct(
        private readonly PackBuilderService $builder,
        private readonly DiscountService $discounts,
        private readonly ?PresetPackService $presets = null
    ) {}

    public function addCustomBox(array &$session, int $size, array $selections, int $quantity=1): string
    {
        $quantity=max(1,min(24,$quantity));
        $box=$this->builder->build($size,$selections);
        $key=hash('sha256',$size.'|'.json_encode($this->selectionMap($box),JSON_THROW_ON_ERROR));
        $this->invalidateCheckoutAttempt($session);
        $session['cart'] ??= [];
        if(isset($session['cart'][$key])){
            $session['cart'][$key]['quantity']=min(24,(int)$session['cart'][$key]['quantity']+$quantity);
        }else{
            $session['cart'][$key]=[
                'kind'=>'custom',
                'size'=>$size,
                'selections'=>$this->selectionMap($box),
                'quantity'=>$quantity,
            ];
        }
        return $key;
    }

    public function addPresetBox(array &$session,string $slug,int $quantity=1): string
    {
        if(!$this->presets) throw new \RuntimeException('Preset pack service is unavailable.');
        $quantity=max(1,min(24,$quantity));
        $box=$this->presets->buildBySlug($slug);
        $key=hash('sha256','preset|'.$box['preset_id']);
        $this->invalidateCheckoutAttempt($session);
        $session['cart'] ??= [];
        if(isset($session['cart'][$key])){
            $session['cart'][$key]['quantity']=min(24,(int)$session['cart'][$key]['quantity']+$quantity);
        }else{
            $session['cart'][$key]=[
                'kind'=>'preset',
                'slug'=>$box['preset_slug'],
                'quantity'=>$quantity,
            ];
        }
        return $key;
    }

    public function remove(array &$session,string $key): void
    {
        if(isset($session['cart'][$key])) $this->invalidateCheckoutAttempt($session);
        unset($session['cart'][$key]);
    }

    public function setQuantity(array &$session,string $key,int $quantity): void
    {
        if(!isset($session['cart'][$key])) return;
        $current=(int)$session['cart'][$key]['quantity'];
        $next=$quantity<=0?0:min(24,$quantity);
        if($next!==$current) $this->invalidateCheckoutAttempt($session);
        if($next<=0){unset($session['cart'][$key]);return;}
        $session['cart'][$key]['quantity']=$next;
    }

    public function invalidateCheckoutAttempt(array &$session): void
    {
        unset($session['checkout_attempt_token'],$session['active_order_id'],$session['fulfillment']);
    }

    public function summary(array $session,?string $code=null): array
    {
        $items=[];$subtotal=0;$units=0;
        foreach(($session['cart']??[]) as $key=>$stored){
            $kind=(string)($stored['kind']??'');
            if($kind==='custom'){
                $box=$this->builder->build((int)$stored['size'],(array)$stored['selections']);
            }elseif($kind==='preset'){
                if(!$this->presets) throw new \RuntimeException('Preset pack service is unavailable.');
                $box=$this->presets->buildBySlug((string)($stored['slug']??''));
            }else{
                continue;
            }
            $qty=max(1,min(24,(int)($stored['quantity']??1)));
            $line=$box['total_cents']*$qty;
            $subtotal+=$line;$units+=$qty;
            $items[]=['key'=>$key,'quantity'=>$qty,'box'=>$box,'line_total_cents'=>$line];
        }
        $discount=$this->discounts->calculate($subtotal,$units,$code);
        return [
            'items'=>$items,'units'=>$units,'subtotal_cents'=>$subtotal,
            'discount_cents'=>$discount['discount_cents'],
            'discounts'=>$discount['applied'],
            'total_cents'=>max(0,$subtotal-$discount['discount_cents']),
        ];
    }

    private function selectionMap(array $box): array
    {
        $map=[];
        foreach($box['items'] as $item)$map[(int)$item['flavor_id']]=(int)$item['quantity'];
        ksort($map);
        return $map;
    }
}
