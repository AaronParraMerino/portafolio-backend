<?php

namespace App\Services\api;

use RuntimeException;

class ProfileImageVariantService
{
    private const VARIANTS = [
        'medium' => 384,
        'small' => 160,
        'thumb' => 96,
    ];

    public function canProcessImages(): bool
    {
        return function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagewebp');
    }

    public function getVariantUrls(?string $originalUrl): array
    {
        $path = $this->getManagedProfilePath($originalUrl);
        if ($path === null) {
            return [];
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $prefix = $this->publicStoragePrefix();

        return [
            'medium' => "{$prefix}/profile/medium/{$filename}.webp",
            'small' => "{$prefix}/profile/small/{$filename}.webp",
            'thumb' => "{$prefix}/profile/thumb/{$filename}.webp",
        ];
    }

    public function getVariantUrl(?string $originalUrl, string $variant): ?string
    {
        return $this->getVariantUrls($originalUrl)[$variant] ?? null;
    }

    public function generateFromUploadedFile($file, string $originalUrl): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new RuntimeException('No se pudo leer la imagen para generar variantes.');
        }

        return $this->generateFromContent($content, $originalUrl);
    }

    public function generateFromUrl(string $originalUrl): array
    {
        $content = $this->download($originalUrl);

        return $this->generateFromContent($content, $originalUrl);
    }

    public function deleteVariants(?string $originalUrl): void
    {
        foreach ($this->getVariantUrls($originalUrl) as $url) {
            $this->deleteObject($this->relativeStoragePath($url));
        }
    }

    private function generateFromContent(string $content, string $originalUrl): array
    {
        if (! $this->canProcessImages()) {
            throw new RuntimeException('La extension PHP GD es requerida para generar variantes de imagen.');
        }

        $urls = $this->getVariantUrls($originalUrl);
        if ($urls === []) {
            throw new RuntimeException('La imagen no pertenece a la ruta profile administrada.');
        }

        $source = @imagecreatefromstring($content);
        if ($source === false) {
            throw new RuntimeException('La imagen original no pudo ser procesada.');
        }

        try {
            foreach (self::VARIANTS as $variant => $size) {
                $variantContent = $this->resizeToSquareWebp($source, $size);
                $this->uploadObject($this->relativeStoragePath($urls[$variant]), $variantContent, 'image/webp');
            }
        } finally {
            imagedestroy($source);
        }

        return $urls;
    }

    private function resizeToSquareWebp($source, int $size): string
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $cropSize = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
        $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);
        $target = imagecreatetruecolor($size, $size);

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $size, $size, $transparent);

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $size,
            $size,
            $cropSize,
            $cropSize
        );

        ob_start();
        imagewebp($target, null, 84);
        $content = ob_get_clean();
        imagedestroy($target);

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('No se pudo convertir la variante a WebP.');
        }

        return $content;
    }

    private function getManagedProfilePath(?string $url): ?string
    {
        if (! is_string($url) || ! str_starts_with($url, $this->publicStoragePrefix() . '/profile/')) {
            return null;
        }

        $path = $this->relativeStoragePath($url);

        return preg_match('#^profile/[^/]+\.[a-z0-9]+$#i', $path) === 1
            ? $path
            : null;
    }

    private function relativeStoragePath(string $url): string
    {
        return ltrim(substr($url, strlen($this->publicStoragePrefix())), '/');
    }

    private function publicStoragePrefix(): string
    {
        return rtrim((string) env('SUPABASE_URL'), '/')
            . '/storage/v1/object/public/'
            . trim((string) env('SUPABASE_BUCKET'), '/');
    }

    private function uploadObject(string $path, string $content, string $mimeType): void
    {
        [$urlBase, $bucket, $key] = $this->storageConfig();
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => "{$urlBase}/storage/v1/object/{$bucket}/{$path}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: ' . $mimeType,
                'x-upsert: true',
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $status < 200 || $status >= 300) {
            throw new RuntimeException('Error al subir variante de imagen: ' . ($error ?: (string) $response));
        }
    }

    private function deleteObject(string $path): void
    {
        [$urlBase, $bucket, $key] = $this->storageConfig();
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => "{$urlBase}/storage/v1/object/{$bucket}/{$path}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
            ],
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    private function download(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $content = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $status < 200 || $status >= 300 || ! is_string($content)) {
            throw new RuntimeException('No se pudo descargar la imagen original.');
        }

        return $content;
    }

    private function storageConfig(): array
    {
        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');
        $bucket = trim((string) env('SUPABASE_BUCKET'), '/');
        $key = (string) env('SUPABASE_KEY');

        if ($urlBase === '' || $bucket === '' || $key === '') {
            throw new RuntimeException('Supabase Storage no esta configurado.');
        }

        return [$urlBase, $bucket, $key];
    }
}
