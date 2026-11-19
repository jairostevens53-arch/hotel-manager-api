<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\GuestController;
use App\Http\Controllers\Api\InventoryMovementController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RateController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StayController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('role:admin,recepcionista,limpieza,mantenimiento')->group(function () {
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
        Route::patch('/rooms/{room}/status', [RoomController::class, 'updateStatus']);
    });

    Route::middleware('role:admin,recepcionista')->group(function () {
        Route::apiResource('guests', GuestController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
        Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
        Route::post('/reservations/{reservation}/check-in', [ReservationController::class, 'checkIn']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::patch('/tasks/{task}/assign', [TaskController::class, 'assign']);
    });

    Route::middleware('role:admin,recepcionista')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);

        Route::post('/shifts/open', [ShiftController::class, 'open']);
        Route::get('/shifts/current', [ShiftController::class, 'current']);
        Route::post('/shifts/close', [ShiftController::class, 'close']);

        Route::get('/sales', [SaleController::class, 'index']);
        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);
        Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel']);

        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);

        Route::get('/rates', [RateController::class, 'index']);
        Route::get('/stays', [StayController::class, 'index']);
        Route::post('/stays', [StayController::class, 'store']);
        Route::get('/stays/{stay}', [StayController::class, 'show']);
        Route::post('/stays/{stay}/extend', [StayController::class, 'extend']);
        Route::post('/stays/{stay}/charge', [StayController::class, 'charge']);
        Route::post('/stays/{stay}/checkout', [StayController::class, 'checkout']);
    });

    // Solo Administrador
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('categories', CategoryController::class)->except(['index']);
        Route::apiResource('products', ProductController::class)->except(['index', 'show']);
        Route::apiResource('suppliers', SupplierController::class)->only(['index', 'store', 'update']);
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
        Route::get('/inventory/movements', [InventoryMovementController::class, 'index']);

        // Gestión administrativa de Habitaciones y Tarifas
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::put('/rooms/{room}', [RoomController::class, 'update']);

        Route::post('/rates', [RateController::class, 'store']);
        Route::put('/rates/{rate}', [RateController::class, 'update']);

        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);

        Route::get('/shifts', [ShiftController::class, 'index']);
        Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->whereNumber('shift');

        Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
    });
});
