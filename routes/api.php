<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Todos los roles
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    Route::post('/shifts/open', [ShiftController::class, 'open']);
    Route::get('/shifts/current', [ShiftController::class, 'current']);
    Route::post('/shifts/close', [ShiftController::class, 'close']);

    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);

    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);

    // Solo admin
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('categories', CategoryController::class)->except(['index']);
        Route::apiResource('products', ProductController::class)->except(['index', 'show']);
        Route::apiResource('suppliers', SupplierController::class)->only(['index', 'store', 'update']);

        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);

        Route::get('/shifts', [ShiftController::class, 'index']);
        Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->whereNumber('shift');

        Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
    });
});
