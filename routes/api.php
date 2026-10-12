<?php

use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductAuditController as AdminProductAuditController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\HelloWorldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PromotionController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', HelloWorldController::class);

Route::controller(ProductController::class)->prefix('products')->name('products.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{product}', 'show')->whereNumber('product')->name('show');
});

Route::controller(CategoryController::class)->prefix('categories')->name('categories.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{slug}', 'show')->name('show');
});

Route::middleware('optional.sanctum')->controller(CartController::class)->prefix('cart')->name('cart.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::delete('/', 'clear')->name('clear');
    Route::post('/items', 'store')->name('items.store');
    Route::patch('/items/{item}', 'update')->whereNumber('item')->name('items.update');
    Route::delete('/items/{item}', 'destroy')->whereNumber('item')->name('items.destroy');

    Route::post('/promotion', 'applyPromotion')->middleware('throttle:promotions')->name('promotion.apply');
    Route::delete('/promotion', 'removePromotion')->name('promotion.remove');
});

Route::middleware(['optional.sanctum', 'throttle:promotions'])
    ->post('/promotions/validate', [PromotionController::class, 'validateCode'])
    ->name('promotions.validate');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:checkout')
        ->name('checkout.store');

    Route::controller(OrderController::class)->prefix('orders')->name('orders.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{order}', 'show')->whereNumber('order')->name('show');
        Route::post('/{order}/pay', 'pay')
            ->middleware('throttle:checkout')
            ->whereNumber('order')
            ->name('pay');
        Route::post('/{order}/cancel', 'cancel')
            ->middleware('throttle:checkout')
            ->whereNumber('order')
            ->name('cancel');
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::apiResource('categories', AdminCategoryController::class);
    Route::apiResource('products', AdminProductController::class);
    Route::post('/products/{product}/restore', [AdminProductController::class, 'restore'])
        ->whereNumber('product')
        ->name('products.restore');
    Route::get('/products/{product}/audits', [AdminProductAuditController::class, 'index'])
        ->whereNumber('product')
        ->name('products.audits');
    Route::get('/audits', [AdminProductAuditController::class, 'indexAll'])->name('audits.index');
    Route::apiResource('promotions', AdminPromotionController::class);
    Route::post('/promotions/{promotion}/restore', [AdminPromotionController::class, 'restore'])
        ->whereNumber('promotion')
        ->name('promotions.restore');

    Route::controller(AdminOrderController::class)->prefix('orders')->name('orders.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{order}', 'show')->whereNumber('order')->name('show');
        Route::patch('/{order}', 'update')->whereNumber('order')->name('update');
    });
});

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', 'register')->name('register');
        Route::post('/login', 'login')->name('login');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', 'logout')->name('logout');
        Route::post('/logout-all', 'logoutAll')->name('logout-all');
        Route::get('/me', 'me')->name('me');
    });
});
