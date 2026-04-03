<?php

use Illuminate\Support\Facades\Route;
use App\Profile\Controllers\ProfileController;

Route::prefix('profile')->group(function () {

    Route::get('{userId}', [ProfileController::class, 'show']);

    Route::put('{userId}', [ProfileController::class, 'update']);

    Route::patch('{userId}/visibility', [ProfileController::class, 'updateVisibility']);
});