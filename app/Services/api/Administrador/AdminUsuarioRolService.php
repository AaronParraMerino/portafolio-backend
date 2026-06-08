<?php

namespace App\Services\api\Administrador;

use App\Models\Usuario;
use App\Services\api\AdminNotificacionGuardadoService;
use App\Services\api\UsuarioService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminUsuarioRolService
{
    public function __construct(
        private readonly UsuarioService $usuarioService,
        private readonly AdminNotificacionGuardadoService $adminNotificationGuardadoService,
    ) {}

    public function update(int $adminId, int $userId, string $nextRole, string $reason, array $channels): array
    {
        $user = $this->usuarioService->findById($userId);
        if (! $user) {
            return $this->error('Usuario no encontrado.', 404);
        }
        if ($user->rol === 'admin') {
            return $this->error('El rol administrador solo puede modificarse desde base de datos.', 422);
        }
        if ($adminId === (int) $user->id_usuario) {
            return $this->error('No puedes cambiar tu propio rol desde esta pantalla.', 422);
        }
        if ($user->rol === $nextRole) {
            return $this->error('El usuario ya tiene ese rol.', 422);
        }

        $user = $this->usuarioService->updateRole($user, $nextRole);
        [$sent, $failed] = $this->notify($adminId, $user, $reason, $channels);

        return [
            'body' => [
                'message' => empty($failed)
                    ? 'Rol actualizado y aviso enviado correctamente.'
                    : 'Rol actualizado. No fue posible enviar todos los avisos.',
                'data' => [
                    'id' => $user->id_usuario,
                    'rol' => $user->rol,
                    'razon' => $reason,
                    'canales_enviados' => $sent,
                    'canales_fallidos' => $failed,
                ],
            ],
            'status' => 200,
        ];
    }

    private function notify(int $adminId, Usuario $user, string $reason, array $channels): array
    {
        $sent = [];
        $failed = [];

        if (in_array('inapp', $channels, true)) {
            $this->adminNotificationGuardadoService->createAdminNotice($adminId, [
                'destinatarios' => [$user->id_usuario],
                'mensaje' => $reason,
                'tipo' => 'cuenta',
                'urgencia' => 'media',
                'canales' => $channels,
                'segmentos' => ['seleccionados'],
            ]);
            $sent[] = 'inapp';
        }

        if (in_array('email', $channels, true)) {
            try {
                $this->sendEmail($user, $reason);
                $sent[] = 'email';
            } catch (Throwable $exception) {
                $failed[] = 'email';
                Log::warning('No se pudo enviar el correo de cambio de rol publicante.', [
                    'id_usuario' => $user->id_usuario,
                    'rol' => $user->rol,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [$sent, $failed];
    }

    private function sendEmail(Usuario $user, string $reason): void
    {
        $apiKey = (string) env('SENDGRID_API_KEY', '');
        $fromEmail = (string) env('SENDGRID_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''));
        $fromName = (string) env('SENDGRID_FROM_NAME', env('MAIL_FROM_NAME', 'Portafolio'));
        $apiUrl = (string) env('SENDGRID_API_URL', 'https://api.sendgrid.com/v3/mail/send');

        if ($apiKey === '' || $fromEmail === '') {
            throw new \RuntimeException('Falta SENDGRID_API_KEY o SENDGRID_FROM_ADDRESS en .env');
        }

        $subject = $user->rol === 'publicante' ? 'Rol publicante asignado' : 'Rol publicante retirado';
        $html = view('emails.rol_publicante_actualizado', [
            'nombre' => trim($user->nombre.' '.$user->apellido),
            'mensaje' => $reason,
            'razon' => $reason,
        ])->render();

        $response = Http::withToken($apiKey)->acceptJson()->post($apiUrl, [
            'personalizations' => [['to' => [['email' => $user->correo]]]],
            'from' => ['email' => $fromEmail, 'name' => $fromName],
            'subject' => $subject,
            'content' => [['type' => 'text/html', 'value' => $html]],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('SendGrid API error '.$response->status().': '.$response->body());
        }
    }

    private function error(string $message, int $status): array
    {
        return ['body' => ['message' => $message], 'status' => $status];
    }
}
