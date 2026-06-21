<?php

namespace App\Services\api\Mensajeria;

use App\Models\Denuncia;

class DenunciaService
{
    public function crearDenuncia(int $idDenunciante, array $data): array
    {
        $asunto = trim((string) ($data['asunto'] ?? ''));
        $motivo = trim((string) ($data['motivo'] ?? ''));

        if ($asunto === '' || $motivo === '') {
            return $this->error('El asunto y el motivo son obligatorios.');
        }

        $denuncia = Denuncia::create([
            'id_denunciante' => $idDenunciante,
            'asunto' => $asunto,
            'motivo' => $motivo,
            'detalle' => $data['detalle'] ?? null,
            'evidencia' => $this->normalizarJsonArray($data['evidencia'] ?? []),
            'metadata' => $this->normalizarJsonArray($data['metadata'] ?? []),
            'estado' => 'pendiente',
        ]);

        return [
            'status' => 'success',
            'message' => 'Denuncia registrada correctamente.',
            'data' => $this->serializarDenuncia($denuncia),
        ];
    }

    private function normalizarJsonArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function serializarDenuncia(Denuncia $denuncia): array
    {
        return [
            'id_denuncia' => (int) $denuncia->id_denuncia,
            'id_denunciante' => (int) $denuncia->id_denunciante,
            'asunto' => $denuncia->asunto,
            'motivo' => $denuncia->motivo,
            'detalle' => $denuncia->detalle,
            'evidencia' => $denuncia->evidencia ?? [],
            'metadata' => $denuncia->metadata ?? [],
            'estado' => $denuncia->estado,
            'created_at' => optional($denuncia->created_at)->toISOString(),
        ];
    }

    private function error(string $message): array
    {
        return [
            'status' => 'invalid_payload',
            'message' => $message,
        ];
    }
}
