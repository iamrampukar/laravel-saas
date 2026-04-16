<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StripeAuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('stripe/create', [StripeAuthController::class, 'create'])->name('stripe.create');
Route::get('stripe/login', [StripeAuthController::class, 'login'])->name('stripe.login');
