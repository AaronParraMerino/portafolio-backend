<?php

namespace App\Services\api\Denuncias;

use App\Models\Denuncia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DenunciaService
{
    public function __construct(
        private readonly DenunciaNotificacionService $notificaciones,
        private readonly DenunciaEvidenciaService $evidencias
    ) {
    }

    public function crearDenuncia(int $idDenunciante, array $data, ?UploadedFile $imagen = null): array
    {
        $asunto = trim((string) ($data['asunto'] ?? ''));
        $detalle = trim((string) ($data['detalle'] ?? ''));

        if ($asunto === '' || $detalle === '') {
            return $this->error('El asunto y el detalle son obligatorios.');
        }

        $evidencia = $this->normalizarJsonArray($data['evidencia'] ?? []);

        if ($imagen) {
            $evidencia[] = $this->evidencias->guardarImagen($imagen, $idDenunciante);
        }

        $resultado = DB::transaction(function () use ($idDenunciante, $asunto, $detalle, $data, $evidencia): array {
            $denuncia = Denuncia::create([
                'id_denunciante' => $idDenunciante,
                'asunto' => $asunto,
                'motivo' => $data['motivo'] ?? null,
                'detalle' => $detalle,
                'evidencia' => $evidencia,
                'metadata' => $this->normalizarJsonArray($data['metadata'] ?? []),
                'estado' => 'pendiente',
            ]);

            $notificacion = $this->notificaciones->notificarNuevaDenuncia($denuncia);

            return [
                'denuncia' => $denuncia,
                'notificacion' => $notificacion,
            ];
        });

        return [
            'status' => 'success',
            'message' => 'Solicitud registrada correctamente.',
            'data' => array_merge(
                $this->serializarDenuncia($resultado['denuncia']),
                ['notificacion_admin' => $resultado['notificacion']]
            ),
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
