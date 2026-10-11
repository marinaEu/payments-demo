<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with demo catalog products, SKUs and coupons.
     */
    public function run(): void
    {
        if (Product::where('name', 'Scarpe da Corsa Pro')->exists()) {
            return;
        }

        // 1. Prodotto: Scarpe da Corsa Pro
        $shoes = Product::create([
            'name' => 'Scarpe da Corsa Pro',
            'description' => 'Scarpe da running tecniche ad alte prestazioni',
            'is_active' => true,
        ]);

        $shoes->skus()->createMany([
            [
                'code' => 'RUN-42',
                'name' => 'Taglia 42 - Nero/Rosso',
                'price_amount' => 100.00,
                'stock' => 10,
                'is_active' => true,
            ],
            [
                'code' => 'RUN-43',
                'name' => 'Taglia 43 - Nero/Rosso',
                'price_amount' => 100.00,
                'stock' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'RUN-44',
                'name' => 'Taglia 44 - Nero/Rosso (Esaurito)',
                'price_amount' => 100.00,
                'stock' => 0, // Per testare OutOfStock 409
                'is_active' => true,
            ],
        ]);

        // 2. Prodotto: T-Shirt Tecnica DryFit
        $tshirt = Product::create([
            'name' => 'T-Shirt Tecnica DryFit',
            'description' => 'Maglia traspirante per allenamento',
            'is_active' => true,
        ]);

        $tshirt->skus()->createMany([
            [
                'code' => 'TSHIRT-BLK-M',
                'name' => 'Nero / Taglia M',
                'price_amount' => 35.00,
                'stock' => 20,
                'is_active' => true,
            ],
            [
                'code' => 'TSHIRT-RED-L',
                'name' => 'Rosso / Taglia L',
                'price_amount' => 35.00,
                'stock' => 15,
                'is_active' => true,
            ],
        ]);

        // 3. Coupon promozionali
        Coupon::create([
            'code' => 'DISCOUNT10',
            'type' => CouponType::PERCENTAGE,
            'value' => 10.00,
            'is_active' => true,
            'expires_at' => now()->addMonths(6),
        ]);

        Coupon::create([
            'code' => 'FLAT15',
            'type' => CouponType::FIXED,
            'value' => 15.00,
            'is_active' => true,
            'expires_at' => now()->addMonths(6),
        ]);

        Coupon::create([
            'code' => 'SCADUTO20',
            'type' => CouponType::PERCENTAGE,
            'value' => 20.00,
            'is_active' => true,
            'expires_at' => now()->subDay(), // Per testare InvalidCouponException 422
        ]);
    }
}
