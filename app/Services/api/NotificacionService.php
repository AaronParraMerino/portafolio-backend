<?php

namespace App\Services\api;

use App\Models\Notificacion;

class NotificacionService
{
    // Obtiene las notificaciones de un usuario
    public function getUserNotifications(int $idUsuario, array $filtros = []): array
    {
        $porPagina = (int) ($filtros['por_pagina'] ?? 15);
        $porPagina = max(1, min($porPagina, 50));

        $query = Notificacion::query()
            ->delUsuario($idUsuario)
            ->with([
                'usuarioActor:id_usuario,nombre,apellido,correo',
            ])
            ->when(isset($filtros['leidas']), function ($query) use ($filtros) {
                $leidas = filter_var($filtros['leidas'], FILTER_VALIDATE_BOOLEAN);

                return $leidas
                    ? $query->leidas()
                    : $query->pendientes();
            })
            ->when(!empty($filtros['modulo']), function ($query) use ($filtros) {
                return $query->where('modulo', $filtros['modulo']);
            })
            ->when(!empty($filtros['tipo']), function ($query) use ($filtros) {
                return $query->where('tipo', $filtros['tipo']);
            })
            ->orderByRaw('leida_en IS NULL DESC')
            ->orderByDesc('created_at');

        $notificaciones = $query->paginate($porPagina);

        return [
            'status' => 'success',
            'data' => $notificaciones->items(),
            'meta' => [
                'current_page' => $notificaciones->currentPage(),
                'per_page' => $notificaciones->perPage(),
                'total' => $notificaciones->total(),
                'last_page' => $notificaciones->lastPage(),
            ],
            'resumen' => [
                'pendientes' => $this->countUnreadNotifications($idUsuario),
            ],
        ];
    }

    // Marca una notificacion como leida
    public function markNotificationAsRead(int $idUsuario, int $idNotificacion): array
    {
        $notificacion = Notificacion::query()
            ->delUsuario($idUsuario)
            ->where('id_notificacion', $idNotificacion)
            ->first();

        if (!$notificacion) {
            return [
                'status' => 'not_found',
                'message' => 'Notificacion no encontrada',
            ];
        }

        $notificacion->marcarComoLeida();

        return [
            'status' => 'success',
            'message' => 'Notificacion marcada como leida',
            'data' => $notificacion->fresh(),
            'resumen' => [
                'pendientes' => $this->countUnreadNotifications($idUsuario),
            ],
        ];
    }

    // Marca varias notificaciones como leidas
    public function markNotificationsAsRead(int $idUsuario, array $idsNotificaciones): array
    {
        $ids = collect($idsNotificaciones)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [
                'status' => 'invalid_payload',
                'message' => 'No se enviaron notificaciones validas',
            ];
        }

        $actualizadas = Notificacion::query()
            ->delUsuario($idUsuario)
            ->whereIn('id_notificacion', $ids->all())
            ->whereNull('leida_en')
            ->update([
                'leida_en' => now(),
                'updated_at' => now(),
            ]);

        return [
            'status' => 'success',
            'message' => 'Notificaciones marcadas como leidas',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => $this->countUnreadNotifications($idUsuario),
            ],
        ];
    }

    // Marca todas las notificaciones como leidas
    public function markAllNotificationsAsRead(int $idUsuario): array
    {
        $actualizadas = Notificacion::query()
            ->delUsuario($idUsuario)
            ->whereNull('leida_en')
            ->update([
                'leida_en' => now(),
                'updated_at' => now(),
            ]);

        return [
            'status' => 'success',
            'message' => 'Todas las notificaciones fueron marcadas como leidas',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => 0,
            ],
        ];
    }

    // Cuenta las notificaciones pendientes de un usuario
    public function countUnreadNotifications(int $idUsuario): int
    {
        return Notificacion::query()
            ->delUsuario($idUsuario)
            ->pendientes()
            ->count();
    }
}