<?php

use App\Http\Controllers\Api\AdminAvisoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AdminAvisoController::class, 'index']);
Route::get('{idAviso}', [AdminAvisoController::class, 'show']);
