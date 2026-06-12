<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoMediaController;
use App\Services\api\Proyecto\ProyectoMediaService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoMediaControllerTest extends TestCase
{
    public function test_user_without_project_access_cannot_delete_images(): void
    {
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnFalse();
        $permissionService->shouldNotReceive('resolve');

        $mediaService = Mockery::mock(ProyectoMediaService::class);
        $mediaService->shouldNotReceive('deleteImages');

        $controller = new ProyectoMediaController($permissionService, $mediaService);
        $request = Request::create('/api/projects/10/images', 'DELETE', [
            'urls' => ['https://example.com/image.png'],
        ]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->deleteImages($request, 10);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Proyecto no encontrado'], $response->getData(true));
    }

    public function test_authorized_user_can_delete_documents(): void
    {
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnTrue();
        $permissionService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_editar' => true]);

        $mediaService = Mockery::mock(ProyectoMediaService::class);
        $mediaService->shouldReceive('deleteDocuments')
            ->once()
            ->with(10, 5, ['https://example.com/document.pdf'])
            ->andReturn(1);

        $controller = new ProyectoMediaController($permissionService, $mediaService);
        $request = Request::create('/api/projects/10/documents', 'DELETE', [
            'urls' => ['https://example.com/document.pdf'],
        ]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->deleteDocuments($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'message' => 'Documentos eliminados correctamente',
            'eliminados' => 1,
        ], $response->getData(true));
    }

    public function test_delete_images_reports_when_no_active_evidence_matches(): void
    {
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnTrue();
        $permissionService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_editar' => true]);

        $mediaService = Mockery::mock(ProyectoMediaService::class);
        $mediaService->shouldReceive('deleteImages')
            ->once()
            ->with(10, 5, ['https://example.com/missing.png'])
            ->andReturn(0);

        $controller = new ProyectoMediaController($permissionService, $mediaService);
        $request = Request::create('/api/projects/10/images', 'DELETE', [
            'urls' => ['https://example.com/missing.png'],
        ]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->deleteImages($request, 10);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'No se encontraron imágenes activas para eliminar'],
            $response->getData(true)
        );
    }

    public function test_authorized_user_can_delete_images(): void
    {
        $imageUrl = 'https://example.com/image.png';
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnTrue();
        $permissionService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_editar' => true]);

        $mediaService = Mockery::mock(ProyectoMediaService::class);
        $mediaService->shouldReceive('deleteImages')
            ->once()
            ->with(10, 5, [$imageUrl])
            ->andReturn(1);

        $controller = new ProyectoMediaController($permissionService, $mediaService);
        $request = Request::create('/api/projects/10/images', 'DELETE', ['urls' => [$imageUrl]]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->deleteImages($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'message' => 'Imágenes eliminadas correctamente',
            'eliminadas' => 1,
        ], $response->getData(true));
    }

    public function test_authorized_user_can_repair_image_variants(): void
    {
        $originalUrl = 'https://example.com/projects/10/images/original.png';
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnTrue();
        $permissionService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_editar' => true]);

        $mediaService = Mockery::mock(ProyectoMediaService::class);
        $mediaService->shouldReceive('repairImageVariants')
            ->once()
            ->with(10, $originalUrl)
            ->andReturn([
                'status' => 'repaired',
                'variants' => ['card' => 'card.webp', 'detail' => 'detail.webp'],
            ]);

        $controller = new ProyectoMediaController($permissionService, $mediaService);
        $request = Request::create('/api/projects/10/images/repair-variants', 'POST', [
            'original_url' => $originalUrl,
        ]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->repairImageVariants($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('repaired', $response->getData(true)['data']['status']);
    }

    public function test_repair_removes_evidence_when_original_is_missing(): void
    {
        $originalUrl = 'https://example.com/projects/10/images/missing.png';
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnTrue();
        $permissionService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_editar' => true]);

        $mediaService = Mockery::mock(ProyectoMediaService::class);
        $mediaService->shouldReceive('repairImageVariants')
            ->once()
            ->with(10, $originalUrl)
            ->andReturn(['status' => 'original_missing']);

        $controller = new ProyectoMediaController($permissionService, $mediaService);
        $request = Request::create('/api/projects/10/images/repair-variants', 'POST', [
            'original_url' => $originalUrl,
        ]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->repairImageVariants($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('original_missing', $response->getData(true)['data']['status']);
    }
}
