<?php

namespace App\Services\api;

use App\Models\Habilidad;
use App\Models\HabilidadUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class HabilidadService
{
    public function __construct(private readonly ContenidoAutoTraduccionService $autoTraduccionService)
    {
    }

    public function getCatalog(?string $tipo = null)
    {
        $query = Habilidad::query()->whereRaw('estado = true');

        if ($tipo) {
            $query->where('tipo', $tipo);
        }

        return $query->orderBy('tipo')->orderBy('nombre')->get();
    }

    public function createCatalog(array $data, ?int $userId = null): Habilidad
    {
        $nombreNormalizado = $this->normalizeName($data['nombre']);
        $existing = $this->findExistingCatalogByNormalizedName($nombreNormalizado);

        if ($existing) {
            throw new \RuntimeException(
                'La habilidad ya existe en el catÃ¡logo como habilidad ' . $existing->tipo . '.'
            );
        }

        try {
            $habilidad = Habilidad::create([
                'nombre' => trim($data['nombre']),
                'nombre_normalizado' => $nombreNormalizado,
                'tipo' => $data['tipo'],
                'descripcion' => $data['descripcion'] ?? null,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw new \RuntimeException('La habilidad ya existe en el catÃ¡logo.');
            }

            throw $e;
        }

        $this->autoTraduccionService->traducirEntidad(
            'habilidad',
            (int) $habilidad->id_habilidad,
            $userId,
            [
                'nombre' => $habilidad->nombre,
                'descripcion' => $habilidad->descripcion,
            ]
        );

        return $habilidad;
    }

    public function getByUserId(int $userId)
    {
        return HabilidadUsuario::with('habilidad')
            ->where('usuario_id', $userId)
            ->orderByDesc('id_habilidad_usuario')
            ->get();
    }

    public function findOwnedById(int $userId, int $id): ?HabilidadUsuario
    {
        return HabilidadUsuario::with('habilidad')
            ->where('usuario_id', $userId)
            ->where('id_habilidad_usuario', $id)
            ->first();
    }

    public function assignToUser(int $userId, array $data): HabilidadUsuario
    {
        $habilidadId = $this->resolveHabilidadId($data, $userId);

        $exists = HabilidadUsuario::where('usuario_id', $userId)
            ->where('habilidad_id', $habilidadId)
            ->exists();

        if ($exists) {
            throw new \RuntimeException('La habilidad ya está registrada para este usuario.');
        }

        return DB::transaction(function () use ($userId, $habilidadId, $data) {
            return HabilidadUsuario::create([
                'usuario_id' => $userId,
                'habilidad_id' => $habilidadId,
                'nivel' => $data['nivel'],
                'es_visible' => $this->toPgBool($data['es_visible'] ?? true),
                'fecha_modificacion' => now(),
            ])->load('habilidad');
        });
    }

    public function updateUserSkill(HabilidadUsuario $habilidadUsuario, array $data): HabilidadUsuario
    {
        $habilidadId = $habilidadUsuario->habilidad_id;

        if (!empty($data['habilidad_id']) || !empty($data['nombre'])) {
            $habilidadId = $this->resolveHabilidadId($data, (int) $habilidadUsuario->usuario_id);

            $exists = HabilidadUsuario::where('usuario_id', $habilidadUsuario->usuario_id)
                ->where('habilidad_id', $habilidadId)
                ->where('id_habilidad_usuario', '!=', $habilidadUsuario->id_habilidad_usuario)
                ->exists();

            if ($exists) {
                throw new \RuntimeException('La habilidad ya está registrada para este usuario.');
            }
        }

        return DB::transaction(function () use ($habilidadUsuario, $habilidadId, $data) {
            $habilidadUsuario->update([
                'habilidad_id' => $habilidadId,
                'nivel' => $data['nivel'] ?? $habilidadUsuario->nivel,
                'es_visible' => array_key_exists('es_visible', $data)
                    ? $this->toPgBool($data['es_visible'])
                    : $this->toPgBool((bool) $habilidadUsuario->es_visible),
                'fecha_modificacion' => now(),
            ]);

            return $habilidadUsuario->fresh()->load('habilidad');
        });
    }

    public function deleteUserSkill(HabilidadUsuario $habilidadUsuario): void
    {
        DB::transaction(function () use ($habilidadUsuario) {
            $habilidadUsuario->delete();
        });
    }

    private function resolveHabilidadId(array $data, ?int $userId = null): int
    {
        if (!empty($data['habilidad_id'])) {
            return (int) $data['habilidad_id'];
        }

        $habilidad = $this->createCatalog([
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'descripcion' => $data['descripcion'] ?? null,
        ], $userId);

        return $habilidad->id_habilidad;
    }

    private function normalizeName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = $this->removeAccents($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s*([+#.])\s*/u', '$1', $value);
        $value = preg_replace('/\.+$/u', '', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function removeAccents(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);

            if ($normalized !== false) {
                return preg_replace('/\p{Mn}+/u', '', $normalized);
            }
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $ascii !== false ? $ascii : $value;
    }

    private function findExistingCatalogByNormalizedName(string $nombreNormalizado): ?Habilidad
    {
        $existing = Habilidad::where('nombre_normalizado', $nombreNormalizado)->first();

        if ($existing) {
            return $existing;
        }

        return Habilidad::query()
            ->get(['id_habilidad', 'nombre', 'nombre_normalizado', 'tipo'])
            ->first(fn (Habilidad $habilidad) => $this->normalizeName($habilidad->nombre) === $nombreNormalizado);
    }

    private function toPgBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
