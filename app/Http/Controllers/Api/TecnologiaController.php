<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\TecnologiaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TecnologiaController extends Controller
{
    public function __construct(
        private TecnologiaService $tecnologiaService
    ) {}

    public function index()
    {
        $tecnologias = $this->tecnologiaService->listar();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Tecnologías listadas correctamente.',
            'data' => $tecnologias,
        ]);
    }

    public function showByName(string $nombre)
    {
        $tecnologia = $this->tecnologiaService->obtenerPorNombre($nombre);

        if (! $tecnologia) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Tecnología no encontrada.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Tecnología obtenida correctamente.',
            'data' => $tecnologia,
        ]);
    }

    public function store(Request $request, string $nombre)
    {
        $request->validate([
            'tipo' => [
                'nullable',
                Rule::in($this->tiposPermitidos()),
            ],
        ]);

        $resultado = $this->tecnologiaService->agregarPorNombre(
            $nombre,
            $request->input('tipo')
        );

        return response()->json([
            'ok' => true,
            'mensaje' => $resultado['creado']
                ? 'Tecnología agregada correctamente.'
                : 'La tecnología ya existía.',
            'data' => $resultado['tecnologia'],
        ], $resultado['creado'] ? 201 : 200);
    }

    public function storeDetectedBatch(Request $request)
    {
        $datos = $request->validate([
            'tecnologias' => ['required', 'array', 'min:1', 'max:20'],
            'tecnologias.*' => ['required', 'string', 'max:100'],
            'tipo' => [
                'nullable',
                Rule::in($this->tiposPermitidos()),
            ],
        ]);

        $tipo = $datos['tipo'] ?? 'lenguaje';
        $nombres = collect($datos['tecnologias'])
            ->map(fn ($nombre) => trim((string) $nombre))
            ->filter()
            ->unique(fn ($nombre) => mb_strtolower($nombre))
            ->values();

        $tecnologias = [];

        foreach ($nombres as $nombre) {
            $resultado = $this->tecnologiaService->agregarBasicaPorNombre($nombre, $tipo);

            if (! empty($resultado['tecnologia'])) {
                $tecnologias[] = $resultado['tecnologia'];
            }
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Tecnologias detectadas aseguradas correctamente.',
            'data' => $tecnologias,
        ]);
    }

    public function update(Request $request, string $nombre)
    {
        $datos = $request->validate([
            'tipo' => [
                'nullable',
                Rule::in($this->tiposPermitidos()),
            ],
            'icono_url' => [
                'nullable',
                'url',
            ],
            'color' => [
                'nullable',
                'string',
                'max:20',
            ],
            'descripcion' => [
                'nullable',
                'string',
            ],
        ]);

        if (empty($datos)) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Debe enviar al menos un dato para actualizar.',
            ], 422);
        }

        $tecnologia = $this->tecnologiaService->actualizarPorNombre($nombre, $datos);

        if (! $tecnologia) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Tecnología no encontrada.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Tecnología actualizada correctamente.',
            'data' => $tecnologia,
        ]);
    }

    public function destroy(string $nombre)
    {
        $eliminado = $this->tecnologiaService->eliminarPorNombre($nombre);

        if (! $eliminado) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Tecnología no encontrada.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Tecnología eliminada correctamente.',
        ]);
    }

    private function tiposPermitidos(): array
    {
        return [
            'lenguaje',
            'framework',
            'libreria',
            'base_datos',
            'herramienta',
            'servicio',
            'plataforma',
            'otro',
        ];
    }
}
