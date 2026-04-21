<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
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

    public function perfil()
    {
        return $this->hasOne(Perfil::class, 'usuario_id', 'id_usuario');
    }

    public function visibilidades()
    {
        return $this->hasMany(VisibilidadCampo::class, 'usuario_id', 'id_usuario');
    }

    public function experiencias()
    {
        return $this->hasMany(Experiencia::class, 'usuario_id', 'id_usuario');
    }

    public function cuentasOauth()
    {
        return $this->hasMany(CuentaOauth::class, 'usuario_id', 'id_usuario');
    }

    public function habilidadesUsuario()
    {
        return $this->hasMany(HabilidadUsuario::class, 'usuario_id', 'id_usuario');
    }
}