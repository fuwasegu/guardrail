<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

// Basic routes
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::get('/users/{id}', [UserController::class, 'show']);

// Grouped routes with middleware
Route::middleware(['auth:api'])->group(function () {
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});

// Grouped routes with prefix
Route::prefix('orders')->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
});

// Module routes (modular monolith style)
Route::prefix('billing')
    ->middleware(['auth:api'])
    ->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::post('/invoices', [InvoiceController::class, 'create']);
    });
