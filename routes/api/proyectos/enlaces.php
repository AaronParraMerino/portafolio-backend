<?php

use App\Http\Controllers\Api\Proyecto\ProyectoEnlaceController;
use Illuminate\Support\Facades\Route;

Route::patch('/{id}/links', [ProyectoEnlaceController::class, 'update']);
