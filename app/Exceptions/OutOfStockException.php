<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'out_of_stock',
            'message' => $this->getMessage(),
            'sku_code' => $this->skuCode,
            'requested_quantity' => $this->requestedQuantity,
            'available_stock' => $this->availableStock,
        ], 409);
    }
}
