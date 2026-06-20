<?php

namespace Tests\Unit;

use App\Services\api\EventosNotificacionGuardadoService;
use App\Services\api\NotificacionService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificacionProjectGroupingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge();
        DB::reconnect();

        Schema::create('usuarios', function (Blueprint $table) {
            $table->id('id_usuario');
            $table->string('nombre')->nullable();
            $table->string('apellido')->nullable();
            $table->string('correo')->nullable();
        });

        Schema::create('proyectos', function (Blueprint $table) {
            $table->id('id_proyecto');
            $table->string('titulo');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('participaciones', function (Blueprint $table) {
            $table->id('id_participacion');
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_proyecto');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id('id_notificacion');
            $table->unsignedBigInteger('id_usuario_actor')->nullable();
            $table->string('modulo');
            $table->string('contexto_tipo')->nullable();
            $table->string('contexto_referencia')->nullable();
            $table->string('grupo_titulo')->nullable();
            $table->string('tipo');
            $table->text('mensaje');
            $table->string('accion_estado')->nullable();
            $table->text('accion_respuesta')->nullable();
            $table->timestamp('accion_respondida_at')->nullable();
            $table->timestamp('accion_disponible_nuevamente_at')->nullable();
            $table->json('metadata')->nullable();
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

    public function test_project_groups_are_merged_by_context_reference_when_titles_changed(): void
    {
        $this->insertProjectNotification(1, 20, 'proyecto_3', 'eliminar 2', '2026-06-14 09:00:00');
        $this->insertProjectNotification(2, 20, 'proyecto_3', 'App de turismo', '2026-06-15 09:00:00');

        $response = $this->notificationService()->obtenerSegundoNivelPorModulo(20, 'proyectos');

        $this->assertSame('success', $response['status']);
        $this->assertCount(1, $response['data']);
        $this->assertSame('proyecto_3', $response['data'][0]['contexto_referencia']);
        $this->assertSame('App de turismo', $response['data'][0]['titulo']);
        $this->assertSame(2, $response['data'][0]['cantidad']);
    }

    public function test_project_rename_updates_group_title_without_touching_read_state(): void
    {
        DB::table('usuarios')->insert([
            ['id_usuario' => 10, 'nombre' => 'Jose'],
            ['id_usuario' => 20, 'nombre' => 'Ana'],
        ]);
        DB::table('proyectos')->insert([
            'id_proyecto' => 3,
            'titulo' => 'App de turismo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('participaciones')->insert([
            'id_usuario' => 20,
            'id_proyecto' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertProjectNotification(1, 20, 'proyecto_3', 'eliminar 2', '2026-06-14 09:00:00', '2026-06-15 11:00:00');

        $result = (new ProyectoNotificacionGuardadoService())->notificarProyectoRenombrado(
            idProyecto: 3,
            idUsuarioActor: 10,
            tituloAnterior: 'eliminar 2',
            tituloNuevo: 'App de turismo'
        );

        $this->assertTrue($result['status']);
        $this->assertSame('App de turismo', DB::table('notificaciones')->where('id_notificacion', 1)->value('grupo_titulo'));
        $this->assertSame(
            '2026-06-15 11:00:00',
            DB::table('notificacion_usuario')->where('id_notificacion', 1)->value('leido_en')
        );
        $this->assertDatabaseHas('notificaciones', [
            'tipo' => 'project_renamed',
            'contexto_referencia' => 'proyecto_3',
            'grupo_titulo' => 'App de turismo',
        ]);
    }

    private function insertProjectNotification(
        int $notificationId,
        int $userId,
        string $context,
        string $title,
        string $createdAt,
        ?string $readAt = null
    ): void {
        DB::table('notificaciones')->insert([
            'id_notificacion' => $notificationId,
            'id_usuario_actor' => null,
            'modulo' => 'proyectos',
            'contexto_tipo' => 'proyecto',
            'contexto_referencia' => $context,
            'grupo_titulo' => $title,
            'tipo' => 'project_updated',
            'mensaje' => 'Proyecto actualizado',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('notificacion_usuario')->insert([
            'id_notificacion' => $notificationId,
            'id_usuario' => $userId,
            'leido_en' => $readAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function notificationService(): NotificacionService
    {
        return new NotificacionService(new EventosNotificacionGuardadoService());
    }
}
