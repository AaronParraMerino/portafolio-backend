<?php

use App\Http\Controllers\Api\DenunciaController;
use Illuminate\Support\Facades\Route;

Route::prefix('denuncias')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::post('/', [DenunciaController::class, 'store']);
});
