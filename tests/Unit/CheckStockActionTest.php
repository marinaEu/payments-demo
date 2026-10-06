<?php

declare(strict_types=1);

use App\Actions\CheckStockAction;
use App\DTOs\CartItemDTO;
use App\Exceptions\OutOfStockException;
use App\Exceptions\SkuNotFoundException;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->action = new CheckStockAction();

    $this->product = Product::create(['name' => 'Scarpe Sportive']);
    $this->sku = $this->product->skus()->create([
        'code' => 'SHOE-41',
        'name' => 'Scarpa Taglia 41',
        'price_amount' => 60.00,
        'stock' => 5,
        'is_active' => true,
    ]);
});

it('resolves skus when requested quantities are available in stock', function () {
    $items = [
        new CartItemDTO(skuCode: 'SHOE-41', quantity: 3),
    ];

    $resolved = $this->action->execute($items);

    expect($resolved)->toHaveKey('SHOE-41')
        ->and($resolved['SHOE-41']->id)->toBe($this->sku->id)
        ->and($resolved['SHOE-41']->stock)->toBe(5);
});

it('throws out of stock exception when requested quantity exceeds inventory stock', function () {
    $items = [
        new CartItemDTO(skuCode: 'SHOE-41', quantity: 10),
    ];

    expect(fn () => $this->action->execute($items))
        ->toThrow(OutOfStockException::class, "Stock insufficiente per l'articolo 'SHOE-41'. Richiesti: 10, Disponibili: 5.");
});

it('throws sku not found exception when sku code does not exist', function () {
    $items = [
        new CartItemDTO(skuCode: 'UNKNOWN-SKU', quantity: 1),
    ];

    expect(fn () => $this->action->execute($items))
        ->toThrow(SkuNotFoundException::class, "L'articolo con SKU 'UNKNOWN-SKU' non è presente a catalogo.");
});

it('throws sku not found exception when sku is inactive', function () {
    $this->sku->update(['is_active' => false]);

    $items = [
        new CartItemDTO(skuCode: 'SHOE-41', quantity: 1),
    ];

    expect(fn () => $this->action->execute($items))
        ->toThrow(SkuNotFoundException::class);
});
