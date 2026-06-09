<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Services\api\Administrador\AdminRespaldoService;
use App\Services\api\BitacoraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RespaldoController extends Controller
{
    public function __construct(
        private readonly AdminRespaldoService $adminRespaldoService,
        private readonly BitacoraService $bitacoraService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        return response()->json($this->adminRespaldoService->metadata());
    }

    public function generate(Request $request): BinaryFileResponse|JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $data = $request->validate([
            'mode' => ['required', 'in:full,tables'],
            'tables' => ['exclude_if:mode,full', 'required_if:mode,tables', 'array', 'min:1', 'max:100'],
            'tables.*' => ['exclude_if:mode,full', 'required', 'string', 'max:100', 'distinct'],
        ]);

        try {
            $backup = $this->adminRespaldoService->generate(
                $data['mode'],
                $data['tables'] ?? [],
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        $this->bitacoraService->record(
            $request->user(),
            'generar_respaldo',
            $backup['mode'] === 'full'
                ? 'Genero un respaldo JSON completo del sistema.'
                : 'Genero un respaldo JSON parcial: '.implode(', ', $backup['tables']).'.',
            [
                'tabla_afectada' => 'base_datos',
            ],
        );

        return response()
            ->download($backup['path'], $backup['filename'], [
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Backup-Mode' => $backup['mode'],
                'X-Backup-Tables' => (string) count($backup['tables']),
            ])
            ->deleteFileAfterSend(true);
    }

    private function forbidNonAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'No tienes permiso para administrar respaldos.',
        ], 403);
    }
}
