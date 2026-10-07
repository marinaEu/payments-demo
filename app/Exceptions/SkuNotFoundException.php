<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when an SKU code provided in the cart is not found in the database.
 */
class SkuNotFoundException extends Exception
{
    public function __construct(public readonly string $skuCode)
    {
        parent::__construct("L'articolo con SKU '{$skuCode}' non è presente a catalogo.", 404);
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'sku_not_found',
            'message' => $this->getMessage(),
        ], 404);
    }
}
