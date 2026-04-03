<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enlaces_personales', function (Blueprint $table) {
            $table->id('id_enlaceP');

            $table->foreignId('usuario_id')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->enum('tipo', ['github', 'linkedin', 'portfolio']);
            $table->string('url');
            $table->string('etiqueta_custom')->nullable();
            $table->boolean('visible')->default(true);

            $table->timestamp('fecha_modificacion')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enlaces_personales');
    }
};
