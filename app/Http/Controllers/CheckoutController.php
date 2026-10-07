<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProcessCheckoutAction;
use App\DTOs\CartDTO;
use App\Http\Requests\CheckoutRequest;
use Illuminate\Http\JsonResponse;

/**
 * Gestione del checkout con redirect PayPal.
 */
final class CheckoutController extends Controller
{
    /**
     * Inizia il processo di checkout e genera l'URL di redirect PayPal.
     *
     * Valida il carrello, verifica lo stock con blocco pessimistico (SELECT FOR UPDATE),
     * applica eventuali coupon (fissi o percentuali), registra l'ordine in stato pending
     * e genera l'URL di approvazione per il redirect verso PayPal.
     *
     * @response 201 array{order_id: int, status: string, provider_order_id: string, redirect_url: string, total_amount: float, currency: string, items_count: int}
     * @response 409 array{error: string, message: string, sku_code?: string, requested_quantity?: int, available_stock?: int}
     * @response 422 array{error: string, message: string}
     * @response 502 array{error: string, message: string}
     */
    public function __invoke(CheckoutRequest $request, ProcessCheckoutAction $action): JsonResponse
    {
        $validated = $request->validated();

        /** @var array{
         *     checkout_token: string,
         *     items: array<int, array{sku_code: string, quantity: int|numeric-string}>,
         *     coupon_code?: string|null,
         *     customer_email?: string|null,
         *     currency?: string,
         *     return_url?: string|null,
         *     cancel_url?: string|null
         * } $validated */
        $cartDTO = CartDTO::fromArray($validated);

        $result = $action->execute(
            cart: $cartDTO,
            returnUrl: $validated['return_url'] ?? null,
            cancelUrl: $validated['cancel_url'] ?? null
        );

        return response()->json($result->toArray(), 201);
    }
}
