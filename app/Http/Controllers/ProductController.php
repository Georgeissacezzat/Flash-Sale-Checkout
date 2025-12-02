<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Hold;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProductController extends Controller
{
    public function getProduct(string $id)
    {
        try {
            //Get product info (cached for 5 minutes)
            $product = Cache::remember(
                "product:info:{$id}",
                now()->addMinutes(5),
                fn () => Product::select('id', 'name', 'price', 'stock')
                        ->findOrFail($id)
            );

            //Get active holds (real-time: must NOT be cached)
            $activeHolds = Hold::where('product_id', $id)
                ->where('expires_at', '>', now())
                ->sum('qty');

            //Calculate final available stock
            $availableStock = $product->stock - $activeHolds;

            //Return JSON response
            return response()->json([
                'id'              => $product->id,
                'name'            => $product->name,
                'price'           => $product->price,
                'available_stock' => $availableStock,
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }
    }
}
