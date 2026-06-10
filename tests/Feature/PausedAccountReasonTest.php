<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\api\Auth\DiscordOAuthService;
use App\Services\api\Auth\GithubOAuthService;
use App\Services\api\Auth\GitlabOAuthService;
use App\Services\api\Auth\GoogleOAuthService;
use App\Services\api\AuthService;
use App\Services\api\SeccionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PausedAccountReasonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge();
        DB::reconnect();

        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id('id_notificacion');
            $table->string('modulo');
            $table->string('tipo');
            $table->text('mensaje');
            $table->timestamps();
        });

        Schema::create('notificacion_usuario', function (Blueprint $table) {
            $table->id('id_notificacion_usuario');
            $table->unsignedBigInteger('id_notificacion');
            $table->unsignedBigInteger('id_usuario');
            $table->timestamp('leido_en')->nullable();
            $table->timestamps();
        });
    }

    public function test_paused_account_uses_latest_admin_notice_message_as_reason(): void
    {
        DB::table('notificaciones')->insert([
            [
                'id_notificacion' => 1,
                'modulo' => 'administracion',
                'tipo' => 'admin_notice_cuenta',
                'mensaje' => 'Primera revision.',
                'created_at' => '2026-06-09 10:00:00',
                'updated_at' => '2026-06-09 10:00:00',
            ],
            [
                'id_notificacion' => 2,
                'modulo' => 'administracion',
                'tipo' => 'admin_notice_cuenta',
                'mensaje' => 'Revision administrativa pendiente.',
                'created_at' => '2026-06-10 10:00:00',
                'updated_at' => '2026-06-10 10:00:00',
            ],
        ]);
        DB::table('notificacion_usuario')->insert([
            ['id_notificacion' => 1, 'id_usuario' => 39],
            ['id_notificacion' => 2, 'id_usuario' => 39],
        ]);

        $usuario = new Usuario(['estado' => 'pausado']);
        $usuario->id_usuario = 39;

        $decorated = $this->authService()->decorateAccountState($usuario);

        $this->assertSame('Revision administrativa pendiente.', $decorated->razon_pausa);
    }

    private function authService(): AuthService
    {
        return new AuthService(
            Mockery::mock(SeccionService::class),
            Mockery::mock(GoogleOAuthService::class),
            Mockery::mock(GithubOAuthService::class),
            Mockery::mock(GitlabOAuthService::class),
            Mockery::mock(DiscordOAuthService::class),
        );
    }
}
