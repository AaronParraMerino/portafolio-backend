<?php

use App\Http\Controllers\Api\Proyecto\ProyectoParticipanteController;
use Illuminate\Support\Facades\Route;

Route::get('/{id}/participants', [ProyectoParticipanteController::class, 'index']);
Route::get('/{id}/participantes', [ProyectoParticipanteController::class, 'index']);
Route::delete('/{id}/participation', [ProyectoParticipanteController::class, 'detach']);
Route::delete('/{id}/participants/{participacionId}', [ProyectoParticipanteController::class, 'remove']);
