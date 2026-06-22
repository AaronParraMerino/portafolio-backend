<?php

namespace App\Services\api\Denuncias;

use App\Models\Denuncia;
use App\Models\Notificacion;
use App\Models\NotificacionUsuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminDenunciaService
{
    private const ESTADOS = ['pendiente', 'en_revision', 'resuelta', 'descartada'];

    public function listar(array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 12), 1), 50);
        $estado = $filters['estado'] ?? null;
        $search = trim((string) ($filters['q'] ?? ''));

        return Denuncia::query()
            ->with(['denunciante:id_usuario,nombre,apellido,correo', 'revisor:id_usuario,nombre,apellido,correo'])
            ->when($estado && in_array($estado, self::ESTADOS, true), fn ($query) => $query->where('estado', $estado))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('asunto', 'ilike', "%{$search}%")
                        ->orWhere('detalle', 'ilike', "%{$search}%")
                        ->orWhereHas('denunciante', function ($userQuery) use ($search) {
                            $userQuery
                                ->where('nombre', 'ilike', "%{$search}%")
                                ->orWhere('apellido', 'ilike', "%{$search}%")
                                ->orWhere('correo', 'ilike', "%{$search}%");
                        });
                });
            })
            ->orderByRaw("CASE estado WHEN 'pendiente' THEN 0 WHEN 'en_revision' THEN 1 WHEN 'resuelta' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function obtener(int $id): Denuncia
    {
        return Denuncia::query()
            ->with(['denunciante:id_usuario,nombre,apellido,correo', 'revisor:id_usuario,nombre,apellido,correo'])
            ->findOrFail($id);
    }

    public function actualizarEstado(Denuncia $denuncia, array $data, int $idAdmin): Denuncia
    {
        return DB::transaction(function () use ($denuncia, $data, $idAdmin) {
            $estado = $data['estado'];
            $respuesta = array_key_exists('respuesta_admin', $data)
                ? trim((string) $data['respuesta_admin'])
                : $denuncia->respuesta_admin;

            $denuncia->fill([
                'estado' => $estado,
                'id_usuario_revisor' => $idAdmin,
                'respuesta_admin' => $respuesta ?: null,
                'revisado_at' => in_array($estado, ['resuelta', 'descartada'], true) ? now() : $denuncia->revisado_at,
            ]);
            $denuncia->save();

            if ($respuesta && in_array($estado, ['resuelta', 'descartada'], true)) {
                $this->notificarRespuesta($denuncia, $idAdmin, $respuesta);
            }

            return $denuncia->fresh(['denunciante:id_usuario,nombre,apellido,correo', 'revisor:id_usuario,nombre,apellido,correo']);
        });
    }

    public function serializar(Denuncia $denuncia): array
    {
        return [
            'id_denuncia' => (int) $denuncia->id_denuncia,
            'asunto' => $denuncia->asunto,
            'detalle' => $denuncia->detalle,
            'estado' => $denuncia->estado,
            'motivo' => $denuncia->motivo,
            'evidencia' => $denuncia->evidencia ?? [],
            'metadata' => $denuncia->metadata ?? [],
            'respuesta_admin' => $denuncia->respuesta_admin,
            'created_at' => optional($denuncia->created_at)->toISOString(),
            'updated_at' => optional($denuncia->updated_at)->toISOString(),
            'revisado_at' => optional($denuncia->revisado_at)->toISOString(),
            'denunciante' => $this->serializarUsuario($denuncia->denunciante),
            'revisor' => $this->serializarUsuario($denuncia->revisor),
        ];
    }

    private function notificarRespuesta(Denuncia $denuncia, int $idAdmin, string $respuesta): void
    {
        $notificacion = Notificacion::create([
            'id_usuario_actor' => $idAdmin,
            'modulo' => 'administracion',
            'contexto_tipo' => 'denuncia',
            'contexto_referencia' => (string) $denuncia->id_denuncia,
            'grupo_titulo' => 'Soporte',
            'tipo' => 'denuncia_respuesta',
            'mensaje' => 'Respuesta de soporte a tu reporte: ' . $denuncia->asunto . "\n" . $respuesta,
            'metadata' => [
                'id_denuncia' => (int) $denuncia->id_denuncia,
                'estado' => $denuncia->estado,
                'asunto' => $denuncia->asunto,
            ],
        ]);

        NotificacionUsuario::create([
            'id_notificacion' => $notificacion->id_notificacion,
            'id_usuario' => $denuncia->id_denunciante,
        ]);
    }

    private function serializarUsuario($usuario): ?array
    {
        if (! $usuario) {
            return null;
        }

        return [
            'id_usuario' => (int) $usuario->id_usuario,
            'nombre' => trim(implode(' ', array_filter([$usuario->nombre, $usuario->apellido]))),
            'correo' => $usuario->correo,
        ];
    }
}
