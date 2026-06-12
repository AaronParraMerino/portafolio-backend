<?php

namespace App\Services\api;

use RuntimeException;

class ProfileImageVariantService
{
    private const PROFILES = [
        'profile' => [
            'pattern' => '#^profile/[^/]+\.[a-z0-9]+$#i',
            'variants' => [
                'medium' => [384, 384],
                'small' => [160, 160],
                'thumb' => [96, 96],
            ],
        ],
        'banner' => [
            'pattern' => '#^banner/[^/]+\.[a-z0-9]+$#i',
            'variants' => [
                'medium' => [1280, 480],
                'small' => [720, 270],
            ],
        ],
        'project' => [
            'pattern' => '#^projects/\d+/images/[^/]+\.[a-z0-9]+$#i',
            'variants' => [
                'detail' => [1280, 720],
                'card' => [640, 360],
            ],
        ],
    ];

    public function canProcessImages(): bool
    {
        return function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagewebp');
    }

    // Compatibility with the already-integrated profile flow.
    public function getVariantUrls(?string $originalUrl): array
    {
        return $this->getProfileVariantUrls($originalUrl);
    }

    public function getVariantUrl(?string $originalUrl, string $variant): ?string
    {
        return $this->getProfileVariantUrls($originalUrl)[$variant] ?? null;
    }

    public function getProfileVariantUrls(?string $originalUrl): array
    {
        return $this->buildVariantUrls($originalUrl, 'profile');
    }

    public function getBannerVariantUrls(?string $originalUrl): array
    {
        return $this->buildVariantUrls($originalUrl, 'banner');
    }

    public function getProjectVariantUrls(?string $originalUrl): array
    {
        return $this->buildVariantUrls($originalUrl, 'project');
    }

    public function generateFromUploadedFile($file, string $originalUrl): array
    {
        return $this->generateProfileFromUploadedFile($file, $originalUrl);
    }

    public function generateFromUrl(string $originalUrl): array
    {
        return $this->generateProfileFromUrl($originalUrl);
    }

    public function generateProfileFromUploadedFile($file, string $originalUrl): array
    {
        return $this->generateUploadedFile($file, $originalUrl, 'profile');
    }

    public function generateBannerFromUploadedFile($file, string $originalUrl): array
    {
        return $this->generateUploadedFile($file, $originalUrl, 'banner');
    }

    public function generateProjectFromUploadedFile($file, string $originalUrl): array
    {
        return $this->generateUploadedFile($file, $originalUrl, 'project');
    }

    public function generateProfileFromUrl(string $originalUrl): array
    {
        return $this->generateUrl($originalUrl, 'profile');
    }

    public function generateBannerFromUrl(string $originalUrl): array
    {
        return $this->generateUrl($originalUrl, 'banner');
    }

    public function generateProjectFromUrl(string $originalUrl): array
    {
        return $this->generateUrl($originalUrl, 'project');
    }

    public function originalExists(string $url): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Range: bytes=0-0'],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $payload = is_string($response) ? json_decode($response, true) : null;
        if ($status === 404 || (int) ($payload['statusCode'] ?? 0) === 404) {
            return false;
        }

        if ($error || $status < 200 || $status >= 300 || ! is_string($response)) {
            throw new RuntimeException('No se pudo comprobar la imagen original.');
        }

        return true;
    }

    public function deleteVariants(?string $originalUrl): void
    {
        $this->deleteProfileVariants($originalUrl);
    }

    public function deleteProfileVariants(?string $originalUrl): void
    {
        $this->deleteVariantSet($originalUrl, 'profile');
    }

    public function deleteBannerVariants(?string $originalUrl): void
    {
        $this->deleteVariantSet($originalUrl, 'banner');
    }

    public function deleteProjectVariants(?string $originalUrl): void
    {
        $this->deleteVariantSet($originalUrl, 'project');
    }

    private function generateUploadedFile($file, string $originalUrl, string $profile): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new RuntimeException('No se pudo leer la imagen para generar variantes.');
        }

        return $this->generateFromContent($content, $originalUrl, $profile);
    }

    private function generateUrl(string $originalUrl, string $profile): array
    {
        return $this->generateFromContent($this->download($originalUrl), $originalUrl, $profile);
    }

    private function deleteVariantSet(?string $originalUrl, string $profile): void
    {
        foreach ($this->buildVariantUrls($originalUrl, $profile) as $url) {
            $this->deleteObject($this->relativeStoragePath($url));
        }
    }

    private function generateFromContent(string $content, string $originalUrl, string $profile): array
    {
        if (! $this->canProcessImages()) {
            throw new RuntimeException('La extension PHP GD es requerida para generar variantes de imagen.');
        }

        $urls = $this->buildVariantUrls($originalUrl, $profile);
        if ($urls === []) {
            throw new RuntimeException("La imagen no pertenece a la ruta {$profile} administrada.");
        }

        $source = @imagecreatefromstring($content);
        if ($source === false) {
            throw new RuntimeException('La imagen original no pudo ser procesada.');
        }

        try {
            foreach (self::PROFILES[$profile]['variants'] as $variant => [$width, $height]) {
                $variantContent = $this->resizeToCropWebp($source, $width, $height);
                $this->uploadObject($this->relativeStoragePath($urls[$variant]), $variantContent, 'image/webp');
            }
        } finally {
            imagedestroy($source);
        }

        return $urls;
    }

    private function resizeToCropWebp($source, int $width, int $height): string
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetRatio = $width / $height;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($cropHeight * $targetRatio);
            $sourceX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $sourceY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($cropWidth / $targetRatio);
            $sourceX = 0;
            $sourceY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $width,
            $height,
            $cropWidth,
            $cropHeight
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

    private function buildVariantUrls(?string $originalUrl, string $profile): array
    {
        $path = $this->getManagedPath($originalUrl, $profile);
        if ($path === null) {
            return [];
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $folder = trim((string) pathinfo($path, PATHINFO_DIRNAME), '/.');
        $prefix = $this->publicStoragePrefix();
        $urls = [];

        foreach (array_keys(self::PROFILES[$profile]['variants']) as $variant) {
            $urls[$variant] = "{$prefix}/{$folder}/{$variant}/{$filename}.webp";
        }

        return $urls;
    }

    private function getManagedPath(?string $url, string $profile): ?string
    {
        if (! is_string($url) || ! isset(self::PROFILES[$profile])) {
            return null;
        }

        $prefix = $this->publicStoragePrefix() . '/';
        if (! str_starts_with($url, $prefix)) {
            return null;
        }

        $path = $this->relativeStoragePath($url);

        return preg_match(self::PROFILES[$profile]['pattern'], $path) === 1 ? $path : null;
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

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $payload = is_string($response) ? json_decode($response, true) : null;
        $notFound = $status === 404 || (int) ($payload['statusCode'] ?? 0) === 404;

        if ($error || (! $notFound && ($status < 200 || $status >= 300))) {
            throw new RuntimeException('No se pudo eliminar una variante de imagen de Supabase Storage.');
        }
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
