<?php

namespace App\Services\api\Administrador;

use App\Models\Usuario;
use App\Services\api\AdminNotificacionGuardadoService;
use App\Services\api\CorreoEnvioService;
use App\Services\api\UsuarioService;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminUsuarioEstadoService
{
    public function __construct(
        private readonly UsuarioService $usuarioService,
        private readonly AdminNotificacionGuardadoService $adminNotificationGuardadoService,
        private readonly ?CorreoEnvioService $correoEnvioService = null,
    ) {}

    public function activate(int $adminId, int $userId, string $message, array $channels): array
    {
        $user = $this->usuarioService->findById($userId);
        if (! $user) {
            return $this->error('Usuario no encontrado.', 404);
        }
        if ($user->estado === 'activo') {
            return $this->error('La cuenta ya se encuentra activa.', 422);
        }

        $this->usuarioService->activate($user);
        [$sent, $failed] = $this->notify(
            $adminId,
            $user,
            $message,
            $channels,
            'media',
            'Tu cuenta ha sido activada',
            'emails.cuenta_activada',
            'activacion'
        );

        return $this->success(
            empty($failed)
                ? 'Cuenta activada y aviso enviado correctamente.'
                : 'Cuenta activada. No fue posible enviar todos los avisos.',
            $user,
            'activo',
            $message,
            $sent,
            $failed,
            'mensaje'
        );
    }

    public function inactivate(int $adminId, int $userId, string $reason, array $channels): array
    {
        $user = $this->usuarioService->findById($userId);
        if (! $user) {
            return $this->error('Usuario no encontrado.', 404);
        }
        if ($user->estado === 'inactivo') {
            return $this->error('La cuenta ya se encuentra inactiva.', 422);
        }

        $this->usuarioService->delete($user);
        [$sent, $failed] = $this->notify(
            $adminId,
            $user,
            $reason,
            $channels,
            'alta',
            'Tu cuenta ha sido inactivada',
            'emails.cuenta_inactivada',
            'inactivacion'
        );

        return $this->success(
            empty($failed)
                ? 'Cuenta inactivada y aviso enviado correctamente.'
                : 'Cuenta inactivada. No fue posible enviar todos los avisos.',
            $user,
            'inactivo',
            $reason,
            $sent,
            $failed
        );
    }

    public function pause(int $adminId, int $userId, string $reason, array $channels): array
    {
        $user = $this->usuarioService->findById($userId);
        if (! $user) {
            return $this->error('Usuario no encontrado.', 404);
        }
        if ($user->estado === 'pausado') {
            return $this->error('La cuenta ya se encuentra en pausa.', 422);
        }
        if ($user->estado !== 'activo') {
            return $this->error('Solo una cuenta activa puede ponerse en pausa sin alterar su visibilidad.', 422);
        }

        $this->usuarioService->pause($user);
        [$sent, $failed] = $this->notify(
            $adminId,
            $user,
            $reason,
            $channels,
            'media',
            'Tu cuenta ha sido puesta en pausa',
            'emails.cuenta_pausada',
            'pausa'
        );

        return $this->success(
            empty($failed)
                ? 'Cuenta puesta en pausa y aviso enviado correctamente.'
                : 'Cuenta puesta en pausa. No fue posible enviar todos los avisos.',
            $user,
            'pausado',
            $reason,
            $sent,
            $failed
        );
    }

    public function block(int $adminId, int $userId, string $reason, array $channels): array
    {
        $user = $this->usuarioService->findById($userId);
        if (! $user) {
            return $this->error('Usuario no encontrado.', 404);
        }
        if ($user->estado === 'bloqueado') {
            return $this->error('La cuenta ya se encuentra bloqueada.', 422);
        }

        $this->usuarioService->block($user);
        [$sent, $failed] = $this->notify(
            $adminId,
            $user,
            $reason,
            $channels,
            'alta',
            'Tu cuenta ha sido bloqueada',
            'emails.cuenta_bloqueada',
            'bloqueo'
        );

        return $this->success(
            empty($failed)
                ? 'Cuenta bloqueada y aviso enviado correctamente.'
                : 'Cuenta bloqueada. No fue posible enviar todos los avisos.',
            $user,
            'bloqueado',
            $reason,
            $sent,
            $failed
        );
    }

    private function notify(
        int $adminId,
        Usuario $user,
        string $message,
        array $channels,
        string $urgency,
        string $subject,
        string $view,
        string $logAction,
    ): array {
        $sent = [];
        $failed = [];

        if (in_array('inapp', $channels, true)) {
            $this->createInAppNotice($adminId, $user, $message, 'cuenta', $urgency, $channels, $subject);
            $sent[] = 'inapp';
        }

        if (in_array('email', $channels, true)) {
            try {
                $this->sendEmail($user, $message, $subject, $view);
                $sent[] = 'email';
            } catch (Throwable $exception) {
                $failed[] = 'email';
                Log::warning("No se pudo enviar el correo de {$logAction} de cuenta.", [
                    'id_usuario' => $user->id_usuario,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [$sent, $failed];
    }

    private function createInAppNotice(
        int $adminId,
        Usuario $user,
        string $message,
        string $type,
        string $urgency,
        array $channels,
        string $title = 'Administracion',
    ): void {
        $this->adminNotificationGuardadoService->createAdminNotice($adminId, [
            'destinatarios' => [$user->id_usuario],
            'titulo' => $title,
            'mensaje' => $message,
            'tipo' => $type,
            'urgencia' => $urgency,
            'canales' => $channels,
            'segmentos' => ['seleccionados'],
        ]);
    }

    private function sendEmail(Usuario $user, string $message, string $subject, string $view): void
    {
        ($this->correoEnvioService ?? app(CorreoEnvioService::class))->enviarVista($user->correo, $subject, $view, [
            'nombre' => trim($user->nombre.' '.$user->apellido),
            'mensaje' => $message,
            'razon' => $message,
        ]);
    }

    private function success(
        string $message,
        Usuario $user,
        string $status,
        string $reason,
        array $sent,
        array $failed,
        string $reasonKey = 'razon',
    ): array {
        return [
            'body' => [
                'message' => $message,
                'data' => [
                    'id' => $user->id_usuario,
                    'estado' => $status,
                    $reasonKey => $reason,
                    'canales_enviados' => $sent,
                    'canales_fallidos' => $failed,
                ],
            ],
            'status' => 200,
        ];
    }

    private function error(string $message, int $status): array
    {
        return ['body' => ['message' => $message], 'status' => $status];
    }
}
