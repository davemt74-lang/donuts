<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class PricingService
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function customPackTotal(int $size, array $selections): array
    {
        $box = (new PackBuilderService($this->catalog))->build($size, $selections);
        return [
            'base_cents' => $box['base_price_cents'],
            'surcharge_cents' => $box['surcharge_cents'],
            'total_cents' => $box['total_cents'],
        ];
    }
}
