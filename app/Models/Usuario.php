<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Model
{
    use HasApiTokens;

    protected $table = 'usuarios';
    protected $primaryKey = 'id_usuario';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'nombre',
        'apellido',
        'correo',
        'password',
        'telefono',
        'rol',
        'estado',
        'intentos_fallidos',
        'fecha_bloqueo',
        'proveedor_oauth',
        'oauth_id',
        'idioma_preferido',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'fecha_bloqueo' => 'datetime',
        ];
    }
}