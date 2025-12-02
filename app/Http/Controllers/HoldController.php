<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException; 

class HoldController extends Controller
{
    protected int $holdDurationMinutes = 2; 
    protected int $maxRetries = 5; 

    public function createHold(Request $request) 
    {
        $productId = $request->input('product_id');
        $qty = $request->input('qty');

        $retryCount = 0;
        $hold = null;

        while ($retryCount < $this->maxRetries) {
            try {
                $hold = DB::transaction(function () use ($productId, $qty) {
                    // Lock product row
                    $product = Product::lockForUpdate()->findOrFail($productId);

                    // Calculate reserved stock
                    $reservedQuantity = Hold::where('product_id', $product->id)
                        ->where('used', false)
                        ->where('expires_at', '>', now())
                        ->sum('qty');

                    $availableStock = max(0, $product->stock - $reservedQuantity);

                    // Throw exception if requested qty exceeds stock
                    if ($qty > $availableStock) {
                        throw ValidationException::withMessages([
                            'qty' => "Requested quantity exceeds available stock ."
                        ]);
                    }

                    // Create hold
                    $hold = Hold::create([
                        'product_id' => $product->id,
                        'qty' => $qty,
                        'expires_at' => now()->addMinutes($this->holdDurationMinutes),
                        'used' => false,
                    ]);

                    // Clear cache
                    Cache::forget("product:{$product->id}:available_stock");

                    return $hold;
                });

                break; // Success, exit retry loop

            } catch (QueryException $e) {
                // Handle deadlock retry
                if ($e->getCode() == 40001 || str_contains($e->getMessage(), 'Deadlock found')) {
                    $retryCount++;
                    Log::warning("Deadlock encountered. Retrying ({$retryCount}/{$this->maxRetries})...");
                    usleep(100000 * $retryCount);
                    continue;
                }
                throw $e;

            } catch (ValidationException $e) {
                // Return proper validation error
                return response()->json(['errors' => $e->errors()], 422);

            } catch (\Exception $e) {
                Log::error('Hold creation failed', ['exception' => $e]);
                return response()->json(['message' => 'Could not reserve stock due to system error.'], 500);
            }
        }

        if (!$hold) {
            return response()->json(['message' => 'Could not reserve stock due to high load. Please try again.'], 500);
        }

        return response()->json([
            'hold_id' => $hold->id,
            'quantity' => $hold->qty,
            'expires_at' => $hold->expires_at->toDateTimeString(),
            'message' => 'Stock successfully reserved for checkout.',
        ], 201);
    }
}