<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use App\Models\SesionBase;
use App\Models\Usuario;
use App\Services\api\SeccionService;
use App\Services\api\AdminNotificacionGuardadoService;
use App\Services\api\ProfileImageVariantService;
use App\Services\api\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class UsuarioController extends Controller
{
    public function __construct(
        private readonly SeccionService $seccionService,
        private readonly UsuarioService $usuarioService,
        private readonly AdminNotificacionGuardadoService $adminNotificationGuardadoService,
        private readonly ?ProfileImageVariantService $profileImageVariants = null
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $usuarios = Usuario::query()
            ->with('perfil:id_perfil,usuario_id,foto_perfil')
            ->select([
                'id_usuario',
                'nombre',
                'apellido',
                'correo',
                'rol',
                'estado',
                'created_at',
            ])
            ->orderBy('id_usuario')
            ->get();

        $sessionCounts = SesionBase::query()
            ->whereNotNull('personal_access_token_id')
            ->whereIn('usuario_id', $usuarios->pluck('id_usuario'))
            ->selectRaw('usuario_id, COUNT(*) as total')
            ->groupBy('usuario_id')
            ->pluck('total', 'usuario_id');

        $lastSessions = SesionBase::query()
            ->whereIn('usuario_id', $usuarios->pluck('id_usuario'))
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
        $imageVariants = $this->profileImageVariants ?? app(ProfileImageVariantService::class);

        $items = $usuarios->map(function (Usuario $usuario) use ($sessionCounts, $lastSessions, $imageVariants): array {
            /** @var SesionBase|null $lastSession */
            $lastSession = $lastSessions->get($usuario->id_usuario);

            return [
                'id' => $usuario->id_usuario,
                'nombre' => trim($usuario->nombre.' '.$usuario->apellido),
                'email' => $usuario->correo,
                'rol' => $usuario->rol,
                'estado' => $usuario->estado,
                'fotoPerfilThumbUrl' => $imageVariants->getVariantUrl($usuario->perfil?->foto_perfil, 'thumb')
                    ?? $usuario->perfil?->foto_perfil,
                'fechaRegistro' => $usuario->created_at?->format('d/m/Y'),
                'sesionesActivas' => (int) ($sessionCounts[$usuario->id_usuario] ?? 0),
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

        $communications = Notificacion::query()
            ->withCount('notificacionUsuarios as destinatarios_count')
            ->where('modulo', 'administracion')
            ->where('tipo', 'like', 'admin_notice_%')
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->map(function (Notificacion $notice): array {
                return [
                    'id' => $notice->contexto_referencia ?? $notice->id_notificacion,
                    'titulo' => $notice->grupo_titulo ?? 'Administracion',
                    'cuerpo' => $notice->mensaje,
                    'tipo' => str_replace('admin_notice_', '', $notice->tipo),
                    'estado' => 'enviado',
                    'urgencia' => 'baja',
                    'destinatarios' => (int) $notice->destinatarios_count,
                    'creado' => $notice->created_at?->format('d/m/Y H:i'),
                    'segmentos' => [],
                    'canales' => ['inapp'],
                ];
            })
            ->values();

        return response()->json([
            'items' => $items,
            'communications' => $communications,
            'metrics' => [
                'total' => $usuarios->count(),
                'activo' => $usuarios->where('estado', 'activo')->count(),
                'pausado' => $usuarios->where('estado', 'pausado')->count(),
                'bloqueado' => $usuarios->where('estado', 'bloqueado')->count(),
                'inactivo' => $usuarios->where('estado', 'inactivo')->count(),
            ],
            'sourceReady' => true,
            'supportsMutations' => false,
            'supportsSessions' => false,
            'supportsActivation' => true,
            'supportsPausing' => true,
            'supportsBlocking' => true,
            'supportsInactivation' => true,
            'supportsCommunications' => true,
        ]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $usuario = $this->usuarioService->findById($id);

        if (! $usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($usuario->estado === 'activo') {
            return response()->json(['message' => 'La cuenta ya se encuentra activa.'], 422);
        }

        [$mensaje, $canales] = $this->validateNoticeOptions(
            $request,
            'Tu cuenta fue activada por administracion. Ya puedes iniciar sesion nuevamente.'
        );
        $canalesEnviados = [];
        $canalesFallidos = [];

        $this->usuarioService->activate($usuario);

        if (in_array('inapp', $canales, true)) {
            $this->adminNotificationGuardadoService->createAdminNotice(
                (int) $request->user()->id_usuario,
                [
                    'destinatarios' => [$usuario->id_usuario],
                    'mensaje' => $mensaje,
                    'tipo' => 'cuenta',
                    'urgencia' => 'media',
                    'canales' => $canales,
                    'segmentos' => ['seleccionados'],
                ]
            );
            $canalesEnviados[] = 'inapp';
        }

        if (in_array('email', $canales, true)) {
            try {
                $this->sendAccountNoticeWithSendGridApi(
                    $usuario->correo,
                    trim($usuario->nombre.' '.$usuario->apellido),
                    $mensaje,
                    'Tu cuenta ha sido activada',
                    'emails.cuenta_activada'
                );
                $canalesEnviados[] = 'email';
            } catch (Throwable $exception) {
                $canalesFallidos[] = 'email';
                Log::warning('No se pudo enviar el correo de activacion de cuenta.', [
                    'id_usuario' => $usuario->id_usuario,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => empty($canalesFallidos)
                ? 'Cuenta activada y aviso enviado correctamente.'
                : 'Cuenta activada. No fue posible enviar todos los avisos.',
            'data' => [
                'id' => $usuario->id_usuario,
                'estado' => 'activo',
                'mensaje' => $mensaje,
                'canales_enviados' => $canalesEnviados,
                'canales_fallidos' => $canalesFallidos,
            ],
        ]);
    }

    public function inactivate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $usuario = $this->usuarioService->findById($id);

        if (! $usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($usuario->estado === 'inactivo') {
            return response()->json(['message' => 'La cuenta ya se encuentra inactiva.'], 422);
        }

        [$razon, $canales] = $this->validateNoticeOptions(
            $request,
            'Tu cuenta fue inactivada por administracion de acuerdo con las politicas de la plataforma.'
        );
        $canalesEnviados = [];
        $canalesFallidos = [];

        $this->usuarioService->delete($usuario);

        if (in_array('inapp', $canales, true)) {
            $this->adminNotificationGuardadoService->createAdminNotice(
                (int) $request->user()->id_usuario,
                [
                    'destinatarios' => [$usuario->id_usuario],
                    'mensaje' => $razon,
                    'tipo' => 'cuenta',
                    'urgencia' => 'alta',
                    'canales' => $canales,
                    'segmentos' => ['seleccionados'],
                ]
            );
            $canalesEnviados[] = 'inapp';
        }

        if (in_array('email', $canales, true)) {
            try {
                $this->sendAccountNoticeWithSendGridApi(
                    $usuario->correo,
                    trim($usuario->nombre.' '.$usuario->apellido),
                    $razon,
                    'Tu cuenta ha sido inactivada',
                    'emails.cuenta_inactivada'
                );
                $canalesEnviados[] = 'email';
            } catch (Throwable $exception) {
                $canalesFallidos[] = 'email';
                Log::warning('No se pudo enviar el correo de inactivacion de cuenta.', [
                    'id_usuario' => $usuario->id_usuario,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => empty($canalesFallidos)
                ? 'Cuenta inactivada y aviso enviado correctamente.'
                : 'Cuenta inactivada. No fue posible enviar todos los avisos.',
            'data' => [
                'id' => $usuario->id_usuario,
                'estado' => 'inactivo',
                'razon' => $razon,
                'canales_enviados' => $canalesEnviados,
                'canales_fallidos' => $canalesFallidos,
            ],
        ]);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $usuario = $this->usuarioService->findById($id);

        if (! $usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($usuario->estado === 'pausado') {
            return response()->json(['message' => 'La cuenta ya se encuentra en pausa.'], 422);
        }

        if ($usuario->estado !== 'activo') {
            return response()->json([
                'message' => 'Solo una cuenta activa puede ponerse en pausa sin alterar su visibilidad.',
            ], 422);
        }

        $data = $request->validate([
            'razon' => ['nullable', 'string', 'max:1000'],
        ]);
        $razon = trim((string) ($data['razon'] ?? ''))
            ?: 'Tu cuenta fue puesta en pausa por administracion. Durante este periodo solo puedes consultar tu informacion.';

        $this->usuarioService->pause($usuario);
        $this->adminNotificationGuardadoService->createAdminNotice(
            (int) $request->user()->id_usuario,
            [
                'destinatarios' => [$usuario->id_usuario],
                'mensaje' => $razon,
                'tipo' => 'cuenta',
                'urgencia' => 'media',
                'canales' => ['inapp'],
                'segmentos' => ['seleccionados'],
            ]
        );

        return response()->json([
            'message' => 'Cuenta puesta en pausa y motivo registrado correctamente.',
            'data' => [
                'id' => $usuario->id_usuario,
                'estado' => 'pausado',
                'razon' => $razon,
                'canales_enviados' => ['inapp'],
                'canales_fallidos' => [],
            ],
        ]);
    }

    public function block(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $usuario = $this->usuarioService->findById($id);

        if (! $usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($usuario->estado === 'bloqueado') {
            return response()->json(['message' => 'La cuenta ya se encuentra bloqueada.'], 422);
        }

        $data = $request->validate([
            'razon' => ['nullable', 'string', 'max:1000'],
        ]);
        $razon = trim((string) ($data['razon'] ?? ''))
            ?: 'Tu cuenta fue bloqueada por administracion. Contacta al equipo de soporte para mas informacion.';

        $this->usuarioService->block($usuario);
        $this->adminNotificationGuardadoService->createAdminNotice(
            (int) $request->user()->id_usuario,
            [
                'destinatarios' => [$usuario->id_usuario],
                'mensaje' => $razon,
                'tipo' => 'seguridad',
                'urgencia' => 'alta',
                'canales' => ['inapp'],
                'segmentos' => ['seleccionados'],
            ]
        );

        return response()->json([
            'message' => 'Cuenta bloqueada y motivo registrado correctamente.',
            'data' => [
                'id' => $usuario->id_usuario,
                'estado' => 'bloqueado',
                'razon' => $razon,
                'canales_enviados' => ['inapp'],
                'canales_fallidos' => [],
            ],
        ]);
    }

    private function validateNoticeOptions(Request $request, string $defaultMessage): array
    {
        $data = $request->validate([
            'razon' => ['nullable', 'string', 'max:1000'],
            'canales' => ['sometimes', 'array', 'min:1'],
            'canales.*' => ['required', 'distinct', Rule::in(['inapp', 'email'])],
        ]);

        return [
            trim((string) ($data['razon'] ?? '')) ?: $defaultMessage,
            $data['canales'] ?? ['inapp', 'email'],
        ];
    }

    private function sendAccountNoticeWithSendGridApi(
        string $toEmail,
        string $nombre,
        string $mensaje,
        string $subject,
        string $view
    ): void
    {
        $apiKey = (string) env('SENDGRID_API_KEY', '');
        $fromEmail = (string) env('SENDGRID_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''));
        $fromName = (string) env('SENDGRID_FROM_NAME', env('MAIL_FROM_NAME', 'Portafolio'));
        $apiUrl = (string) env('SENDGRID_API_URL', 'https://api.sendgrid.com/v3/mail/send');

        if ($apiKey === '' || $fromEmail === '') {
            throw new \RuntimeException('Falta SENDGRID_API_KEY o SENDGRID_FROM_ADDRESS en .env');
        }

        $html = view($view, [
            'nombre' => $nombre,
            'mensaje' => $mensaje,
            'razon' => $mensaje,
        ])->render();

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post($apiUrl, [
                'personalizations' => [[
                    'to' => [[
                        'email' => $toEmail,
                    ]],
                ]],
                'from' => [
                    'email' => $fromEmail,
                    'name' => $fromName,
                ],
                'subject' => $subject,
                'content' => [[
                    'type' => 'text/html',
                    'value' => $html,
                ]],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('SendGrid API error '.$response->status().': '.$response->body());
        }
    }

    public function sessions(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        if (! Usuario::query()->whereKey($id)->exists()) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        $sessions = $this->seccionService->listActiveByUser($id)
            ->map(fn (array $session): array => [
                'id_rastreo_interno' => $session['id_rastreo_interno'],
                'ip_address' => $session['ip_address'],
                'pais_codigo' => $session['pais_codigo'],
                'navegador_nombre' => $session['navegador_nombre'],
                'navegador_version' => $session['navegador_version'],
                'sistema_operativo' => $session['sistema_operativo'],
                'es_movil' => $session['es_movil'],
                'ultima_actividad' => $session['ultima_actividad'],
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function closeSession(Request $request, int $id, int $sessionId): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $status = $this->seccionService->closeSessionByIdForUser($sessionId, $id);

        if ($status === 'not_found') {
            return response()->json(['message' => 'Sesion no encontrada.'], 404);
        }

        return response()->json(['message' => 'Sesion cerrada correctamente.']);
    }

    public function closeAllSessions(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        if (! Usuario::query()->whereKey($id)->exists()) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        $closedCount = $this->seccionService->closeOtherSessionsForUser($id);

        return response()->json([
            'message' => 'Sesiones cerradas correctamente.',
            'data' => ['cerradas' => $closedCount],
        ]);
    }

    private function forbidNonAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'No tienes permiso para acceder a la administracion de usuarios.',
        ], 403);
    }
}
