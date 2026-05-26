<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileController;
//para hacerlo sin autenticacion: 
//Route::prefix('profile')->group(function () {

Route::prefix('profile')->middleware(['auth:sanctum', 'account.writable'])->group(function () {

    Route::get('{userId}', [ProfileController::class, 'show']);

    Route::put('{userId}', [ProfileController::class, 'update']);

    Route::patch('{userId}/visibility', [ProfileController::class, 'updateVisibility']);

    Route::patch('{userId}/portfolio-visibility', [ProfileController::class, 'updatePortfolioVisibility']);

    
    Route::post('{userId}/image', [ProfileController::class, 'uploadImage']);

    Route::post('{userId}/image/update', [ProfileController::class, 'updateImage']);

    Route::delete('{userId}/image/delete', [ProfileController::class, 'deleteImage']);

});
