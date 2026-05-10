<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioRepositorioValidacion extends Model
{
    protected $table = 'usuario_repositorio_validaciones';
    protected $primaryKey = 'id_usuario_repositorio_validacion';

    protected $fillable = [
        'id_usuario',
        'id_repositorio_github',
        'id_cuenta_oauth',
        'relacion_github',
        'es_propietario',
        'validado',
        'validado_at',
        'ultima_verificacion_at',
        'permisos_github',
        'detalle_validacion',
    ];

    protected function casts(): array
    {
        return [
            'es_propietario' => 'boolean',
            'validado' => 'boolean',
            'validado_at' => 'datetime',
            'ultima_verificacion_at' => 'datetime',
            'permisos_github' => 'array',
        ];
    }
}
