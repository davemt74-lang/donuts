<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class PricingService
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function customPackTotal(int $size, array $selections): array
    {
        $pack = $this->catalog->packBySize($size);
        if (!$pack || !(int)$pack['customizable']) throw new \InvalidArgumentException('Pack is not customizable.');

        $count = 0;
        $surcharge = 0;
        $eligible = array_flip($this->catalog->eligibleFlavorIds((int)$pack['id']));
        foreach ($selections as $flavorId => $qty) {
            $qty = max(0, (int)$qty);
            if ($qty === 0) continue;
            $flavor = $this->catalog->flavorById((int)$flavorId);
            if (!$flavor || !(int)$flavor['active'] || (int)$flavor['sold_out'] || !isset($eligible[(int)$flavorId])) {
                throw new \InvalidArgumentException('A selected flavor is unavailable.');
            }
            $count += $qty;
            $surcharge += $qty * (int)$flavor['surcharge_cents'];
        }
        if ($count !== $size) throw new \InvalidArgumentException("Choose exactly {$size} donuts.");

        return [
            'base_cents' => (int)$pack['base_price_cents'],
            'surcharge_cents' => $surcharge,
            'total_cents' => (int)$pack['base_price_cents'] + $surcharge,
        ];
    }
}
