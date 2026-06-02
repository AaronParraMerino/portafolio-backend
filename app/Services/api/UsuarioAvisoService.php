<?php

namespace App\Services\api;

use App\Models\Aviso;

class UsuarioAvisoService
{
    /**
     * Lista todos los avisos visibles para usuarios
     */
    public function listarVisibles(): array
    {
        $avisos = $this->consultaBaseVisibles()
            ->orderByRaw("
                CASE prioridad
                    WHEN 'critica' THEN 4
                    WHEN 'alta' THEN 3
                    WHEN 'normal' THEN 2
                    WHEN 'baja' THEN 1
                    ELSE 0
                END DESC
            ")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Aviso $aviso) => $this->formatearAviso($aviso))
            ->values()
            ->all();

        return [
            'status' => 'success',
            'message' => 'Avisos visibles obtenidos correctamente',
            'data' => $avisos,
            'total' => count($avisos),
        ];
    }

    /**
     * Cuenta avisos visibles actuales
     */
    public function contarVisibles(): array
    {
        return [
            'status' => 'success',
            'total' => $this->consultaBaseVisibles()->count(),
        ];
    }

    /**
     * Consulta base para avisos visibles actualmente
     */
    private function consultaBaseVisibles()
    {
        return Aviso::query()
            ->where('estado', Aviso::ESTADO_ACTIVO)
            ->where(function ($query) {
                $query->whereNull('visible_desde')
                    ->orWhere('visible_desde', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('visible_hasta')
                    ->orWhere('visible_hasta', '>=', now());
            });
    }

    private function formatearAviso(Aviso $aviso): array
    {
        return [
            'id_aviso' => (int) $aviso->id_aviso,
            'tipo' => $aviso->tipo,
            'titulo' => $aviso->titulo,
            'mensaje' => $aviso->mensaje,
            'prioridad' => $aviso->prioridad,
            'visible_desde' => $aviso->visible_desde?->toDateTimeString(),
            'visible_hasta' => $aviso->visible_hasta?->toDateTimeString(),
            'fecha_creacion' => $aviso->created_at?->toDateTimeString(),
        ];
    }
}