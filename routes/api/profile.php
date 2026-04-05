<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileController;
//para hacerlo sin autenticacion: Route::prefix('profile')->group(function () {

Route::prefix('profile')->middleware('auth:sanctum')->group(function () {

    Route::get('{userId}', [ProfileController::class, 'show']);

    Route::put('{userId}', [ProfileController::class, 'update']);

    Route::patch('{userId}/visibility', [ProfileController::class, 'updateVisibility']);

});