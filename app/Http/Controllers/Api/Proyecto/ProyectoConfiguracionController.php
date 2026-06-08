<?php

namespace App\Http\Controllers\Api\Proyecto;

use App\Http\Controllers\Controller;
use App\Services\api\Proyecto\ProyectoPermisoService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProyectoConfiguracionController extends Controller
{
    public function __construct(
        private readonly ProyectoPermisoService $proyectoPermisoService,
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
    ) {}

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);

        if (! $this->proyectoPermisoService->userHasAccess($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        $permissions = $this->proyectoPermisoService->resolve($userId, $id);
        if (! $permissions['puede_configurar']) {
            return response()->json(['message' => 'No tienes permiso para configurar este proyecto'], 403);
        }

        return response()->json([
            'data' => [
                'configuracion' => $this->proyectoPermisoService->configuration($id),
                'permisos' => $permissions,
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);

        if (! $this->proyectoPermisoService->userHasAccess($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        $permissions = $this->proyectoPermisoService->resolve($userId, $id);
        if (! $permissions['puede_configurar']) {
            return response()->json(['message' => 'No tienes permiso para configurar este proyecto'], 403);
        }

        $payload = $request->validate([
            'permitir_participantes_sin_validacion' => 'sometimes|boolean',
            'puede_editar_proyecto' => 'sometimes|in:propietarios,autoridad_github,participantes_validados,participantes',
            'puede_administrar_proyecto' => 'sometimes|in:propietarios,autoridad_github',
            'github_nivel_autoridad' => 'sometimes|in:owner,maintainer,admin_push',
            'github_prevalece_sobre_creador' => 'sometimes|boolean',
            'visibilidad_usuario_sin_validacion' => 'sometimes|in:oculto,visible',
            'permitir_remover_participantes_sin_validacion' => 'sometimes|boolean',
        ]);

        foreach ([
            'permitir_participantes_sin_validacion',
            'github_prevalece_sobre_creador',
            'permitir_remover_participantes_sin_validacion',
        ] as $booleanField) {
            if (array_key_exists($booleanField, $payload)) {
                $payload[$booleanField] = $this->proyectoPermisoService->postgresBool($payload[$booleanField]);
            }
        }

        $payload['updated_at'] = now();

        DB::table('proyecto_configuraciones')
            ->where('id_proyecto', $id)
            ->update($payload);

        $this->proyectoNotificacionGuardadoService->notificarConfiguracionActualizada(
            idProyecto: $id,
            idUsuarioActor: $userId
        );

        return response()->json([
            'message' => 'Configuracion actualizada correctamente',
            'data' => [
                'configuracion' => $this->proyectoPermisoService->configuration($id),
                'permisos' => $this->proyectoPermisoService->resolve($userId, $id),
            ],
        ]);
    }
}
