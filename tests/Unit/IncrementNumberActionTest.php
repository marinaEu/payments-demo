<?php

use App\Actions\IncrementNumberAction;

it('increments an integer by 2', function () {
    $action = new IncrementNumberAction();

    expect($action->execute(4))->toBe(6);
});

it('increments a float by 2', function () {
    $action = new IncrementNumberAction();

    expect($action->execute(2.5))->toBe(4.5);
});
