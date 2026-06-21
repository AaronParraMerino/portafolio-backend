<?php

namespace App\Services\api;

use App\Models\Experiencia;
use Illuminate\Support\Facades\DB;

class ExperienciaService
{
    public function __construct(private readonly ContenidoAutoTraduccionService $autoTraduccionService)
    {
    }

    public function getByUserId(int $userId)
    {
        return Experiencia::where('usuario_id', $userId)
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_experiencia')
            ->get();
    }

    public function getCatalog(): array
    {
        $experiencias = Experiencia::query()
            ->whereNotNull('institucion')
            ->where('institucion', '<>', '')
            ->whereNotNull('cargo')
            ->where('cargo', '<>', '')
            ->get(['tipo', 'institucion', 'cargo']);

        $empresas = [
            'laboral' => [],
            'academica' => [],
        ];
        $puestos = [
            'laboral' => [],
            'academica' => [],
        ];

        foreach ($experiencias as $experiencia) {
            $empresa = $this->formatText($experiencia->institucion);
            $puesto = $this->formatText($experiencia->cargo);

            $empresas[$experiencia->tipo][$this->normalizeText($empresa)] = $empresa;
            $puestos[$experiencia->tipo][$this->normalizeText($puesto)] = $puesto;
        }

        return [
            'empresas' => [
                'laboral' => array_values($empresas['laboral']),
                'academica' => array_values($empresas['academica']),
            ],
            'puestos' => [
                'laboral' => array_values($puestos['laboral']),
                'academica' => array_values($puestos['academica']),
            ],
        ];
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
        $this->ensureNotDuplicate($userId, $data);
        $data['usuario_id'] = $userId;
        $data['fecha_modificacion'] = now();

        $experiencia = DB::transaction(function () use ($data) {
            $id = DB::table('experiencias')->insertGetId($data, 'id_experiencia');

            return Experiencia::findOrFail($id);
        });

        $this->autoTraduccionService->traducirEntidad(
            'experiencia',
            (int) $experiencia->id_experiencia,
            $userId,
            [
                'cargo' => $experiencia->cargo,
                'descripcion' => $experiencia->descripcion,
            ]
        );

        return $experiencia;
    }

    public function update(Experiencia $experiencia, array $data): Experiencia
    {
        $data = $this->normalizeData($data);
        $merged = array_merge($experiencia->toArray(), $data);
        $this->ensureNotDuplicate($experiencia->usuario_id, $merged, $experiencia->id_experiencia);
        $data['fecha_modificacion'] = now();

        $actualizada = DB::transaction(function () use ($experiencia, $data) {
            DB::table('experiencias')
                ->where('id_experiencia', $experiencia->id_experiencia)
                ->update($data);

            return $experiencia->fresh();
        });

        $camposTraducibles = array_intersect_key($data, array_flip(['cargo', 'descripcion']));
        if ($camposTraducibles !== []) {
            $this->autoTraduccionService->traducirEntidad(
                'experiencia',
                (int) $actualizada->id_experiencia,
                (int) $actualizada->usuario_id,
                $camposTraducibles
            );
        }

        return $actualizada;
    }

    public function delete(Experiencia $experiencia): void
    {
        $idExperiencia = (int) $experiencia->id_experiencia;

        DB::transaction(function () use ($experiencia) {
            $experiencia->delete();
        });

        $this->autoTraduccionService->eliminarEntidad('experiencia', $idExperiencia);
    }

    private function normalizeData(array $data): array
    {
        foreach (['institucion', 'cargo', 'descripcion'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = $this->cleanText($data[$field]);
            }
        }

        foreach (['institucion', 'cargo'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = $this->formatText($data[$field]);
            }
        }

        if (array_key_exists('descripcion', $data) && $data['descripcion'] === '') {
            $data['descripcion'] = null;
        }

        $esActual = null;

        if (array_key_exists('es_actual', $data)) {
            $esActual = filter_var($data['es_actual'], FILTER_VALIDATE_BOOLEAN);
            $data['es_actual'] = DB::raw($esActual ? 'true' : 'false');
        }

        if (array_key_exists('es_publico', $data)) {
            $esPublico = filter_var($data['es_publico'], FILTER_VALIDATE_BOOLEAN);
            $data['es_publico'] = DB::raw($esPublico ? 'true' : 'false');
        }

        if ($esActual === true) {
            $data['fecha_fin'] = null;
        }

        return $data;
    }

    private function ensureNotDuplicate(int $userId, array $data, ?int $ignoreId = null): void
    {
        $tipo = $data['tipo'] ?? null;
        $institucion = $data['institucion'] ?? null;
        $cargo = $data['cargo'] ?? null;

        if (! $tipo || ! $institucion || ! $cargo) {
            return;
        }

        $targetInstitution = $this->normalizeText($institucion);
        $targetRole = $this->normalizeText($cargo);

        $duplicate = Experiencia::where('usuario_id', $userId)
            ->where('tipo', $tipo)
            ->when($ignoreId, fn ($query) => $query->where('id_experiencia', '!=', $ignoreId))
            ->get(['id_experiencia', 'institucion', 'cargo'])
            ->contains(fn (Experiencia $experiencia) =>
                $this->normalizeText($experiencia->institucion) === $targetInstitution &&
                $this->normalizeText($experiencia->cargo) === $targetRole
            );

        if ($duplicate) {
            throw new \RuntimeException('Ya existe una experiencia con la misma empresa y puesto para este tipo.');
        }
    }

    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function normalizeText(string $value): string
    {
        $value = $this->cleanText($value);
        $value = $this->removeAccents($value);

        return mb_strtolower($value);
    }

    private function formatText(string $value): string
    {
        $value = $this->cleanText($value);

        if ($value === '') {
            return '';
        }

        $lowerWords = ['de', 'del', 'la', 'las', 'el', 'los', 'en', 'y', 'con', 'para', 'por', 'a'];
        $acronyms = ['qa', 'ui', 'ux', 'rrhh', 'ti', 'it', 'ceo', 'cto', 'cfo', 'coo', 'umss', 'uagrm', 'emi', 'aws', 'api'];

        return collect(explode(' ', mb_strtolower($value)))
            ->map(function (string $word, int $index) use ($lowerWords, $acronyms) {
                if ($word === '') {
                    return $word;
                }

                if ($index > 0 && in_array($word, $lowerWords, true)) {
                    return $word;
                }

                if (in_array($word, $acronyms, true)) {
                    return mb_strtoupper($word);
                }

                return mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
            })
            ->implode(' ');
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
}
