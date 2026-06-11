<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\ExperienciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\api\ContenidoTraduccionService;

class ExperienciaController extends Controller
{
    public function __construct(
        private readonly ExperienciaService $experienciaService,
        private readonly ContenidoTraduccionService $traduccionService
    ) {
    }

    public function catalog(): JsonResponse
    {
        return response()->json($this->experienciaService->getCatalog());
    }

    public function index(Request $request, int $userId): JsonResponse
    {
        $lang = $request->query('lang', 'es');

        $experiencias = collect($this->experienciaService->getByUserId($userId))
            ->map(fn ($experiencia) => $this->traducirExperiencia($experiencia, $lang))
            ->values();

        return response()->json($experiencias);
    }

    public function show(int $userId, int $id): JsonResponse
    {
        $experiencia = $this->experienciaService->findOwnedById($userId, $id);

        if (! $experiencia) {
            return response()->json([
                'message' => 'Experiencia no encontrada'
            ], 404);
        }

        return response()->json($experiencia);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tipo' => ['required', 'in:laboral,academica'],
            'institucion' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL0-9][\pL0-9\s.,&\/#+()\-]*$/u'],
            'cargo' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL0-9][\pL0-9\s.,&\/#+()\-]*$/u'],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'es_actual' => ['sometimes', 'boolean'],
            'es_publico' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $experiencia = $this->experienciaService->create($userId, $data);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Experiencia creada correctamente',
            'data' => $experiencia,
        ], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $experiencia = $this->experienciaService->findOwnedById($userId, $id);

        if (! $experiencia) {
            return response()->json([
                'message' => 'Experiencia no encontrada'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'tipo' => ['sometimes', 'in:laboral,academica'],
            'institucion' => ['sometimes', 'string', 'min:2', 'max:60', 'regex:/^[\pL0-9][\pL0-9\s.,&\/#+()\-]*$/u'],
            'cargo' => ['sometimes', 'string', 'min:2', 'max:80', 'regex:/^[\pL0-9][\pL0-9\s.,&\/#+()\-]*$/u'],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'fecha_inicio' => ['sometimes', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'es_actual' => ['sometimes', 'boolean'],
            'es_publico' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $fechaInicio = $data['fecha_inicio'] ?? $experiencia->fecha_inicio?->format('Y-m-d');
        $fechaFin = array_key_exists('fecha_fin', $data)
            ? $data['fecha_fin']
            : ($experiencia->fecha_fin?->format('Y-m-d'));

        $esActual = $data['es_actual'] ?? $experiencia->es_actual;

        if (! $esActual && ! empty($fechaInicio) && ! empty($fechaFin) && $fechaInicio > $fechaFin) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => [
                    'fecha_fin' => ['La fecha fin no puede ser menor que la fecha inicio.']
                ],
            ], 422);
        }

        try {
            $experiencia = $this->experienciaService->update($experiencia, $data);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Experiencia actualizada correctamente',
            'data' => $experiencia,
        ]);
    }

    public function destroy(int $userId, int $id): JsonResponse
    {
        $experiencia = $this->experienciaService->findOwnedById($userId, $id);

        if (! $experiencia) {
            return response()->json([
                'message' => 'Experiencia no encontrada'
            ], 404);
        }

        $this->experienciaService->delete($experiencia);

        return response()->json([
            'message' => 'Experiencia eliminada correctamente',
        ]);
    }

    private function traducirExperiencia(mixed $experiencia, string $lang): mixed
    {
        if (is_object($experiencia) && method_exists($experiencia, 'toArray')) {
            $experiencia = $experiencia->toArray();
        }

        if (! is_array($experiencia)) {
            return $experiencia;
        }

        $id = $experiencia['id_experiencia'] ?? $experiencia['id'] ?? null;

        return $this->traduccionService->traducirArray(
            $experiencia,
            'experiencia',
            $id,
            ['cargo', 'descripcion'],
            $lang
        );
    }

}
