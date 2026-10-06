<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Increments a given number by two.
 */
final class IncrementNumberAction
{
    /**
     * Executes the increment logic.
     */
    public function execute(float|int $number): float|int
    {
        return $number + 2;
    }
}
