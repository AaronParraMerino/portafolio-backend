<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personalizaciones_portafolio', function (Blueprint $table) {
            $table->id('id_personalizacion');

            $table->foreignId('usuario_id')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->string('hero_color', 20)->default('#0c1a2e');
            $table->enum('hero_bg_source', ['foto', 'custom'])->default('custom');
            $table->enum('hero_pattern', ['dots', 'grid', 'hex', 'none'])->default('dots');
            $table->enum('avatar_bg_source', ['foto', 'custom'])->default('foto');
            $table->string('avatar_color', 20)->default('#0c1a2e');
            $table->string('accent_color', 20)->default('#0077b7');
            $table->string('card_bg', 20)->default('#ffffff');
            $table->boolean('text_color_auto')->default(true);
            $table->string('text_color', 20)->default('#111827');
            $table->enum('font_id', ['inter', 'mono', 'georgia', 'system'])->default('inter');
            $table->enum('frame_id', ['thick', 'mac', 'linux', 'windows', 'none'])->default('mac');
            $table->boolean('disponible')->default(false);

            $table->timestamps();

            $table->unique('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalizaciones_portafolio');
    }
};
