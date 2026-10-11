<?php

declare(strict_types=1);

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\PaymentFailed;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    $this->product = Product::create([
        'name' => 'Scarpe da Corsa Pro',
        'description' => 'Scarpa running tecnica',
        'is_active' => true,
    ]);

    $this->sku42 = $this->product->skus()->create([
        'code' => 'RUN-42',
        'name' => 'Taglia 42',
        'price_amount' => 100.00,
        'stock' => 10,
        'is_active' => true,
    ]);

    $this->sku43 = $this->product->skus()->create([
        'code' => 'RUN-43',
        'name' => 'Taglia 43',
        'price_amount' => 100.00,
        'stock' => 2,
        'is_active' => true,
    ]);

    $this->coupon = Coupon::create([
        'code' => 'DISCOUNT10',
        'type' => CouponType::PERCENTAGE,
        'value' => 10.00,
        'is_active' => true,
    ]);
});

it('successfully processes checkout, creates pending order and returns paypal redirect url', function () {
    $payload = [
        'checkout_token' => 'tok_order_success_123',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
        'coupon_code' => 'DISCOUNT10',
        'customer_email' => 'mario.rossi@example.com',
        'currency' => 'EUR',
    ];

    $response = postJson('/api/checkout', $payload, [
        'Idempotency-Key' => 'idemp_key_success_123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'order_id',
            'status',
            'provider_order_id',
            'redirect_url',
            'total_amount',
            'currency',
            'items_count',
        ])
        ->assertJson([
            'status' => 'pending',
            'total_amount' => '109.80', // Subtotale 100 - Sconto 10 = Imponibile 90 + IVA 22% (19.80) = 109.80
            'currency' => 'EUR',
            'items_count' => 1,
        ]);

    $providerOrderId = $response->json('provider_order_id');
    expect($providerOrderId)->toStartWith('PAYPAL_MOCK_')
        ->and($response->json('redirect_url'))->toBe("https://www.sandbox.paypal.com/checkoutnow?token={$providerOrderId}");

    // Asserts database persistence
    $order = Order::where('checkout_token', 'tok_order_success_123')->first();
    expect($order)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::PENDING)
        ->and($order->provider_order_id)->toBe($providerOrderId)
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->sku_code)->toBe('RUN-42');
});

it('returns 409 conflict and rolls back transaction when item stock is insufficient', function () {
    $payload = [
        'checkout_token' => 'tok_out_of_stock',
        'items' => [
            ['sku_code' => 'RUN-43', 'quantity' => 5], // Solo 2 pezzi a magazzino
        ],
    ];

    $response = postJson('/api/checkout', $payload);

    $response->assertStatus(409)
        ->assertJson([
            'error' => 'out_of_stock',
            'sku_code' => 'RUN-43',
            'requested_quantity' => 5,
            'available_stock' => 2,
        ]);

    expect(Order::where('checkout_token', 'tok_out_of_stock')->exists())->toBeFalse();
});

it('returns 422 unprocessable entity and aborts order creation when coupon is invalid', function () {
    $payload = [
        'checkout_token' => 'tok_invalid_coupon',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
        'coupon_code' => 'NON_EXISTENT_CODE',
    ];

    $response = postJson('/api/checkout', $payload);

    $response->assertStatus(422)
        ->assertJson([
            'error' => 'invalid_coupon',
        ]);

    expect(Order::where('checkout_token', 'tok_invalid_coupon')->exists())->toBeFalse();
});

it('returns 502 bad gateway, marks order as FAILED with failure reason and restores stock when payment gateway fails', function () {
    $payload = [
        'checkout_token' => 'tok_gateway_failure',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
        'customer_email' => 'fail_paypal@test.com', // Trigger di errore per il Mock PayPal
    ];

    $response = postJson('/api/checkout', $payload);

    $response->assertStatus(502)
        ->assertJson([
            'error' => 'payment_gateway_error',
        ]);

    // Order is preserved in FAILED status for accounting audit trail, and stock is restored!
    $failedOrder = Order::where('checkout_token', 'tok_gateway_failure')->first();
    expect($failedOrder)->not->toBeNull()
        ->and($failedOrder->status)->toBe(OrderStatus::FAILED)
        ->and($failedOrder->failure_reason)->toContain('Simulazione errore gateway PayPal');

    // Stock for RUN-42 was restored back to 10
    expect($this->sku42->fresh()->stock)->toBe(10);
});

it('rejects idempotent retry with 422 if payload was altered (payload mismatch vulnerability)', function () {
    $headers = ['Idempotency-Key' => 'idemp_key_mismatch_test'];

    // First request: valid initial purchase
    $firstPayload = [
        'checkout_token' => 'tok_mismatch_1',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
    ];
    $firstResponse = postJson('/api/checkout', $firstPayload, $headers);
    $firstResponse->assertStatus(201);

    // Second request: SAME Idempotency-Key but MODIFIED items payload
    $alteredPayload = [
        'checkout_token' => 'tok_mismatch_1',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 2], // Modified quantity!
        ],
    ];
    $secondResponse = postJson('/api/checkout', $alteredPayload, $headers);
    $secondResponse->assertStatus(422)
        ->assertJson([
            'error' => 'idempotency_payload_mismatch',
        ]);
});

it('dispatches OrderCreated and PaymentFailed domain events appropriately without premature emission', function () {
    Event::fake([OrderCreated::class, PaymentFailed::class]);

    // Happy path dispatches OrderCreated only upon gateway approval
    $payloadSuccess = [
        'checkout_token' => 'tok_event_success',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
    ];
    postJson('/api/checkout', $payloadSuccess);
    Event::assertDispatched(OrderCreated::class);

    // Reset event tracker
    Event::fake([OrderCreated::class, PaymentFailed::class]);

    // Failure dispatches PaymentFailed, but NEVER dispatches OrderCreated
    $payloadFail = [
        'checkout_token' => 'tok_event_fail',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
        'customer_email' => 'fail_paypal@test.com',
    ];
    postJson('/api/checkout', $payloadFail);
    Event::assertDispatched(PaymentFailed::class);
    Event::assertNotDispatched(OrderCreated::class);
});

it('guarantees idempotency level 1 via cache middleware on identical network retries', function () {
    $payload = [
        'checkout_token' => 'tok_retry_test',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
    ];

    $headers = [
        'Idempotency-Key' => 'idemp_key_network_retry_456',
    ];

    // First call: fresh execution
    $firstResponse = postJson('/api/checkout', $payload, $headers);
    $firstResponse->assertStatus(201)
        ->assertHeader('X-Cache', 'MISS');

    $originalOrderId = $firstResponse->json('order_id');
    $originalRedirectUrl = $firstResponse->json('redirect_url');

    // Second call: duplicate/retry with same Idempotency-Key
    $secondResponse = postJson('/api/checkout', $payload, $headers);
    $secondResponse->assertStatus(201)
        ->assertHeader('X-Cache', 'HIT')
        ->assertJson([
            'order_id' => $originalOrderId,
            'redirect_url' => $originalRedirectUrl,
        ]);

    // Guarantees only one order was written to the database
    expect(Order::where('checkout_token', 'tok_retry_test')->count())->toBe(1);
});

it('enforces idempotency level 2 via database unique constraint when duplicate token bypasses cache', function () {
    $payload = [
        'checkout_token' => 'tok_db_constraint_test',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
    ];

    // First call without Idempotency-Key header (uses checkout_token)
    $first = postJson('/api/checkout', $payload);
    $first->assertStatus(201);

    // Manually flush cache to simulate cache eviction or multi-node race condition
    Cache::flush();

    // Second call tries to insert same checkout_token
    $second = postJson('/api/checkout', $payload);
    $second->assertStatus(409)
        ->assertJson([
            'idempotency_key' => 'tok_db_constraint_test',
        ]);

    expect(Order::where('checkout_token', 'tok_db_constraint_test')->count())->toBe(1);
});

it('blocks concurrent in-flight duplicate requests using atomic distributed lock', function () {
    $payload = [
        'checkout_token' => 'tok_inflight_test',
        'items' => [
            ['sku_code' => 'RUN-42', 'quantity' => 1],
        ],
    ];

    $headers = [
        'Idempotency-Key' => 'idemp_inflight_key_123',
    ];

    // Simulate an in-flight lock held by a concurrent request
    $lock = Cache::lock('lock:idempotency:idemp_inflight_key_123', 15);
    $lock->get();

    try {
        $response = postJson('/api/checkout', $payload, $headers);

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'request_in_flight',
                'idempotency_key' => 'idemp_inflight_key_123',
            ]);
    } finally {
        $lock->release();
    }
});

