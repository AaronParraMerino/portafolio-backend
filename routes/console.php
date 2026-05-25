<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Models\Perfil;
use App\Services\api\ProfileImageVariantService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('profile-images:generate-variants {--user= : Procesar solo un usuario}', function () {
    $variants = app(ProfileImageVariantService::class);

    if (! $variants->canProcessImages()) {
        $this->error('No se pueden generar variantes: habilita la extension PHP GD.');
        return 1;
    }

    $query = Perfil::query()
        ->whereNotNull('foto_perfil')
        ->where('foto_perfil', '<>', '');

    if ($userId = $this->option('user')) {
        $query->where('usuario_id', (int) $userId);
    }

    $processed = 0;
    $skipped = 0;
    $failed = 0;

    $query->orderBy('id_perfil')->chunkById(50, function ($perfiles) use ($variants, &$processed, &$skipped, &$failed) {
        foreach ($perfiles as $perfil) {
            if ($variants->getVariantUrls($perfil->foto_perfil) === []) {
                $skipped++;
                $this->line("Omitida foto externa o no administrada: usuario {$perfil->usuario_id}");
                continue;
            }

            try {
                $variants->generateFromUrl($perfil->foto_perfil);
                $processed++;
                $this->info("Variantes generadas: usuario {$perfil->usuario_id}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Error en usuario {$perfil->usuario_id}: {$e->getMessage()}");
            }
        }
    }, 'id_perfil');

    $this->newLine();
    $this->info("Finalizado. Procesadas: {$processed}; omitidas: {$skipped}; fallidas: {$failed}.");

    return $failed > 0 ? 1 : 0;
})->purpose('Genera medium, small y thumb para fotos de perfil existentes sin cambiar la URL original');

Artisan::command('banner-images:generate-variants {--user= : Procesar solo un usuario}', function () {
    $variants = app(ProfileImageVariantService::class);

    if (! $variants->canProcessImages()) {
        $this->error('No se pueden generar variantes: habilita la extension PHP GD.');
        return 1;
    }

    $query = Perfil::query()
        ->whereNotNull('foto_fondo')
        ->where('foto_fondo', '<>', '');

    if ($userId = $this->option('user')) {
        $query->where('usuario_id', (int) $userId);
    }

    $processed = 0;
    $skipped = 0;
    $failed = 0;

    $query->orderBy('id_perfil')->chunkById(50, function ($perfiles) use ($variants, &$processed, &$skipped, &$failed) {
        foreach ($perfiles as $perfil) {
            if ($variants->getBannerVariantUrls($perfil->foto_fondo) === []) {
                $skipped++;
                $this->line("Omitido banner externo o no administrado: usuario {$perfil->usuario_id}");
                continue;
            }

            try {
                $variants->generateBannerFromUrl($perfil->foto_fondo);
                $processed++;
                $this->info("Variantes de banner generadas: usuario {$perfil->usuario_id}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Error en usuario {$perfil->usuario_id}: {$e->getMessage()}");
            }
        }
    }, 'id_perfil');

    $this->newLine();
    $this->info("Finalizado. Procesadas: {$processed}; omitidas: {$skipped}; fallidas: {$failed}.");

    return $failed > 0 ? 1 : 0;
})->purpose('Genera medium y small para banners existentes sin cambiar la URL original');

Artisan::command('project-images:generate-variants {--project= : Procesar solo un proyecto}', function () {
    $variants = app(ProfileImageVariantService::class);

    if (! $variants->canProcessImages()) {
        $this->error('No se pueden generar variantes: habilita la extension PHP GD.');
        return 1;
    }

    $query = DB::table('proyecto_evidencias')
        ->whereIn('tipo', ['imagen', 'captura'])
        ->whereNotNull('url')
        ->where('url', '<>', '')
        ->whereNull('deleted_at');

    if ($projectId = $this->option('project')) {
        $query->where('id_proyecto', (int) $projectId);
    }

    $processed = 0;
    $skipped = 0;
    $failed = 0;

    $query->orderBy('id_evidencia')->chunkById(50, function ($evidencias) use ($variants, &$processed, &$skipped, &$failed) {
        foreach ($evidencias as $evidencia) {
            if ($variants->getProjectVariantUrls($evidencia->url) === []) {
                $skipped++;
                $this->line("Omitida imagen externa o no administrada: evidencia {$evidencia->id_evidencia}");
                continue;
            }

            try {
                $variants->generateProjectFromUrl($evidencia->url);
                $processed++;
                $this->info("Variantes de proyecto generadas: evidencia {$evidencia->id_evidencia}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Error en evidencia {$evidencia->id_evidencia}: {$e->getMessage()}");
            }
        }
    }, 'id_evidencia');

    $this->newLine();
    $this->info("Finalizado. Procesadas: {$processed}; omitidas: {$skipped}; fallidas: {$failed}.");

    return $failed > 0 ? 1 : 0;
})->purpose('Genera card y detail para imagenes de proyectos existentes sin cambiar sus URLs originales');
