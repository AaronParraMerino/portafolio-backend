<?php

namespace App\Services\api\Denuncias;

use App\Models\Denuncia;
use App\Models\Notificacion;
use App\Models\NotificacionUsuario;
use App\Models\Usuario;

class DenunciaNotificacionService
{
    public function notificarNuevaDenuncia(Denuncia $denuncia): array
    {
        $adminIds = Usuario::query()
            ->where('rol', 'admin')
            ->pluck('id_usuario')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($adminIds->isEmpty()) {
            return [
                'status' => 'sin_accion',
                'destinatarios' => 0,
            ];
        }

        $notificacion = Notificacion::create([
            'id_usuario_actor' => $denuncia->id_denunciante,
            'modulo' => 'administracion',
            'contexto_tipo' => 'denuncia',
            'contexto_referencia' => (string) $denuncia->id_denuncia,
            'grupo_titulo' => 'Reportes de usuarios',
            'tipo' => 'denuncia_nueva',
            'mensaje' => 'Nuevo reporte de usuario: ' . $denuncia->asunto,
            'metadata' => [
                'id_denuncia' => (int) $denuncia->id_denuncia,
                'asunto' => $denuncia->asunto,
                'estado' => $denuncia->estado,
                'id_denunciante' => (int) $denuncia->id_denunciante,
            ],
        ]);

        $now = now();
        NotificacionUsuario::insert($adminIds
            ->map(fn (int $idAdmin) => [
                'id_notificacion' => $notificacion->id_notificacion,
                'id_usuario' => $idAdmin,
                'leido_en' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all());

        return [
            'status' => 'success',
            'id_notificacion' => (int) $notificacion->id_notificacion,
            'destinatarios' => $adminIds->count(),
        ];
    }
}
