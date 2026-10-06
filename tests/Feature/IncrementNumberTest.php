<?php

use function Pest\Laravel\getJson;

it('increments an integer by 2 via route parameter', function () {
    $response = getJson('/api/increment/5');

    $response->assertOk()
        ->assertExactJson([
            'original' => 5,
            'result' => 7,
        ]);
});

it('increments a float by 2 via query parameter', function () {
    $response = getJson('/api/increment?number=10.5');

    $response->assertOk()
        ->assertExactJson([
            'original' => 10.5,
            'result' => 12.5,
        ]);
});

it('returns unprocessable entity when number is non-numeric', function () {
    $response = getJson('/api/increment/invalid-text');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['number']);
});

it('returns unprocessable entity when number is omitted', function () {
    $response = getJson('/api/increment');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['number']);
});
