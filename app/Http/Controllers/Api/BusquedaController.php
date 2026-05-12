<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\BusquedaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusquedaController extends Controller
{
    public function __construct(
        private readonly BusquedaService $service
    ) {}
 
    /**
     * Controlador para búsqueda avanzada de portafolios con múltiples filtros y ordenamientos
     */
        public function buscar(Request $request): JsonResponse
    {
        $input = $this->normalizarFiltrosBusqueda($request->all());

        $validator = Validator::make($input, [
            // Texto libre
            'query' => ['nullable', 'string', 'max:200'],

            // Usuario
            'usuario' => ['nullable', 'array:nombre,ciudad,pais,profesion'],
            'usuario.nombre' => ['nullable', 'string', 'max:100'],

            'usuario.ciudad' => ['nullable', 'array', 'max:20'],
            'usuario.ciudad.*' => ['string', 'max:100'],

            'usuario.pais' => ['nullable', 'array', 'max:20'],
            'usuario.pais.*' => ['string', 'max:100'],

            'usuario.profesion' => ['nullable', 'array', 'max:20'],
            'usuario.profesion.*' => ['string', 'max:100'],

            // Habilidades
            'habilidades' => ['nullable', 'array:tecnicas,blandas'],

            'habilidades.tecnicas' => ['nullable', 'array:items,niveles'],
            'habilidades.tecnicas.items' => ['nullable', 'array', 'max:50'],
            'habilidades.tecnicas.items.*' => ['string', 'max:100'],
            'habilidades.tecnicas.niveles' => ['nullable', 'array', 'max:5'],
            'habilidades.tecnicas.niveles.*' => [
                'string',
                Rule::in(['basico', 'intermedio', 'avanzado', 'experto', 'todos']),
            ],

            'habilidades.blandas' => ['nullable', 'array:items,niveles'],
            'habilidades.blandas.items' => ['nullable', 'array', 'max:50'],
            'habilidades.blandas.items.*' => ['string', 'max:100'],
            'habilidades.blandas.niveles' => ['nullable', 'array', 'max:5'],
            'habilidades.blandas.niveles.*' => [
                'string',
                Rule::in(['basico', 'intermedio', 'avanzado', 'experto', 'todos']),
            ],

            // Experiencia nueva estructura:
            // experiencia: [{ cargo: "backend", tipos: ["laboral"] }]
            'experiencia' => ['nullable', 'array', 'max:20'],
            'experiencia.*' => ['array:cargo,tipos'],
            'experiencia.*.cargo' => ['nullable', 'string', 'max:150'],
            'experiencia.*.tipos' => ['nullable', 'array', 'max:3'],
            'experiencia.*.tipos.*' => [
                'string',
                Rule::in(['laboral', 'academica', 'ambos']),
            ],

            // Proyectos
            'proyectos' => ['nullable', 'array:tecnologias,tipo,estado'],

            'proyectos.tecnologias' => ['nullable', 'array', 'max:50'],
            'proyectos.tecnologias.*' => ['string', 'max:100'],

            'proyectos.tipo' => ['nullable', 'array', 'max:20'],
            'proyectos.tipo.*' => ['string', 'max:100'],

            'proyectos.estado' => ['nullable', 'array', 'max:5'],
            'proyectos.estado.*' => [
                'string',
                Rule::in(['borrador', 'publicado', 'en_desarrollo', 'archivado', 'todos']),
            ],

            // Orden
            // Ya no va orden.campo porque fecha_desde es filtro, no ordenamiento
            'orden' => [
                'nullable',
                'array:direccion,fecha_desde,priorizar_proyectos,priorizar_experiencia,priorizar_habilidades',
            ],
            'orden.fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'orden.direccion' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'orden.priorizar_proyectos' => ['nullable', 'boolean'],
            'orden.priorizar_experiencia' => ['nullable', 'boolean'],
            'orden.priorizar_habilidades' => ['nullable', 'boolean'],

            // Paginación
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los filtros enviados no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filters = $validator->validated();

        // Solo una priorización activa
        $orden = $filters['orden'] ?? [];

        $activas = collect([
            'priorizar_proyectos',
            'priorizar_experiencia',
            'priorizar_habilidades',
        ])->filter(fn ($k) => !empty($orden[$k]))->count();

        if ($activas > 1) {
            return response()->json([
                'message' => 'Solo se puede activar una opción de priorización a la vez.',
                'errors' => [
                    'orden' => ['Solo se puede activar una opción de priorización a la vez.'],
                ],
            ], 422);
        }

        $perPage = (int) ($filters['per_page'] ?? 12);

        unset($filters['per_page']);

        $resultados = $this->service->search($filters, $perPage);

        return response()->json([
            'data' => $resultados->items(),
            'meta' => [
                'total' => $resultados->total(),
                'por_pagina' => $resultados->perPage(),
                'pagina_actual' => $resultados->currentPage(),
                'ultima_pagina' => $resultados->lastPage(),
            ],
            'message' => "Mostrando {$resultados->count()} portafolios",
        ]);
    }

        private function normalizarFiltrosBusqueda(array $input): array
    {
        if (isset($input['query'])) {
            $input['query'] = trim((string) $input['query']);
        }

        if (data_get($input, 'orden.fecha_desde') === '') {
            data_set($input, 'orden.fecha_desde', null);
        }

        $this->normalizarEnumArray($input, 'habilidades.tecnicas.niveles');
        $this->normalizarEnumArray($input, 'habilidades.blandas.niveles');
        $this->normalizarEnumArray($input, 'proyectos.estado');

        if (isset($input['experiencia']) && is_array($input['experiencia'])) {
            foreach ($input['experiencia'] as $i => $experiencia) {
                $path = "experiencia.{$i}.tipos";
                $this->normalizarEnumArray($input, $path);
            }
        }

        if (isset($input['orden']['direccion'])) {
            $input['orden']['direccion'] = $this->normalizarEnum($input['orden']['direccion']);
        }

        return $input;
    }

    private function normalizarEnumArray(array &$input, string $path): void
    {
        $valores = data_get($input, $path);

        if (!is_array($valores)) {
            return;
        }

        $normalizados = array_map(
            fn ($valor) => $this->normalizarEnum($valor),
            $valores
        );

        data_set($input, $path, $normalizados);
    }

    private function normalizarEnum($valor): string
    {
        return str_replace(
            ' ',
            '_',
            Str::lower(Str::ascii(trim((string) $valor)))
        );
    }

    /**
     * Obtener las profesiones únicas usadas en portafolios para filtros y sugerencias
     */
    public function profesiones(): JsonResponse
    {
        $profesiones = $this->service->getProfesiones();

        return response()->json($profesiones);
    }

    /**
     * Obtener las habilidades blandas únicas usadas en portafolios para filtros y sugerencias
     */
    public function habilidadesBlandas(): JsonResponse
    {
        $habilidades = $this->service->getHabilidadesBlandas();

        return response()->json($habilidades);
    }

    /**
     * Obtener las habilidades técnicas únicas usadas en portafolios para filtros y sugerencias
     */
    public function habilidadesTecnicas(): JsonResponse
    {
        $habilidades = $this->service->getHabilidadesTecnicas();

        return response()->json($habilidades);
    }

    /**
     * Obtener los cargos únicos usados en experiencias laborales para filtros y sugerencias
     */
    public function cargosExperiencia(): JsonResponse
    {
        $cargos = $this->service->getCargosExperiencia();

        return response()->json($cargos);
    }

    /**
     * Obtner las tecnologías únicas usadas en proyectos publicados para filtros y sugerencias
     */
    public function tecnologiasProyecto(): JsonResponse
    {
        $tecnologias = $this->service->getTecnologiasProyecto();

        return response()->json($tecnologias);
    }

    /**
     * Obtner los tipos o categorías únicas usadas en proyectos publicados para filtros y sugerencias
     */

        public function tiposProyecto(): JsonResponse
    {
        $tipos = $this->service->getTiposProyecto();

        return response()->json($tipos);
    }
}