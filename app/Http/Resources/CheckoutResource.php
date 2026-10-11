<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\CheckoutResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms the internal CheckoutResultDTO into a standardized API HTTP presentation resource.
 *
 * Emits monetary amounts as exact formatted decimal strings ("109.80") to prevent
 * IEEE-754 64-bit binary floating-point precision loss at the API perimeter.
 *
 * @property-read CheckoutResultDTO $resource
 */
final class CheckoutResource extends JsonResource
{
    /**
     * Disable the default "data" wrapper to maintain flat payload compatibility.
     */
    public static $wrap = null;

    /**
     * @param CheckoutResultDTO $resource
     */
    public function __construct(CheckoutResultDTO $resource)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array for API output.
     *
     * @return array{
     *     order_id: int,
     *     status: string,
     *     provider_order_id: string,
     *     redirect_url: string,
     *     total_amount: string,
     *     currency: string,
     *     items_count: int
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->resource->orderId,
            'status' => $this->resource->status,
            'provider_order_id' => $this->resource->providerOrderId,
            'redirect_url' => $this->resource->redirectUrl,
            'total_amount' => $this->resource->totalAmount,
            'currency' => $this->resource->currency,
            'items_count' => $this->resource->itemsCount,
        ];
    }
}
