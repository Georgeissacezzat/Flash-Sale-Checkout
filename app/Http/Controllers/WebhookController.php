<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\PaymentWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use App\Enums\OrderStatus;

class WebhookController extends Controller
{
    // How many times to retry a database transaction if a deadlock occurs.
    protected int $maxRetries = 5;

    public function handleWebhook(Request $request)
    {
        // Logging 
        Log::info('Payment Webhook Received', [
            'payload' => $request->all(),
            'headers' => $request->headers->all(),
        ]);
        
        $idempotencyKey = $request->input('idempotency_key');
        $orderId = $request->input('order_id');
        $status = $request->input('status');

        $retryCount = 0;

        // Manual retry loop for deadlock handling
        while ($retryCount < $this->maxRetries) {
            try {
                DB::transaction(function () use (&$retryCount, $orderId, $status, $idempotencyKey) {
                    // We attempt to create the record immediately. If it fails due to a unique key violation, 
                    // we know it's a duplicate and the transaction safely exits without error.
                    try {
                        PaymentWebhook::create([
                            'order_id' => $orderId,
                            'idempotency_key' => $idempotencyKey,
                        ]);
                    } catch (QueryException $e) {
                        // Check for duplicate key error code (e.g., 23000 in MySQL)
                        if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                            Log::info('Webhook Duplicate Ignored', [
                                'order_id' => $orderId,
                                'idempotency_key' => $idempotencyKey,
                            ]);
                            return; 
                        }
                        throw $e;
                    }

                    //Lock all related rows (Order, Hold, Product) to safely update their states.
                    $order = Order::lockForUpdate()->findOrFail($orderId);
                    $hold = $order->hold()->lockForUpdate()->firstOrFail();
                    $product = $hold->product()->lockForUpdate()->firstOrFail();

                    // Check if the order is already in a final state (Paid, Cancelled, Failed).
                    // Prevents duplicate stock deductions.
                    if ($order->status->isFinal()) {
                         Log::warning('Order already finalized, ignoring webhook.', ['order_id' => $orderId, 'status' => $order->status->value]);
                         return; 
                    }

                    //Process the result of the payment.
                    if ($status === 'success') {
                        
                        // Update states
                        $order->status = OrderStatus::Paid; 
                        $hold->used = true; 
                        
                        //Permanently deduct the stock quantity.
                        $product->decrement('stock', $hold->qty); 
                        
                        // Clear the stock cache 
                        Cache::forget("product:{$product->id}:available_stock");
                        
                        Log::info('Webhook Payment Success', [
                            'order_id' => $orderId,
                            'qty' => $hold->qty,
                            'new_stock' => $product->stock - $hold->qty, 
                        ]);
                        
                    } elseif ($status === 'failure') {
                        
                        // Update states to cancelled.
                        $order->status = OrderStatus::Cancelled; 
                        $hold->used = true; //Mark hold as used/invalidated so it can't be used again, even on failure.
                        
                         Log::warning('Webhook Payment Failed', [
                            'order_id' => $orderId,
                            'hold_id' => $hold->id,
                        ]);
                    }

                    $order->save();
                    $hold->save();

                });
                break; // If successful, break the loop

            } catch (QueryException $e) {
                //DEADLOCK HANDLING: Check for deadlock error code
                if ($e->getCode() == 40001 || str_contains($e->getMessage(), 'Deadlock found')) {
                    $retryCount++;
                    Log::critical("Deadlock encountered on webhook. Retrying ({$retryCount}/{$this->maxRetries}).");
                    usleep(100000 * $retryCount);
                    continue; 
                }
                throw $e; 

            } catch (ModelNotFoundException $e) {
                return response()->json(['message' => 'Order or related record not found.'], 404);
            } catch (\Exception $e) {
                Log::critical('Webhook processing failed.', ['exception' => $e]);
                return response()->json(['message' => 'Internal server error during processing.'], 500); 
            }
        }
        return response()->json(['status' => 'ok'], 200);
    }
}