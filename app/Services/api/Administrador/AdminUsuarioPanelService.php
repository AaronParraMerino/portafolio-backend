<?php

namespace App\Services\api\Administrador;

use App\Models\AdminUsuarioPlantilla;
use App\Models\Aviso;
use App\Models\Notificacion;
use App\Models\SesionBase;
use App\Models\Usuario;
use App\Services\api\ProfileImageVariantService;
use Illuminate\Support\Facades\DB;

class AdminUsuarioPanelService
{
    public function __construct(private readonly ProfileImageVariantService $profileImageVariants) {}

    public function getPanel(): array
    {
        $users = Usuario::query()
            ->with('perfil:id_perfil,usuario_id,foto_perfil')
            ->where('rol', '!=', 'admin')
            ->select(['id_usuario', 'nombre', 'apellido', 'correo', 'rol', 'estado', 'created_at'])
            ->orderBy('id_usuario')
            ->get();

        $communications = $this->communications($users->count());

        return [
            'items' => $this->users($users),
            'communications' => $communications,
            'history' => $communications->take(20)->values(),
            'templates' => $this->templates(),
            'metrics' => [
                'total' => $users->count(),
                'activo' => $users->where('estado', 'activo')->count(),
                'pausado' => $users->where('estado', 'pausado')->count(),
                'bloqueado' => $users->where('estado', 'bloqueado')->count(),
                'inactivo' => $users->where('estado', 'inactivo')->count(),
            ],
            'sourceReady' => true,
            'supportsMutations' => false,
            'supportsSessions' => true,
            'supportsActivation' => true,
            'supportsPausing' => true,
            'supportsBlocking' => true,
            'supportsInactivation' => true,
            'supportsCommunications' => true,
            'supportsRoleManagement' => true,
        ];
    }

    private function users($users)
    {
        $userIds = $users->pluck('id_usuario');
        $sessionCounts = SesionBase::query()
            ->whereNotNull('personal_access_token_id')
            ->whereIn('usuario_id', $userIds)
            ->selectRaw('usuario_id, COUNT(*) as total')
            ->groupBy('usuario_id')
            ->pluck('total', 'usuario_id');

        $lastSessions = SesionBase::query()
            ->whereIn('usuario_id', $userIds)
            ->orderByDesc('ultima_actividad')
            ->get([
                'usuario_id',
                'ultima_actividad',
                'ip_address',
                'pais_codigo',
                'navegador_nombre',
                'sistema_operativo',
                'es_movil',
            ])
            ->unique('usuario_id')
            ->keyBy('usuario_id');

        return $users->map(function (Usuario $user) use ($sessionCounts, $lastSessions): array {
            /** @var SesionBase|null $lastSession */
            $lastSession = $lastSessions->get($user->id_usuario);

            return [
                'id' => $user->id_usuario,
                'nombre' => trim($user->nombre.' '.$user->apellido),
                'email' => $user->correo,
                'rol' => $user->rol,
                'estado' => $user->estado,
                'fotoPerfilThumbUrl' => $this->profileImageVariants->getVariantUrl($user->perfil?->foto_perfil, 'thumb')
                    ?? $user->perfil?->foto_perfil,
                'fechaRegistro' => $user->created_at?->format('d/m/Y'),
                'sesionesActivas' => (int) ($sessionCounts[$user->id_usuario] ?? 0),
                'ultimoAcceso' => $lastSession?->ultima_actividad?->format('d/m/Y H:i'),
                'ultimaSesion' => $lastSession ? [
                    'ip_address' => $lastSession->ip_address,
                    'pais_codigo' => $lastSession->pais_codigo,
                    'navegador_nombre' => $lastSession->navegador_nombre,
                    'sistema_operativo' => $lastSession->sistema_operativo,
                    'es_movil' => (bool) $lastSession->es_movil,
                    'ultima_actividad' => $lastSession->ultima_actividad?->toISOString(),
                ] : null,
            ];
        })->values();
    }

    private function communications(int $userCount)
    {
        $adminNotificationRows = Notificacion::query()
            ->withCount('notificacionUsuarios as destinatarios_count')
            ->where('modulo', 'administracion')
            ->where('tipo', 'like', 'admin_notice_%')
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();
        $singleRecipientIds = $adminNotificationRows
            ->filter(fn (Notificacion $notice) => (int) $notice->destinatarios_count === 1)
            ->pluck('id_notificacion')
            ->values();
        $singleRecipients = $singleRecipientIds->isEmpty()
            ? collect()
            : DB::table('notificacion_usuario as nu')
                ->join('usuarios as u', 'u.id_usuario', '=', 'nu.id_usuario')
                ->whereIn('nu.id_notificacion', $singleRecipientIds)
                ->select([
                    'nu.id_notificacion',
                    'u.id_usuario',
                    'u.nombre',
                    'u.apellido',
                    'u.correo',
                    'u.rol',
                    'u.estado',
                ])
                ->get()
                ->keyBy('id_notificacion');

        $adminCommunications = $adminNotificationRows
            ->map(function (Notificacion $notice) use ($singleRecipients): array {
                $metadata = is_array($notice->metadata) ? $notice->metadata : [];
                $destinatarios = (int) $notice->destinatarios_count;
                $audienceKind = $metadata['audiencia_tipo'] ?? ($destinatarios === 1 ? 'individual' : 'segmentada');
                $segments = $metadata['segmentos'] ?? [];
                $singleRecipient = $destinatarios === 1
                    ? $singleRecipients->get($notice->id_notificacion)
                    : null;
                $canDuplicate = ($audienceKind !== 'individual' && ! empty($segments)) || $singleRecipient !== null;

                return [
                    'id' => $notice->contexto_referencia ?? $notice->id_notificacion,
                    'source' => 'admin_notification',
                    'audience_kind' => $audienceKind,
                    'id_notificacion' => (int) $notice->id_notificacion,
                    'titulo' => $notice->grupo_titulo ?? $metadata['titulo'] ?? 'Administracion',
                    'cuerpo' => $notice->mensaje,
                    'tipo' => str_replace('admin_notice_', '', $notice->tipo),
                    'estado' => 'enviado',
                    'urgencia' => $metadata['urgencia'] ?? 'baja',
                    'destinatarios' => $destinatarios,
                    'creado' => $notice->created_at?->format('d/m/Y H:i'),
                    'created_at' => $notice->created_at?->toISOString(),
                    'segmentos' => $segments,
                    'canales' => $metadata['canales'] ?? ['inapp'],
                    'destinatario' => $singleRecipient ? [
                        'id' => (int) $singleRecipient->id_usuario,
                        'id_usuario' => (int) $singleRecipient->id_usuario,
                        'nombre' => trim($singleRecipient->nombre.' '.$singleRecipient->apellido),
                        'email' => $singleRecipient->correo,
                        'rol' => $singleRecipient->rol,
                        'estado' => $singleRecipient->estado,
                    ] : null,
                    'editable' => false,
                    'deletable' => false,
                    'actions' => $canDuplicate ? ['view', 'duplicate'] : ['view'],
                ];
            })
            ->values();

        $globalCommunications = Aviso::query()
            ->where('estado', '!=', Aviso::ESTADO_ELIMINADO)
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->map(fn (Aviso $notice): array => [
                'id' => 'global_'.$notice->id_aviso,
                'source' => 'global_aviso',
                'id_aviso' => (int) $notice->id_aviso,
                'titulo' => $notice->titulo,
                'cuerpo' => $notice->mensaje,
                'tipo' => $notice->tipo,
                'estado' => $notice->estado === Aviso::ESTADO_ACTIVO ? 'enviado' : 'archivado',
                'estado_aviso' => $notice->estado,
                'urgencia' => match ($notice->prioridad) {
                    Aviso::PRIORIDAD_NORMAL => 'media',
                    Aviso::PRIORIDAD_CRITICA => 'alta',
                    default => $notice->prioridad,
                },
                'destinatarios' => $userCount,
                'creado' => $notice->created_at?->format('d/m/Y H:i'),
                'created_at' => $notice->created_at?->toISOString(),
                'segmentos' => ['todos'],
                'canales' => ['inapp'],
                'editable' => true,
                'deletable' => true,
                'actions' => ['edit', 'toggle_status', 'delete'],
            ]);

        return $adminCommunications->concat($globalCommunications)->sortByDesc('created_at')->values();
    }

    private function templates()
    {
        return AdminUsuarioPlantilla::query()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (AdminUsuarioPlantilla $template): array => [
                'id' => $template->id_plantilla,
                'titulo' => $template->titulo,
                'cuerpo' => $template->cuerpo,
                'tipo' => $template->tipo,
                'urgencia' => $template->urgencia,
                'canales' => $template->canales ?? [],
                'usadas' => (int) $template->usadas,
                'actualizado' => $template->updated_at?->format('d/m/Y H:i'),
            ])
            ->values();
    }
}
