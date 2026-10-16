<?php

use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Seller\ProductController as SellerProductController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'abilities:access']);
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware(['auth:sanctum', 'abilities:refresh']);
    });

    Route::prefix('users')->group(function () {
        Route::get('me', [UserController::class, 'me'])->middleware(['auth:sanctum', 'abilities:access']);
        Route::get('/', [UserController::class, 'index'])->middleware(['auth:sanctum', 'abilities:access', 'role:admin']);
    });

    Route::prefix('stores')
        ->middleware(['auth:sanctum', 'abilities:access'])
        ->group(function () {
            Route::post('/', [StoreController::class, 'store']);
            Route::get('me', [StoreController::class, 'me']);
        });

    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('{slug}', [CategoryController::class, 'show']);
    });

    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('{slug}', [ProductController::class, 'show']);
    });

    Route::prefix('seller/products')
        ->middleware(['auth:sanctum', 'abilities:access', 'role:seller'])
        ->group(function () {
            Route::get('/', [SellerProductController::class, 'index']);
            Route::post('/', [SellerProductController::class, 'store']);
            Route::post('{product}/submit', [SellerProductController::class, 'submit']);
            Route::post('{product}/pause', [SellerProductController::class, 'pause']);
            Route::post('{product}/resume', [SellerProductController::class, 'resume']);
            Route::post('{product}/images', [SellerProductController::class, 'storeImage']);
            Route::put('{product}/images/reorder', [SellerProductController::class, 'reorderImages']);
            Route::delete('{product}/images/{image}', [SellerProductController::class, 'destroyImage']);
            Route::get('{product}', [SellerProductController::class, 'show']);
            Route::put('{product}', [SellerProductController::class, 'update']);
            Route::patch('{product}', [SellerProductController::class, 'update']);
            Route::delete('{product}', [SellerProductController::class, 'destroy']);
        });

    Route::prefix('admin/stores')
        ->middleware(['auth:sanctum', 'abilities:access', 'role:admin'])
        ->group(function () {
            Route::get('/', [AdminStoreController::class, 'index']);
            Route::post('{store}/approve', [AdminStoreController::class, 'approve']);
            Route::post('{store}/reject', [AdminStoreController::class, 'reject']);
            Route::post('{store}/suspend', [AdminStoreController::class, 'suspend']);
        });

    Route::prefix('admin/categories')
        ->middleware(['auth:sanctum', 'abilities:access', 'role:admin'])
        ->group(function () {
            Route::get('/', [AdminCategoryController::class, 'index']);
            Route::post('/', [AdminCategoryController::class, 'store']);
            Route::put('{category}', [AdminCategoryController::class, 'update']);
            Route::patch('{category}', [AdminCategoryController::class, 'update']);
            Route::delete('{category}', [AdminCategoryController::class, 'destroy']);
            Route::post('{category}/attributes', [AdminCategoryController::class, 'storeAttribute']);
            Route::put('{category}/attributes/{attribute}', [AdminCategoryController::class, 'updateAttribute']);
            Route::delete('{category}/attributes/{attribute}', [AdminCategoryController::class, 'destroyAttribute']);
        });

    Route::prefix('admin/products')
        ->middleware(['auth:sanctum', 'abilities:access', 'role:admin'])
        ->group(function () {
            Route::get('/', [AdminProductController::class, 'index']);
            Route::get('{product}', [AdminProductController::class, 'show']);
            Route::put('{product}', [AdminProductController::class, 'update']);
            Route::patch('{product}', [AdminProductController::class, 'update']);
            Route::post('{product}/approve', [AdminProductController::class, 'approve']);
            Route::post('{product}/reject', [AdminProductController::class, 'reject']);
            Route::post('{product}/pause', [AdminProductController::class, 'pause']);
        });
});
