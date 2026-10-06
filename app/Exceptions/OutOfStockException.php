<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown when the requested SKU quantity exceeds available inventory stock.
 */
class OutOfStockException extends Exception
{
    public function __construct(
        public readonly string $skuCode,
        public readonly int $requestedQuantity,
        public readonly int $availableStock
    ) {
        parent::__construct(
            "Stock insufficiente per l'articolo '{$skuCode}'. Richiesti: {$requestedQuantity}, Disponibili: {$availableStock}.",
            409
        );
    }
}
