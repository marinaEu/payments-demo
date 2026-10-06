<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IncrementNumberAction;
use App\Http\Requests\IncrementNumberRequest;
use Illuminate\Http\JsonResponse;

/**
 * Handles HTTP requests to increment a numeric value by two.
 */
final class IncrementNumberController extends Controller
{
    /**
     * Executes the increment action and returns the result.
     */
    public function __invoke(IncrementNumberRequest $request, IncrementNumberAction $action): JsonResponse
    {
        $input = $request->validated('number');
        $number = is_numeric($input) ? (float) $input : 0.0;
        $result = $action->execute($number);

        return response()->json([
            'original' => $number,
            'result' => $result,
        ]);
    }
}
