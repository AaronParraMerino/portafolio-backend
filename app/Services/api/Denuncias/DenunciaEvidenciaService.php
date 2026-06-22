<?php

namespace App\Services\api\Denuncias;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DenunciaEvidenciaService
{
    public function guardarImagen(UploadedFile $image, int $idDenunciante): array
    {
        $upload = $this->guardarEnSupabase($image, $idDenunciante)
            ?? $this->guardarLocalmente($image);

        return [
            'tipo' => 'imagen',
            'nombre' => $image->getClientOriginalName(),
            'mime' => $image->getMimeType(),
            'size' => $image->getSize(),
            'path' => $upload['path'],
            'url' => $upload['url'],
        ];
    }

    private function guardarEnSupabase(UploadedFile $image, int $idDenunciante): ?array
    {
        $bucket = trim((string) env('SUPABASE_BUCKET'), '/');
        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');
        $key = (string) env('SUPABASE_KEY');

        if ($bucket === '' || $urlBase === '' || $key === '') {
            return null;
        }

        $extension = strtolower($image->getClientOriginalExtension() ?: $image->extension() ?: 'jpg');
        $path = 'denuncias/usuario-' . $idDenunciante . '/' . Str::uuid() . '.' . $extension;
        $content = file_get_contents($image->getRealPath());

        if ($content === false) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'apikey' => $key,
                'Content-Type' => $image->getMimeType() ?: 'application/octet-stream',
            ])
                ->withBody($content, $image->getMimeType() ?: 'application/octet-stream')
                ->post($urlBase . '/storage/v1/object/' . $bucket . '/' . $path);

            if ($response->failed()) {
                Log::warning('No se pudo subir evidencia de denuncia a Supabase Storage.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo subir evidencia de denuncia a Supabase Storage.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return [
            'path' => $path,
            'url' => $urlBase . '/storage/v1/object/public/' . $bucket . '/' . $path,
        ];
    }

    private function guardarLocalmente(UploadedFile $image): array
    {
        $path = $image->store('denuncias/evidencias', 'public');

        return [
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ];
    }
}
