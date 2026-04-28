<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projectService)
    {
    }

    public function index(int $userId): JsonResponse
    {
        $this->authorizeUserId($userId);

        return response()->json($this->projectService->getByUserId($userId));
    }

    public function show(int $id): JsonResponse
    {
        $project = $this->findOwnedProject($id);

        if (! $project) {
            return response()->json([
                'message' => 'Proyecto no encontrado',
            ], 404);
        }

        return response()->json(
            $this->projectService->toApi($project, $this->authUserId())
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $dateError = $this->validateDateConsistency(null, $data);

        if ($dateError) {
            return response()->json($dateError, 422);
        }

        $project = $this->projectService->create(
            $this->authUserId(),
            $data
        );

        return response()->json($project, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $project = $this->findOwnedProject($id);

        if (! $project) {
            return response()->json([
                'message' => 'Proyecto no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $dateError = $this->validateDateConsistency($project, $data);

        if ($dateError) {
            return response()->json($dateError, 422);
        }

        return response()->json(
            $this->projectService->update($project, $this->authUserId(), $data)
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $project = $this->findOwnedProject($id);

        if (! $project) {
            return response()->json([
                'message' => 'Proyecto no encontrado',
            ], 404);
        }

        $this->projectService->delete($project);

        return response()->json([
            'message' => 'Proyecto eliminado correctamente',
        ]);
    }

    public function updateVisibility(Request $request, int $id): JsonResponse
    {
        $project = $this->findOwnedProject($id);

        if (! $project) {
            return response()->json([
                'message' => 'Proyecto no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'es_publico' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        return response()->json(
            $this->projectService->updateVisibility(
                $project,
                $this->authUserId(),
                filter_var($request->input('es_publico'), FILTER_VALIDATE_BOOLEAN)
            )
        );
    }

    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $project = $this->findOwnedProject($id);

        if (! $project) {
            return response()->json([
                'message' => 'Proyecto no encontrado',
            ], 404);
        }

        $request->validate([
            'file' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        try {
            return response()->json(
                $this->projectService->uploadCoverImage($project, $request->file('file'), false),
                201
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }
    }

    public function updateImage(Request $request, int $id): JsonResponse
    {
        $project = $this->findOwnedProject($id);

        if (! $project) {
            return response()->json([
                'message' => 'Proyecto no encontrado',
            ], 404);
        }

        $request->validate([
            'file' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        return response()->json(
            $this->projectService->uploadCoverImage($project, $request->file('file'), true)
        );
    }

    public function deleteImage(int $id): JsonResponse
    {
        $project = $this->findOwnedProject($id);

        if (! $project) {
            return response()->json([
                'message' => 'Proyecto no encontrado',
            ], 404);
        }

        return response()->json($this->projectService->deleteCoverImage($project));
    }

    private function findOwnedProject(int $id)
    {
        return $this->projectService->findOwnedById($this->authUserId(), $id);
    }

    private function authUserId(): int
    {
        return (int) auth()->user()->id_usuario;
    }

    private function authorizeUserId(int $userId): void
    {
        abort_if($this->authUserId() !== $userId, 403, 'No autorizado');
    }

    private function rules(bool $isCreate): array
    {
        $titleRule = $isCreate ? ['required', 'string', 'max:200'] : ['sometimes', 'string', 'max:200'];

        return [
            'titulo' => $titleRule,
            'descripcion' => ['nullable', 'string'],
            'url_repositorio' => ['nullable', 'url', 'max:2048'],
            'url_demo' => ['nullable', 'url', 'max:2048'],
            'estado' => ['sometimes', 'in:publicado,desarrollo,borrador,archivado'],
            'es_publico' => ['sometimes', 'boolean'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'en_curso' => ['sometimes', 'boolean'],
            'tipo' => ['nullable', 'string', 'max:100'],
            'etiquetas' => ['nullable', 'array'],
            'etiquetas.*' => ['string', 'max:100'],
        ];
    }

    private function validateDateConsistency($project, array $data): ?array
    {
        $fechaInicio = $data['fecha_inicio'] ?? $project?->fecha_inicio?->format('Y-m-d');
        $fechaFin = array_key_exists('fecha_fin', $data)
            ? $data['fecha_fin']
            : $project?->fecha_fin?->format('Y-m-d');
        $enCurso = array_key_exists('en_curso', $data)
            ? filter_var($data['en_curso'], FILTER_VALIDATE_BOOLEAN)
            : ($project?->estado_desarrollo === 'en_desarrollo' && $project?->fecha_fin === null);

        if (! $enCurso && ! empty($fechaInicio) && ! empty($fechaFin) && $fechaInicio > $fechaFin) {
            return [
                'message' => 'Datos inválidos',
                'errors' => [
                    'fecha_fin' => ['La fecha fin no puede ser menor que la fecha inicio.'],
                ],
            ];
        }

        return null;
    }
}