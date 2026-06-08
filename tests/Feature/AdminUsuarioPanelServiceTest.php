<?php

namespace Tests\Feature;

use App\Services\api\Administrador\AdminUsuarioPanelService;
use App\Services\api\ProfileImageVariantService;
use Mockery;
use Tests\TestCase;

class AdminUsuarioPanelServiceTest extends TestCase
{
    public function test_panel_preserves_frontend_contract(): void
    {
        $imageVariants = Mockery::mock(ProfileImageVariantService::class);
        $imageVariants->shouldReceive('getVariantUrl')->zeroOrMoreTimes()->andReturnNull();
        $service = new AdminUsuarioPanelService($imageVariants);

        $panel = $service->getPanel();

        $this->assertTrue($panel['items']->every(fn (array $item) => array_key_exists('id', $item)));
        $this->assertLessThanOrEqual(20, $panel['history']->count());
        $this->assertSame(
            ['total', 'activo', 'pausado', 'bloqueado', 'inactivo'],
            array_keys($panel['metrics'])
        );
        $this->assertTrue($panel['sourceReady']);
        $this->assertTrue($panel['supportsSessions']);
        $this->assertTrue($panel['supportsRoleManagement']);
    }
}
