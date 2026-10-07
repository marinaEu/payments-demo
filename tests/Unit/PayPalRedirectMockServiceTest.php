<?php

declare(strict_types=1);

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentRedirectResponseDTO;
use App\Enums\OrderStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Services\PayPalRedirectMockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new PayPalRedirectMockService();
});

it('resolves PayPalRedirectMockService through PaymentGatewayInterface via IoC container', function () {
    $gateway = app(PaymentGatewayInterface::class);

    expect($gateway)->toBeInstanceOf(PayPalRedirectMockService::class);
});

it('creates a mock paypal order and returns valid redirect response dto', function () {
    $order = Order::create([
        'checkout_token' => 'tok_pay_1',
        'status' => OrderStatus::PENDING,
        'subtotal_amount' => 100.00,
        'tax_amount' => 22.00,
        'discount_amount' => 0.00,
        'total_amount' => 122.00,
        'currency' => 'EUR',
        'customer_email' => 'cliente@test.com',
    ]);

    $response = $this->service->createOrder($order);

    expect($response)->toBeInstanceOf(PaymentRedirectResponseDTO::class)
        ->and($response->status)->toBe('CREATED')
        ->and($response->providerOrderId)->toStartWith('PAYPAL_MOCK_')
        ->and($response->redirectUrl)->toBe("https://www.sandbox.paypal.com/checkoutnow?token={$response->providerOrderId}");
});

it('throws payment gateway exception if order total amount is zero or negative', function () {
    $order = Order::create([
        'checkout_token' => 'tok_pay_zero',
        'status' => OrderStatus::PENDING,
        'subtotal_amount' => 0.00,
        'tax_amount' => 0.00,
        'discount_amount' => 0.00,
        'total_amount' => 0.00,
    ]);

    expect(fn () => $this->service->createOrder($order))
        ->toThrow(PaymentGatewayException::class, "L'importo dell'ordine deve essere maggiore di zero");
});

it('simulates external gateway failure when test failure email is used', function () {
    $order = Order::create([
        'checkout_token' => 'tok_pay_fail',
        'status' => OrderStatus::PENDING,
        'subtotal_amount' => 50.00,
        'tax_amount' => 11.00,
        'discount_amount' => 0.00,
        'total_amount' => 61.00,
        'customer_email' => 'fail_paypal@test.com',
    ]);

    expect(fn () => $this->service->createOrder($order))
        ->toThrow(PaymentGatewayException::class, "Simulazione errore gateway PayPal");
});
