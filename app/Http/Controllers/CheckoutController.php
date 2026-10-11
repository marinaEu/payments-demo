<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProcessCheckoutAction;
use App\DTOs\CartDTO;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\CheckoutResource;
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
     * @throws \App\Exceptions\OutOfStockException
     * @throws \App\Exceptions\PaymentGatewayException
     * @throws \App\Exceptions\SkuNotFoundException
     * @throws \App\Exceptions\InvalidCouponException
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

        /** @var JsonResponse $response */
        $response = (new CheckoutResource($result))
            ->response()
            ->setStatusCode(201);

        return $response;
    }
}
