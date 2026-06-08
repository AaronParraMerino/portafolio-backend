<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminUsuarioPlantilla extends Model
{
    protected $table = 'admin_usuario_plantillas';
    protected $primaryKey = 'id_plantilla';

    protected $fillable = [
        'usuario_creador_id',
        'usuario_actualizador_id',
        'titulo',
        'cuerpo',
        'tipo',
        'urgencia',
        'canales',
        'usadas',
    ];

    protected function casts(): array
    {
        return [
            'canales' => 'array',
            'usadas' => 'integer',
        ];
    }
}
