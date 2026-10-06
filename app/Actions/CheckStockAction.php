<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CartItemDTO;
use App\Exceptions\OutOfStockException;
use App\Exceptions\SkuNotFoundException;
use App\Models\Sku;

/**
 * Verifies inventory stock availability for requested cart items with optional pessimistic locking.
 */
final class CheckStockAction
{
    /**
     * Inspects stock for each item, throwing domain exceptions if unavailable.
     *
     * @param array<int, CartItemDTO> $items
     * @return array<string, Sku>
     *
     * @throws SkuNotFoundException
     * @throws OutOfStockException
     */
    public function execute(array $items, bool $lock = false): array
    {
        /** @var array<string, Sku> $resolvedSkus */
        $resolvedSkus = [];

        foreach ($items as $item) {
            $query = Sku::where('code', $item->skuCode)->where('is_active', true);

            if ($lock) {
                // Pessimistic lock to prevent race conditions during checkout confirmation
                $query->lockForUpdate();
            }

            /** @var Sku|null $sku */
            $sku = $query->first();

            if ($sku === null) {
                throw new SkuNotFoundException($item->skuCode);
            }

            if ($sku->stock < $item->quantity) {
                throw new OutOfStockException(
                    skuCode: $sku->code,
                    requestedQuantity: $item->quantity,
                    availableStock: $sku->stock
                );
            }

            $resolvedSkus[$sku->code] = $sku;
        }

        return $resolvedSkus;
    }
}
