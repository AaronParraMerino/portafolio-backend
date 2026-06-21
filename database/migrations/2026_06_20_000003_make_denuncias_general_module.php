<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('denuncias', function (Blueprint $table) {
            if (! Schema::hasColumn('denuncias', 'asunto')) {
                $table->string('asunto', 180)->default('Asunto denunciado')->after('id_denunciante');
            }
        });

        DB::statement('ALTER TABLE denuncias DROP CONSTRAINT IF EXISTS denuncias_tipo_check');

        Schema::table('denuncias', function (Blueprint $table) {
            if (Schema::hasColumn('denuncias', 'id_denunciado')) {
                $table->dropForeign(['id_denunciado']);
            }

            if (Schema::hasColumn('denuncias', 'id_chat')) {
                $table->dropForeign(['id_chat']);
            }

            if (Schema::hasColumn('denuncias', 'id_chat_mensaje')) {
                $table->dropForeign(['id_chat_mensaje']);
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('denuncias', 'id_denunciado') ? 'id_denunciado' : null,
                Schema::hasColumn('denuncias', 'id_chat') ? 'id_chat' : null,
                Schema::hasColumn('denuncias', 'id_chat_mensaje') ? 'id_chat_mensaje' : null,
                Schema::hasColumn('denuncias', 'tipo') ? 'tipo' : null,
                Schema::hasColumn('denuncias', 'nombre_objeto_denunciado') ? 'nombre_objeto_denunciado' : null,
            ]));

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('denuncias', function (Blueprint $table) {
            if (! Schema::hasColumn('denuncias', 'tipo')) {
                $table->string('tipo', 50)->default('otro')->after('id_denunciante');
            }

            if (! Schema::hasColumn('denuncias', 'nombre_objeto_denunciado')) {
                $table->string('nombre_objeto_denunciado', 180)->default('Asunto denunciado')->after('tipo');
            }

            if (! Schema::hasColumn('denuncias', 'id_denunciado')) {
                $table->foreignId('id_denunciado')
                    ->nullable()
                    ->after('id_denunciante')
                    ->constrained('usuarios', 'id_usuario')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('denuncias', 'id_chat')) {
                $table->foreignId('id_chat')
                    ->nullable()
                    ->after('id_denunciado')
                    ->constrained('chats', 'id_chat')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('denuncias', 'id_chat_mensaje')) {
                $table->foreignId('id_chat_mensaje')
                    ->nullable()
                    ->after('id_chat')
                    ->constrained('chat_mensajes', 'id_chat_mensaje')
                    ->nullOnDelete();
            }
        });

        Schema::table('denuncias', function (Blueprint $table) {
            if (Schema::hasColumn('denuncias', 'asunto')) {
                $table->dropColumn('asunto');
            }
        });
    }
};
