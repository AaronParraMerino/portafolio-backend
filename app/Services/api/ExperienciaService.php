<?php

namespace App\Services\api;

use App\Models\Experiencia;
use Illuminate\Support\Facades\DB;

class ExperienciaService
{
    public function getByUserId(int $userId)
    {
        return Experiencia::where('usuario_id', $userId)
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_experiencia')
            ->get();
    }

    public function findOwnedById(int $userId, int $id): ?Experiencia
    {
        return Experiencia::where('usuario_id', $userId)
            ->where('id_experiencia', $id)
            ->first();
    }

    public function create(int $userId, array $data): Experiencia
    {
        $data = $this->normalizeData($data);
        $data['usuario_id'] = $userId;
        $data['fecha_modificacion'] = now();

        return DB::transaction(function () use ($data) {
            return Experiencia::create($data);
        });
    }

    public function update(Experiencia $experiencia, array $data): Experiencia
    {
        $data = $this->normalizeData($data);
        $data['fecha_modificacion'] = now();

        return DB::transaction(function () use ($experiencia, $data) {
            $experiencia->update($data);
            return $experiencia->fresh();
        });
    }

    public function delete(Experiencia $experiencia): void
    {
        DB::transaction(function () use ($experiencia) {
            $experiencia->delete();
        });
    }

    private function normalizeData(array $data): array
    {
        if (array_key_exists('es_actual', $data)) {
            $data['es_actual'] = filter_var($data['es_actual'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        if (array_key_exists('es_publico', $data)) {
            $data['es_publico'] = filter_var($data['es_publico'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        if (($data['es_actual'] ?? null) === 'true') {
            $data['fecha_fin'] = null;
        }

        return $data;
    }
}
