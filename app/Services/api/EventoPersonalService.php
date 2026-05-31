<?php

namespace App\Services\api;

use App\Models\EventoPersonal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EventoPersonalService
{
    private const ESTADO_ACTIVO = 'activo';
    private const ESTADO_ELIMINADO = 'eliminado';

    private const TIPOS = [
        'personal' => 'Personal',
        'academico' => 'Académico',
        'trabajo' => 'Trabajo',
        'reunion' => 'Reunión',
        'entrega' => 'Entrega',
        'otro' => 'Otro',
    ];

    public function getByUser(int $userId, ?string $mes = null)
    {
        $query = EventoPersonal::where('usuario_id', $userId)
            ->where('estado', self::ESTADO_ACTIVO)
            ->orderBy('fecha')
            ->orderBy('hora')
            ->orderBy('id_evento');

        if ($mes) {
            $inicio = Carbon::createFromFormat('Y-m', $mes)->startOfMonth();
            $fin = (clone $inicio)->endOfMonth();

            $query->whereBetween('fecha', [
                $inicio->format('Y-m-d'),
                $fin->format('Y-m-d'),
            ]);
        }

        return $query->get()
            ->map(fn (EventoPersonal $evento) => $this->serialize($evento))
            ->values();
    }

    public function create(int $userId, array $data): array
    {
        $data = $this->normalizeData($data);

        if ($this->hasTimeConflict($userId, $data['fecha'], $data['hora'])) {
            throw new \RuntimeException('Ya existe un evento activo en esa fecha y hora.');
        }

        $evento = EventoPersonal::create([
            'usuario_id' => $userId,
            'titulo' => $data['titulo'],
            'descripcion' => $data['descripcion'] ?? null,
            'fecha' => $data['fecha'],
            'hora' => $data['hora'],
            'tipo' => $data['tipo'],
            'estado' => self::ESTADO_ACTIVO,
        ]);

        return $this->serialize($evento);
    }

    public function update(int $userId, int $id, array $data): ?array
    {
        $evento = $this->findActiveOwned($userId, $id);

        if (! $evento) {
            return null;
        }

        $data = $this->normalizeData($data);

        $fecha = $data['fecha'] ?? $evento->fecha->format('Y-m-d');
        $hora = $data['hora'] ?? $this->formatTime($evento->hora);

        if ($this->hasTimeConflict($userId, $fecha, $hora, $evento->id_evento)) {
            throw new \RuntimeException('Ya existe un evento activo en esa fecha y hora.');
        }

        $evento->update($data);

        return $this->serialize($evento->fresh());
    }

    public function delete(int $userId, int $id): bool
    {
        $evento = $this->findActiveOwned($userId, $id);

        if (! $evento) {
            return false;
        }

        $evento->update(['estado' => self::ESTADO_ELIMINADO]);

        return true;
    }

    public function deleteByDate(int $userId, string $fecha): int
    {
        return EventoPersonal::where('usuario_id', $userId)
            ->where('estado', self::ESTADO_ACTIVO)
            ->whereDate('fecha', $fecha)
            ->update(['estado' => self::ESTADO_ELIMINADO]);
    }

    private function findActiveOwned(int $userId, int $id): ?EventoPersonal
    {
        return EventoPersonal::where('usuario_id', $userId)
            ->where('id_evento', $id)
            ->where('estado', self::ESTADO_ACTIVO)
            ->first();
    }

    private function hasTimeConflict(int $userId, string $fecha, string $hora, ?int $exceptId = null): bool
    {
        return EventoPersonal::where('usuario_id', $userId)
            ->where('estado', self::ESTADO_ACTIVO)
            ->whereDate('fecha', $fecha)
            ->where('hora', $hora)
            ->when($exceptId, fn ($query) => $query->where('id_evento', '!=', $exceptId))
            ->exists();
    }

    private function normalizeData(array $data): array
    {
        if (array_key_exists('tipo', $data)) {
            $tipo = $this->normalizeType((string) $data['tipo']);

            if (! array_key_exists($tipo, self::TIPOS)) {
                throw new \InvalidArgumentException('Tipo de evento inválido.');
            }

            $data['tipo'] = $tipo;
        }

        return $data;
    }

    private function normalizeType(string $tipo): string
    {
        return Str::lower(Str::ascii(trim($tipo)));
    }

    private function serialize(EventoPersonal $evento): array
    {
        return [
            'id_evento' => $evento->id_evento,
            'titulo' => $evento->titulo,
            'descripcion' => $evento->descripcion,
            'fecha' => $evento->fecha->format('Y-m-d'),
            'hora' => $this->formatTime($evento->hora),
            'tipo' => self::TIPOS[$evento->tipo] ?? 'Otro',
        ];
    }

    private function formatTime(string $hora): string
    {
        return substr($hora, 0, 5);
    }
}
