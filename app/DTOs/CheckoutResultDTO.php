<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Encapsulates the output of a successfully processed checkout transaction.
 */
final readonly class CheckoutResultDTO
{
    public function __construct(
        public int $orderId,
        public string $status,
        public string $providerOrderId,
        public string $redirectUrl,
        public float $totalAmount,
        public string $currency,
        public int $itemsCount
    ) {
    }

    /**
     * Converts the DTO into an array suitable for JSON response serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'provider_order_id' => $this->providerOrderId,
            'redirect_url' => $this->redirectUrl,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'items_count' => $this->itemsCount,
        ];
    }
}
