<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\NotificacionService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected NotificacionService $service;

    public function __construct(NotificacionService $service)
    {
        $this->service = $service;
    }

    // Obtiene las notificaciones de un usuario
    public function index(Request $request, int $userId)
    {
       /* $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }*/

        $filtros = $request->only([
            'por_pagina',
            'leidas',
            'modulo',
            'tipo',
        ]);

        return response()->json(
            $this->service->getUserNotifications($userId, $filtros)
        );
    }

    // Marca una notificacion como leida
    public function markAsRead(int $userId, int $notificationId)
    {
       /* $user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }*/

        $response = $this->service->markNotificationAsRead($userId, $notificationId);

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    // Marca varias notificaciones como leidas
    public function markManyAsRead(Request $request, int $userId)
    {
        /*$user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }*/

        $request->validate([
            'ids_notificaciones' => 'required|array',
            'ids_notificaciones.*' => 'integer',
        ]);

        $response = $this->service->markNotificationsAsRead(
            $userId,
            $request->ids_notificaciones
        );

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    // Marca todas las notificaciones como leidas
    public function markAllAsRead(int $userId)
    {
        /*$user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }*/

        $response = $this->service->markAllNotificationsAsRead($userId);

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    // Cuenta las notificaciones pendientes de un usuario
    public function countUnread(int $userId)
    {
        /*$user = auth()->user();

        if ($user->id_usuario != $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }*/

        return response()->json([
            'status' => 'success',
            'pendientes' => $this->service->countUnreadNotifications($userId),
        ]);
    }

    // Obtiene el codigo HTTP segun la respuesta del servicio
    private function getStatusCode(array $response): int
    {
        return match ($response['status'] ?? 'success') {
            'not_found' => 404,
            'invalid_payload' => 422,
            default => 200,
        };
    }
}