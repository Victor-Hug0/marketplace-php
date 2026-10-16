<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OfferReservationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Seller\OfferController as SellerOfferController;
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

    Route::prefix('addresses')
        ->middleware(['auth:sanctum', 'abilities:access'])
        ->group(function () {
            Route::get('/', [AddressController::class, 'index']);
            Route::post('/', [AddressController::class, 'store']);
            Route::post('{address}/default', [AddressController::class, 'makeDefault']);
            Route::get('{address}', [AddressController::class, 'show']);
            Route::put('{address}', [AddressController::class, 'update']);
            Route::patch('{address}', [AddressController::class, 'update']);
            Route::delete('{address}', [AddressController::class, 'destroy']);
        });

    Route::prefix('cart')
        ->middleware(['auth:sanctum', 'abilities:access'])
        ->group(function () {
            Route::get('/', [CartController::class, 'show']);
            Route::post('items', [CartController::class, 'storeItem']);
            Route::patch('items/{item}', [CartController::class, 'updateItem']);
            Route::delete('items/{item}', [CartController::class, 'destroyItem']);
            Route::delete('/', [CartController::class, 'clear']);
        });

    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware(['auth:sanctum', 'abilities:access']);

    Route::prefix('stores')->group(function () {
        Route::middleware(['auth:sanctum', 'abilities:access'])->group(function () {
            Route::post('/', [StoreController::class, 'store']);
            Route::get('me', [StoreController::class, 'me']);
        });

        Route::get('{slug}', [StoreController::class, 'show']);
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

    Route::prefix('seller/offers')
        ->middleware(['auth:sanctum', 'abilities:access', 'role:seller'])
        ->group(function () {
            Route::get('/', [SellerOfferController::class, 'index']);
            Route::post('/', [SellerOfferController::class, 'store']);
            Route::post('{offer}/pause', [SellerOfferController::class, 'pause']);
            Route::post('{offer}/resume', [SellerOfferController::class, 'resume']);
            Route::get('{offer}', [SellerOfferController::class, 'show']);
            Route::put('{offer}', [SellerOfferController::class, 'update']);
            Route::patch('{offer}', [SellerOfferController::class, 'update']);
            Route::delete('{offer}', [SellerOfferController::class, 'destroy']);
        });

    Route::prefix('offers')
        ->middleware(['auth:sanctum', 'abilities:access'])
        ->group(function () {
            Route::post('{offer}/reservations', [OfferReservationController::class, 'store']);
            Route::delete('{offer}/reservations/{reservation}', [OfferReservationController::class, 'destroy']);
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
