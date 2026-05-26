<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\api\PersonalizacionPortafolioService;
use App\Services\api\PortafolioPublicoService;
use App\Services\api\ProfileImageVariantService;
use App\Services\api\UsuarioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InactiveAccountVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        DB::purge();
        DB::reconnect();

        $this->createTables();
    }

    public function test_deactivation_hides_personal_content_without_hiding_shared_project_content(): void
    {
        $userId = $this->createUser('activo');

        DB::table('visibilidad_campos')->insert(['usuario_id' => $userId, 'campo' => 'correo', 'visible' => true]);
        DB::table('enlaces')->insert(['id_usuario' => $userId, 'es_visible' => true]);
        DB::table('habilidades_usuario')->insert(['usuario_id' => $userId, 'es_visible' => true]);
        DB::table('experiencias')->insert(['usuario_id' => $userId, 'es_publico' => true]);
        DB::table('participaciones')->insert([
            'id_usuario' => $userId,
            'id_proyecto' => 12,
            'visibilidad' => 'publico',
            'es_propietario' => true,
            'deleted_at' => null,
        ]);
        DB::table('proyecto_evidencias')->insert(['id_proyecto' => 12, 'es_visible' => true, 'deleted_at' => null]);
        DB::table('uso_tecnologias')->insert(['id_proyecto' => 12, 'es_visible' => true, 'deleted_at' => null]);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => Usuario::class,
            'tokenable_id' => $userId,
        ]);

        (new UsuarioService())->delete(Usuario::findOrFail($userId));

        $this->assertDatabaseHas('usuarios', ['id_usuario' => $userId, 'estado' => 'inactivo']);
        $this->assertDatabaseHas('visibilidad_campos', ['usuario_id' => $userId, 'visible' => false]);
        $this->assertDatabaseHas('enlaces', ['id_usuario' => $userId, 'es_visible' => false]);
        $this->assertDatabaseHas('habilidades_usuario', ['usuario_id' => $userId, 'es_visible' => false]);
        $this->assertDatabaseHas('experiencias', ['usuario_id' => $userId, 'es_publico' => false]);
        $this->assertDatabaseHas('participaciones', ['id_usuario' => $userId, 'visibilidad' => 'privado']);
        $this->assertDatabaseHas('proyecto_evidencias', ['id_proyecto' => 12, 'es_visible' => true]);
        $this->assertDatabaseHas('uso_tecnologias', ['id_proyecto' => 12, 'es_visible' => true]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $userId]);
    }

    public function test_public_portfolio_is_not_available_for_an_inactive_user(): void
    {
        $userId = $this->createUser('inactivo');
        DB::table('perfiles')->insert(['usuario_id' => $userId, 'es_publico' => true]);

        $this->assertNull($this->publicPortfolioService()->getByUser($userId));
    }

    public function test_pause_preserves_visibility_and_active_sessions(): void
    {
        $userId = $this->createUser('activo');

        DB::table('visibilidad_campos')->insert(['usuario_id' => $userId, 'campo' => 'correo', 'visible' => true]);
        DB::table('enlaces')->insert(['id_usuario' => $userId, 'es_visible' => true]);
        DB::table('habilidades_usuario')->insert(['usuario_id' => $userId, 'es_visible' => true]);
        DB::table('experiencias')->insert(['usuario_id' => $userId, 'es_publico' => true]);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => Usuario::class,
            'tokenable_id' => $userId,
        ]);

        (new UsuarioService())->pause(Usuario::findOrFail($userId));

        $this->assertDatabaseHas('usuarios', ['id_usuario' => $userId, 'estado' => 'pausado']);
        $this->assertDatabaseHas('visibilidad_campos', ['usuario_id' => $userId, 'visible' => true]);
        $this->assertDatabaseHas('enlaces', ['id_usuario' => $userId, 'es_visible' => true]);
        $this->assertDatabaseHas('habilidades_usuario', ['usuario_id' => $userId, 'es_visible' => true]);
        $this->assertDatabaseHas('experiencias', ['usuario_id' => $userId, 'es_publico' => true]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $userId]);
    }

    public function test_public_project_includes_unvalidated_participants_when_configured_visible(): void
    {
        $userId = $this->createUser('activo');
        $this->createUnvalidatedProjectParticipant($userId, 'visible');

        $participantes = $this->publicProjectParticipants(12);

        $this->assertCount(1, $participantes);
        $this->assertSame('usuario_sin_validacion_github', $participantes[0]['tipo_participante']);
        $this->assertFalse($participantes[0]['validacion_github']);
    }

    public function test_public_project_hides_unvalidated_participants_when_configured_hidden(): void
    {
        $userId = $this->createUser('activo');
        $this->createUnvalidatedProjectParticipant($userId, 'oculto');

        $this->assertSame([], $this->publicProjectParticipants(12));
    }

    private function createUser(string $estado): int
    {
        return (int) DB::table('usuarios')->insertGetId([
            'nombre' => 'Usuario',
            'apellido' => 'Prueba',
            'correo' => uniqid('usuario-', true) . '@example.com',
            'password' => 'secret',
            'rol' => 'usuario',
            'estado' => $estado,
            'intentos_fallidos' => 0,
            'fecha_bloqueo' => null,
        ], 'id_usuario');
    }

    private function createUnvalidatedProjectParticipant(int $userId, string $visibility): void
    {
        DB::table('perfiles')->insert([
            'usuario_id' => $userId,
            'foto_perfil' => null,
            'es_publico' => true,
        ]);
        DB::table('participaciones')->insert([
            'id_usuario' => $userId,
            'id_proyecto' => 12,
            'rol' => 'Colaborador',
            'descripcion_aporte' => null,
            'visibilidad' => 'publico',
            'es_propietario' => false,
            'participacion_validada' => false,
            'deleted_at' => null,
        ]);
        DB::table('proyecto_configuraciones')->insert([
            'id_proyecto' => 12,
            'visibilidad_usuario_sin_validacion' => $visibility,
        ]);
    }

    private function publicProjectParticipants(int $projectId): array
    {
        $method = new \ReflectionMethod(PortafolioPublicoService::class, 'getParticipantesPublicos');

        return $method->invoke($this->publicPortfolioService(), $projectId);
    }

    private function publicPortfolioService(): PortafolioPublicoService
    {
        return new PortafolioPublicoService(
            new PersonalizacionPortafolioService(),
            new ProfileImageVariantService()
        );
    }

    private function createTables(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->increments('id_usuario');
            $table->string('nombre');
            $table->string('apellido');
            $table->string('correo');
            $table->string('password');
            $table->string('rol');
            $table->string('estado');
            $table->integer('intentos_fallidos')->default(0);
            $table->timestamp('fecha_bloqueo')->nullable();
            $table->timestamps();
        });

        Schema::create('perfiles', function (Blueprint $table) {
            $table->increments('id_perfil');
            $table->unsignedInteger('usuario_id');
            $table->string('foto_perfil')->nullable();
            $table->boolean('es_publico')->default(true);
            $table->timestamps();
        });

        Schema::create('visibilidad_campos', function (Blueprint $table) {
            $table->increments('id_visibilidad');
            $table->unsignedInteger('usuario_id');
            $table->string('campo');
            $table->boolean('visible')->default(true);
        });

        Schema::create('enlaces', function (Blueprint $table) {
            $table->increments('id_enlace');
            $table->unsignedInteger('id_usuario');
            $table->boolean('es_visible')->default(true);
        });

        Schema::create('habilidades_usuario', function (Blueprint $table) {
            $table->increments('id_habilidad_usuario');
            $table->unsignedInteger('usuario_id');
            $table->boolean('es_visible')->default(true);
        });

        Schema::create('experiencias', function (Blueprint $table) {
            $table->increments('id_experiencia');
            $table->unsignedInteger('usuario_id');
            $table->boolean('es_publico')->default(true);
        });

        Schema::create('participaciones', function (Blueprint $table) {
            $table->increments('id_participacion');
            $table->unsignedInteger('id_usuario');
            $table->unsignedInteger('id_proyecto');
            $table->string('rol')->nullable();
            $table->text('descripcion_aporte')->nullable();
            $table->boolean('es_propietario')->default(false);
            $table->boolean('participacion_validada')->default(false);
            $table->string('visibilidad')->default('publico');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('proyecto_configuraciones', function (Blueprint $table) {
            $table->increments('id_configuracion');
            $table->unsignedInteger('id_proyecto');
            $table->string('visibilidad_usuario_sin_validacion')->default('visible');
        });

        Schema::create('proyecto_repositorios', function (Blueprint $table) {
            $table->increments('id_proyecto_repositorio');
            $table->unsignedInteger('id_proyecto');
            $table->string('proveedor')->default('github');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('repositorio_github', function (Blueprint $table) {
            $table->increments('id_repositorio_github');
            $table->unsignedInteger('id_proyecto_repositorio');
        });

        Schema::create('cuentas_oauth', function (Blueprint $table) {
            $table->increments('id_cuenta_oauth');
            $table->unsignedInteger('usuario_id');
            $table->string('provider');
            $table->string('provider_user_id')->nullable();
            $table->string('nombre')->nullable();
            $table->string('foto_url')->nullable();
        });

        Schema::create('usuario_repositorio_validaciones', function (Blueprint $table) {
            $table->increments('id_validacion');
            $table->unsignedInteger('id_usuario');
            $table->unsignedInteger('id_repositorio_github');
            $table->boolean('validado')->default(false);
            $table->string('relacion_github')->nullable();
            $table->boolean('es_propietario')->default(false);
            $table->timestamp('ultima_verificacion_at')->nullable();
        });

        Schema::create('proyecto_evidencias', function (Blueprint $table) {
            $table->increments('id_evidencia');
            $table->unsignedInteger('id_proyecto');
            $table->boolean('es_visible')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('uso_tecnologias', function (Blueprint $table) {
            $table->increments('id_uso');
            $table->unsignedInteger('id_proyecto');
            $table->boolean('es_visible')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tokenable_type');
            $table->unsignedInteger('tokenable_id');
        });
    }
}
