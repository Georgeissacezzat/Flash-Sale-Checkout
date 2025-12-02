<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use App\Enums\OrderStatus;

class OrderController extends Controller
{
    // How many times to retry a database transaction if a deadlock occurs.
    protected int $maxRetries = 5;

    public function createOrder(Request $request)
    {   
        $holdId = $request->input('hold_id');

        $retryCount = 0;
        $order = null;

        // Manual retry loop for deadlock handling
        while ($retryCount < $this->maxRetries) {
            try {
                $order = DB::transaction(function () use ($holdId) {
                    
                    //Use lockForUpdate() on the fetch to acquire the lock immediately.
                    $hold = Hold::lockForUpdate()->findOrFail($holdId);
                    
                    //Ensure the hold is still available *while the row is locked*.
                    if ($hold->used || $hold->expires_at < now()) {
                        throw ValidationException::withMessages(['hold_id' => 'Reservation is expired or already processed.']);
                    }
                    
                    //Mark the hold as used *before* creating the order to ensure
                    $hold->used = true;
                    $hold->save(); 

                    //Use the correct Enum status.
                    $order = Order::create([
                        'hold_id' => $hold->id,
                        'status' => OrderStatus::PrePayment,
                    ]);
                    
                    return $order;

                });
                break;

            } catch (QueryException $e) {
                 if ($e->getCode() == 40001 || str_contains($e->getMessage(), 'Deadlock found')) {
                    $retryCount++;
                    Log::warning("Deadlock encountered on order creation. Retrying ({$retryCount}/{$this->maxRetries}).");
                    usleep(100000 * $retryCount);
                    continue;
                }
                // Handle unique constraint failure (ensuring hold_id is unique on the Orders table)
                if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    return response()->json(['message' => 'This reservation has already been used to create an order.'], 409);
                }
                throw $e;
            } catch (\Exception $e) {
                Log::error('Order creation failed.', ['exception' => $e]);
                return response()->json(['message' => 'Could not create order due to system error.'], 500);
            }
        }
        
        if ($order) {
            return response()->json([
                'order_id' => $order->id,
                'status' => $order->status->value,
                'message' => 'Order created and ready for payment.',
            ], 201);
        } else {
             return response()->json(['message' => 'Could not create order due to system error.'], 500);
        }
    }
}
