<?php

namespace Tests\Feature;

use App\Models\Proyecto;
use App\Models\TipoProyecto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function usuario(): Usuario
    {
        return Usuario::factory()->create();
    }

    private function tipo(): TipoProyecto
    {
        return TipoProyecto::firstOrCreate(['nombre' => 'web'], ['descripcion' => 'Web']);
    }

    /** Crea un proyecto con participación del usuario dado. */
    private function crearProyecto(Usuario $usuario, array $attrs = []): Proyecto
    {
        $tipo = $this->tipo();

        $proyecto = Proyecto::create(array_merge([
            'titulo'             => 'Proyecto de prueba',
            'descripcion'        => 'Descripción de prueba',
            'id_tipo_proyecto'   => $tipo->id_tipo_proyecto,
            'estado_desarrollo'  => 'completado',
        ], $attrs));

        $proyecto->participaciones()->create([
            'id_usuario'   => $usuario->id_usuario,
            'rol'          => 'desarrollador',
            'es_propietario' => true,
            'visibilidad'  => 'publico',
        ]);

        return $proyecto;
    }

    // ── GET /api/projects/usuario/{userId} ───────────────────────────────────

    public function test_index_devuelve_proyectos_del_usuario(): void
    {
        $usuario = $this->usuario();
        $this->crearProyecto($usuario);
        $this->crearProyecto($usuario, ['titulo' => 'Segundo proyecto']);

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/projects/usuario/{$usuario->id_usuario}")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_index_requiere_autenticacion(): void
    {
        $usuario = $this->usuario();

        $this->getJson("/api/projects/usuario/{$usuario->id_usuario}")
            ->assertUnauthorized();
    }

    // ── GET /api/projects/{id} ───────────────────────────────────────────────

    public function test_show_devuelve_el_proyecto(): void
    {
        $usuario  = $this->usuario();
        $proyecto = $this->crearProyecto($usuario);

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/projects/{$proyecto->id_proyecto}")
            ->assertOk()
            ->assertJsonFragment(['titulo' => 'Proyecto de prueba']);
    }

    public function test_show_404_si_no_es_propietario(): void
    {
        $owner    = $this->usuario();
        $otro     = $this->usuario();
        $proyecto = $this->crearProyecto($owner);

        $this->actingAs($otro, 'sanctum')
            ->getJson("/api/projects/{$proyecto->id_proyecto}")
            ->assertNotFound();
    }

    // ── POST /api/projects ───────────────────────────────────────────────────

    public function test_store_crea_proyecto_y_devuelve_201(): void
    {
        $usuario = $this->usuario();
        $this->tipo();

        $payload = [
            'titulo'      => 'Nuevo proyecto',
            'descripcion' => 'Descripción',
            'tipo'        => 'web',
            'es_publico'  => true,
        ];

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/projects', $payload)
            ->assertCreated()
            ->assertJsonFragment(['titulo' => 'Nuevo proyecto', 'es_publico' => true]);
    }

    public function test_store_valida_campos_requeridos(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/projects', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['titulo', 'descripcion', 'tipo', 'es_publico']);
    }

    public function test_store_valida_fecha_fin_posterior_a_inicio(): void
    {
        $usuario = $this->usuario();
        $this->tipo();

        $payload = [
            'titulo'      => 'Proyecto con fechas inválidas',
            'descripcion' => 'x',
            'tipo'        => 'web',
            'es_publico'  => false,
            'fecha_inicio' => '2025-06-01',
            'fecha_fin'    => '2025-01-01',
        ];

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/projects', $payload)
            ->assertUnprocessable();
    }

    // ── PUT /api/projects/{id} ───────────────────────────────────────────────

    public function test_update_modifica_proyecto(): void
    {
        $usuario  = $this->usuario();
        $proyecto = $this->crearProyecto($usuario);

        $this->actingAs($usuario, 'sanctum')
            ->putJson("/api/projects/{$proyecto->id_proyecto}", ['titulo' => 'Título actualizado'])
            ->assertOk()
            ->assertJsonFragment(['titulo' => 'Título actualizado']);
    }

    public function test_update_403_si_no_es_propietario(): void
    {
        $owner    = $this->usuario();
        $otro     = $this->usuario();
        $proyecto = $this->crearProyecto($owner);

        $this->actingAs($otro, 'sanctum')
            ->putJson("/api/projects/{$proyecto->id_proyecto}", ['titulo' => 'Hack'])
            ->assertNotFound();
    }

    // ── DELETE /api/projects/{id} ────────────────────────────────────────────

    public function test_destroy_elimina_proyecto(): void
    {
        $usuario  = $this->usuario();
        $proyecto = $this->crearProyecto($usuario);

        $this->actingAs($usuario, 'sanctum')
            ->deleteJson("/api/projects/{$proyecto->id_proyecto}")
            ->assertNoContent();

        $this->assertSoftDeleted('proyectos', ['id_proyecto' => $proyecto->id_proyecto]);
    }

    public function test_destroy_404_si_no_es_propietario(): void
    {
        $owner    = $this->usuario();
        $otro     = $this->usuario();
        $proyecto = $this->crearProyecto($owner);

        $this->actingAs($otro, 'sanctum')
            ->deleteJson("/api/projects/{$proyecto->id_proyecto}")
            ->assertNotFound();
    }

    // ── PATCH /api/projects/{id}/visibility ──────────────────────────────────

    public function test_update_visibility_cambia_a_privado(): void
    {
        $usuario  = $this->usuario();
        $proyecto = $this->crearProyecto($usuario);   // empieza 'publico'

        $this->actingAs($usuario, 'sanctum')
            ->patchJson("/api/projects/{$proyecto->id_proyecto}/visibility", ['es_publico' => false])
            ->assertOk()
            ->assertJsonFragment(['es_publico' => false]);
    }

    // ── POST /api/projects/{id}/image ────────────────────────────────────────

    public function test_upload_image_guarda_portada(): void
    {
        Storage::fake('public');

        $usuario  = $this->usuario();
        $proyecto = $this->crearProyecto($usuario);

        $file = UploadedFile::fake()->image('portada.jpg');

        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/projects/{$proyecto->id_proyecto}/image", ['imagen' => $file])
            ->assertOk()
            ->assertJsonStructure(['imagen_portada']);

        $this->assertDatabaseHas('proyecto_evidencias', [
            'id_proyecto' => $proyecto->id_proyecto,
            'es_portada'  => true,
        ]);
    }

    // ── DELETE /api/projects/{id}/image ─────────────────────────────────────

    public function test_delete_image_elimina_portada(): void
    {
        Storage::fake('public');

        $usuario  = $this->usuario();
        $proyecto = $this->crearProyecto($usuario);

        // Crear portada previa
        $proyecto->evidencias()->create([
            'titulo'      => 'portada.jpg',
            'tipo'        => 'imagen',
            'url'         => '/storage/projects/test/portada.jpg',
            'archivo_path'=> 'projects/test/portada.jpg',
            'es_portada'  => true,
            'es_visible'  => true,
            'orden'       => 0,
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->deleteJson("/api/projects/{$proyecto->id_proyecto}/image")
            ->assertOk()
            ->assertJsonFragment(['imagen_portada' => null]);
    }
}
