<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\FlashPayController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('/flashpay/deposit', [PaymentController::class, 'deposit']);
Route::post('/flashpay/callback', [PaymentController::class, 'callback']);

/* Abpay Routes */
Route::post('/abpay/unified-order', [FlashPayController::class, 'unifiedOrder']);
