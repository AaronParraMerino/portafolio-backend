<?php

use App\Http\Controllers\Api\Administrador\EventoController;
use Illuminate\Support\Facades\Route;

Route::get('/publicantes/solicitudes', [EventoController::class, 'publisherRequests']);
Route::patch('/publicantes/solicitudes/{id}/aprobar', [EventoController::class, 'approvePublisherRequest'])->whereNumber('id');
Route::patch('/publicantes/solicitudes/{id}/rechazar', [EventoController::class, 'rejectPublisherRequest'])->whereNumber('id');
