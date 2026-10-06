<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class PackBuilderService
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function build(int $size, array $rawSelections): array
    {
        $pack = $this->catalog->packBySize($size);
        if (!$pack || !(int)$pack['customizable']) {
            throw new \InvalidArgumentException('Pack is not customizable.');
        }

        $eligible = array_flip($this->catalog->eligibleFlavorIds((int)$pack['id']));
        $items = [];
        $count = 0;
        $surcharge = 0;

        foreach ($rawSelections as $flavorId => $rawQty) {
            if (!is_scalar($rawQty) || !preg_match('/^\d+$/', (string)$rawQty)) {
                throw new \InvalidArgumentException('Invalid flavor quantity.');
            }
            $qty = (int)$rawQty;
            if ($qty === 0) continue;
            if ($qty > $size) throw new \InvalidArgumentException('Flavor quantity exceeds pack size.');

            $id = (int)$flavorId;
            $flavor = $this->catalog->flavorById($id);
            if (!$flavor || !(int)$flavor['active'] || (int)$flavor['sold_out'] || !isset($eligible[$id])) {
                throw new \InvalidArgumentException('A selected flavor is unavailable.');
            }

            $count += $qty;
            if ($count > $size) throw new \InvalidArgumentException("Choose exactly {$size} donuts.");

            $lineSurcharge = $qty * (int)$flavor['surcharge_cents'];
            $surcharge += $lineSurcharge;
            $items[] = [
                'flavor_id' => $id,
                'slug' => $flavor['slug'],
                'name' => $flavor['name'],
                'quantity' => $qty,
                'unit_surcharge_cents' => (int)$flavor['surcharge_cents'],
                'line_surcharge_cents' => $lineSurcharge,
                'image_path' => $flavor['image_path'],
            ];
        }

        if ($count !== $size) throw new \InvalidArgumentException("Choose exactly {$size} donuts.");

        return [
            'type' => 'custom',
            'pack_size_id' => (int)$pack['id'],
            'size' => (int)$pack['size'],
            'name' => $pack['name'],
            'base_price_cents' => (int)$pack['base_price_cents'],
            'surcharge_cents' => $surcharge,
            'total_cents' => (int)$pack['base_price_cents'] + $surcharge,
            'items' => $items,
        ];
    }

    public function normalizeDraft(int $size, array $rawSelections): array
    {
        $normalized = [];
        foreach ($rawSelections as $flavorId => $rawQty) {
            $id = (int)$flavorId;
            $qty = max(0, min($size, (int)$rawQty));
            if ($id > 0 && $qty > 0) $normalized[$id] = $qty;
        }
        return $normalized;
    }
}
