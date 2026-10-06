<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an SKU code provided in the cart is not found in the database.
 */
class SkuNotFoundException extends Exception
{
    public function __construct(public readonly string $skuCode)
    {
        parent::__construct("L'articolo con SKU '{$skuCode}' non è presente a catalogo.", 404);
    }
}
