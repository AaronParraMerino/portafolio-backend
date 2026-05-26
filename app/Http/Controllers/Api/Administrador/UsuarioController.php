<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use App\Models\SesionBase;
use App\Models\Usuario;
use App\Services\api\SeccionService;
use App\Services\api\NotificacionService;
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
        private readonly NotificacionService $notificacionService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $usuarios = Usuario::query()
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

        $items = $usuarios->map(function (Usuario $usuario) use ($sessionCounts): array {
            return [
                'id' => $usuario->id_usuario,
                'nombre' => trim($usuario->nombre.' '.$usuario->apellido),
                'email' => $usuario->correo,
                'rol' => $usuario->rol,
                'estado' => $usuario->estado,
                'fechaRegistro' => $usuario->created_at?->format('d/m/Y'),
                'sesionesActivas' => (int) ($sessionCounts[$usuario->id_usuario] ?? 0),
            ];
        })->values();

        $communications = Notificacion::query()
            ->where('modulo', 'administracion')
            ->where('tipo', 'like', 'admin_notice_%')
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->groupBy(fn (Notificacion $notice) => $notice->data['envio_id'] ?? (string) $notice->id_notificacion)
            ->map(function ($notices): array {
                /** @var Notificacion $notice */
                $notice = $notices->first();
                $data = $notice->data ?? [];

                return [
                    'id' => $data['envio_id'] ?? $notice->id_notificacion,
                    'titulo' => $notice->titulo,
                    'cuerpo' => $notice->contenido,
                    'tipo' => $data['tipo_aviso'] ?? 'sistema',
                    'estado' => 'enviado',
                    'urgencia' => $data['urgencia'] ?? 'baja',
                    'destinatarios' => $notices->count(),
                    'creado' => $notice->created_at?->format('d/m/Y H:i'),
                    'segmentos' => $data['segmentos'] ?? [],
                    'canales' => $data['canales'] ?? ['inapp'],
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
            'supportsInactivation' => true,
            'supportsCommunications' => true,
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

        $data = $request->validate([
            'razon' => ['nullable', 'string', 'max:1000'],
            'canales' => ['sometimes', 'array', 'min:1'],
            'canales.*' => ['required', 'distinct', Rule::in(['inapp', 'email'])],
        ]);
        $razon = trim((string) ($data['razon'] ?? ''))
            ?: 'Tu cuenta fue inactivada por administracion de acuerdo con las politicas de la plataforma.';
        $canales = $data['canales'] ?? ['inapp', 'email'];
        $canalesEnviados = [];
        $canalesFallidos = [];

        $this->usuarioService->delete($usuario);

        if (in_array('inapp', $canales, true)) {
            $this->notificacionService->createAdminNotice(
                (int) $request->user()->id_usuario,
                [
                    'destinatarios' => [$usuario->id_usuario],
                    'titulo' => 'Cuenta inactivada',
                    'contenido' => $razon,
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
                $this->sendInactivationNoticeWithSendGridApi(
                    $usuario->correo,
                    trim($usuario->nombre.' '.$usuario->apellido),
                    $razon
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

    private function sendInactivationNoticeWithSendGridApi(string $toEmail, string $nombre, string $razon): void
    {
        $apiKey = (string) env('SENDGRID_API_KEY', '');
        $fromEmail = (string) env('SENDGRID_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''));
        $fromName = (string) env('SENDGRID_FROM_NAME', env('MAIL_FROM_NAME', 'Portafolio'));
        $apiUrl = (string) env('SENDGRID_API_URL', 'https://api.sendgrid.com/v3/mail/send');

        if ($apiKey === '' || $fromEmail === '') {
            throw new \RuntimeException('Falta SENDGRID_API_KEY o SENDGRID_FROM_ADDRESS en .env');
        }

        $html = view('emails.cuenta_inactivada', [
            'nombre' => $nombre,
            'razon' => $razon,
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
                'subject' => 'Tu cuenta ha sido inactivada',
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
