<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CartItemDTO;
use App\Exceptions\OutOfStockException;
use App\Exceptions\SkuNotFoundException;
use App\Models\Sku;

/**
 * Verifies inventory stock in a single batch query with deterministic pessimistic locking.
 *
 * Eliminates N+1 database queries and prevents circular-wait transaction deadlocks (SQLSTATE 40001)
 * by strictly acquiring row locks in ascending primary key order.
 */
final class CheckStockAction
{
    /**
     * Inspects stock for each item in batch, throwing domain exceptions if unavailable.
     *
     * @param array<int, CartItemDTO> $items
     * @return array<string, Sku>
     *
     * @throws SkuNotFoundException
     * @throws OutOfStockException
     */
    public function execute(array $items, bool $lock = false): array
    {
        if (empty($items)) {
            return [];
        }

        // 1. Aggregate requested quantities per SKU code to handle potential duplicate cart lines
        /** @var array<string, int> $aggregatedQuantities */
        $aggregatedQuantities = [];
        foreach ($items as $item) {
            $aggregatedQuantities[$item->skuCode] = ($aggregatedQuantities[$item->skuCode] ?? 0) + $item->quantity;
        }

        $uniqueSkuCodes = array_keys($aggregatedQuantities);

        // 2. Build single batch query eliminating N+1 overhead
        // Ordering deterministically by 'id' ASC mathematically eliminates circular-wait Deadlocks
        $query = Sku::whereIn('code', $uniqueSkuCodes)
            ->where('is_active', true)
            ->orderBy('id', 'asc');

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Sku> $loadedSkus */
        $loadedSkus = $query->get();

        /** @var array<string, Sku> $skuMap */
        $skuMap = [];
        foreach ($loadedSkus as $sku) {
            $skuMap[$sku->code] = $sku;
        }

        // 3. Validate existence and stock availability against aggregated demand
        foreach ($aggregatedQuantities as $skuCode => $requestedQty) {
            if (! isset($skuMap[$skuCode])) {
                throw new SkuNotFoundException($skuCode);
            }

            $sku = $skuMap[$skuCode];

            if ($sku->stock < $requestedQty) {
                throw new OutOfStockException(
                    skuCode: $sku->code,
                    requestedQuantity: $requestedQty,
                    availableStock: $sku->stock
                );
            }
        }

        return $skuMap;
    }
}
