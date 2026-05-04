<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\BusquedaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $request->validate([
            // Texto libre
            'query'                       => ['nullable', 'string', 'max:200'],
 
            // Usuario
            'usuario'                     => ['nullable', 'array'],
            'usuario.nombre'              => ['nullable', 'string', 'max:100'],
            'usuario.ciudad'              => ['nullable', 'array'],
            'usuario.ciudad.*'            => ['string', 'max:100'],
            'usuario.pais'                => ['nullable', 'array'],
            'usuario.pais.*'              => ['string', 'max:100'],
            'usuario.profesion'           => ['nullable', 'array'],
            'usuario.profesion.*'         => ['string', 'max:100'],
 
            // Habilidades
            'habilidades'                 => ['nullable', 'array'],
            'habilidades.tecnicas'        => ['nullable', 'array'],
            'habilidades.tecnicas.*'      => ['string', 'max:100'],
            'habilidades.blandas'         => ['nullable', 'array'],
            'habilidades.blandas.*'       => ['string', 'max:100'],
            'habilidades.niveles'         => ['nullable', 'array'],
            'habilidades.niveles.*'       => ['string', 'in:basico,intermedio,avanzado,experto,todos'],
 
            // Experiencia
            'experiencia'                 => ['nullable', 'array'],
            'experiencia.tipo'            => ['nullable', 'array'],
            'experiencia.tipo.*'          => ['string', 'in:laboral,academica,ambos'],
            'experiencia.cargo'           => ['nullable', 'array'],
            'experiencia.cargo.*'         => ['string', 'max:150'],
 
            // Proyectos
            'proyectos'                   => ['nullable', 'array'],
            'proyectos.tecnologias'       => ['nullable', 'array'],
            'proyectos.tecnologias.*'     => ['string', 'max:100'],
            'proyectos.tipo'              => ['nullable', 'array'],
            'proyectos.tipo.*'            => ['string', 'max:100'],
            'proyectos.estado'            => ['nullable', 'array'],
            'proyectos.estado.*'          => ['string', 'in:publicado,en_desarrollo,en desarrollo,todos'],
 
            // Orden
            'orden'                       => ['nullable', 'array'],
            'orden.campo'                 => ['nullable', 'string', 'in:relevancia,fecha'],
            'orden.fecha_desde'           => ['nullable', 'date'],
            'orden.direccion'             => ['nullable', 'string', 'in:asc,desc'],
            'orden.priorizar_proyectos'   => ['nullable', 'boolean'],
            'orden.priorizar_experiencia' => ['nullable', 'boolean'],
            'orden.priorizar_habilidades' => ['nullable', 'boolean'],
 
            // Paginación
            'per_page'                    => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
 
        // Solo una priorización activa
        $orden = $request->input('orden', []);
        $activas = collect(['priorizar_proyectos', 'priorizar_experiencia', 'priorizar_habilidades'])
            ->filter(fn($k) => !empty($orden[$k]))
            ->count();
 
        if ($activas > 1) {
            return response()->json([
                'message' => 'Solo se puede activar una opción de priorización a la vez.',
                'errors'  => ['orden' => ['Solo se puede activar una opción de priorización a la vez.']],
            ], 422);
        }
 
        $filters = $request->all();
        $perPage = (int) ($filters['per_page'] ?? 12);
 
        $resultados = $this->service->search($filters, $perPage);
 
        return response()->json([
            'data' => $resultados->items(),
            'meta' => [
                'total'         => $resultados->total(),
                'por_pagina'    => $resultados->perPage(),
                'pagina_actual' => $resultados->currentPage(),
                'ultima_pagina' => $resultados->lastPage(),
            ],
            'message' => "Mostrando {$resultados->count()} portafolios",
        ]);
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
}