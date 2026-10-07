<?php

declare(strict_types=1);

use function Pest\Laravel\getJson;

it('generates openapi swagger documentation containing registered endpoints and schemas', function () {
    $response = getJson('/docs/api.json');

    $response->assertOk()
        ->assertJsonStructure([
            'openapi',
            'info',
            'paths',
        ]);

    $paths = $response->json('paths');

    expect($paths)->toHaveKey('/checkout')
        ->and($paths)->toHaveKey('/increment/{number}')
        ->and($paths['/checkout'])->toHaveKey('post');

    $checkoutPost = $paths['/checkout']['post'];
    expect($checkoutPost)->toHaveKey('responses')
        ->and($checkoutPost)->toHaveKey('requestBody');
});
