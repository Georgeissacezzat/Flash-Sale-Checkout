<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\HoldController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WebhookController;


//Product details and accurate available stock
Route::get('/products/{id}', [ProductController::class, 'getProduct']);

//Create a temporary hold (reservation)
Route::post('/holds', [HoldController::class, 'createHold']);

//Create Order
Route::post('/orders', [OrderController::class, 'createOrder']);

//Payment Webhook 
Route::post('/payments/webhook', [WebhookController::class, 'handleWebhook']);