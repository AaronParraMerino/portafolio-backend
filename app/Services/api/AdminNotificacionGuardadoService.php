<?php

namespace App\Services\api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminNotificacionGuardadoService
{
    /**
     * Crea un aviso administrativo para usuarios especificos
     */
    public function createAdminNotice(int $idUsuarioActor, array $data): array
    {
        $destinatarios = collect($data['destinatarios'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($destinatarios->isEmpty()) {
            return $this->sinAccion('No hay destinatarios validos');
        }

        $mensaje = trim((string) ($data['mensaje'] ?? $data['contenido'] ?? ''));

        if ($mensaje === '') {
            return $this->sinAccion('El mensaje no puede estar vacio');
        }

        $tipo = trim((string) ($data['tipo'] ?? 'general'));
        $envioId = (string) Str::uuid();

        $idNotificacion = DB::transaction(function () use (
            $idUsuarioActor,
            $data,
            $destinatarios,
            $mensaje,
            $tipo,
            $envioId
        ): int {
            $idNotificacion = DB::table('notificaciones')->insertGetId([
                'id_usuario_actor' => $idUsuarioActor,
                'modulo' => 'administracion',
                'tipo' => 'admin_notice_' . $tipo,
                'mensaje' => $mensaje,
                'contexto_referencia' => 'admin_' . $envioId,
                'grupo_titulo' => 'Administracion',
                'created_at' => now(),
                'updated_at' => now(),
            ], 'id_notificacion');

            $now = now();

            $registros = $destinatarios
                ->map(fn ($idUsuarioDestino) => [
                    'id_notificacion' => $idNotificacion,
                    'id_usuario' => $idUsuarioDestino,
                    'leido_en' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            DB::table('notificacion_usuario')->insert($registros);

            return (int) $idNotificacion;
        });

        return [
            'status' => 'success',
            'message' => 'Aviso enviado correctamente',
            'data' => [
                'id_notificacion' => $idNotificacion,
                'id_envio' => $envioId,
                'tipo' => $tipo,
                'mensaje' => $mensaje,
                'urgencia' => $data['urgencia'] ?? null,
                'canales' => $data['canales'] ?? ['inapp'],
                'segmentos' => $data['segmentos'] ?? [],
                'destinatarios' => $destinatarios->count(),
                'created_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Respuesta sin accion
     */
    private function sinAccion(string $motivo): array
    {
        return [
            'status' => 'sin_accion',
            'message' => $motivo,
        ];
    }
}