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
            ->andReturnTrue();

        $controller = new ProyectoMediaController($permissionService, $mediaService);
        $request = Request::create('/api/projects/10/documents', 'DELETE', [
            'urls' => ['https://example.com/document.pdf'],
        ]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->deleteDocuments($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'Documentos eliminados correctamente'], $response->getData(true));
    }
}
