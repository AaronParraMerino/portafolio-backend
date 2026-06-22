<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Services\api\Administrador\AdminUsuarioEstadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsuarioEstadoController extends Controller
{
    public function __construct(private readonly AdminUsuarioEstadoService $adminUsuarioEstadoService) {}

    public function activate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        [$message, $channels] = $this->noticeOptions(
            $request,
            'Tu cuenta fue activada por administracion. Ya puedes iniciar sesion nuevamente.'
        );

        return $this->response($this->adminUsuarioEstadoService->activate($this->adminId($request), $id, $message, $channels));
    }

    public function inactivate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        [$reason, $channels] = $this->noticeOptions(
            $request,
            'Tu cuenta fue inactivada por administracion de acuerdo con las politicas de la plataforma.'
        );

        return $this->response($this->adminUsuarioEstadoService->inactivate($this->adminId($request), $id, $reason, $channels));
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        [$reason, $channels] = $this->noticeOptions(
            $request,
            'Tu cuenta fue puesta en pausa por administracion. Durante este periodo solo puedes consultar tu informacion.'
        );

        return $this->response($this->adminUsuarioEstadoService->pause($this->adminId($request), $id, $reason, $channels));
    }

    public function block(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        [$reason, $channels] = $this->noticeOptions(
            $request,
            'Tu cuenta fue bloqueada por administracion. Contacta al equipo de soporte para mas informacion.'
        );

        return $this->response($this->adminUsuarioEstadoService->block($this->adminId($request), $id, $reason, $channels));
    }

    private function noticeOptions(Request $request, string $defaultMessage): array
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

    private function response(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status']);
    }

    private function adminId(Request $request): int
    {
        return (int) $request->user()->id_usuario;
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
