<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

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

    public function enlaces()
    {
        return $this->hasMany(Enlace::class, 'id_usuario', 'id_usuario');
    }

    public function personalizacionPortafolio()
    {
        return $this->hasOne(PersonalizacionPortafolio::class, 'usuario_id', 'id_usuario');
    }

    public function eventosPersonales()
    {
        return $this->hasMany(EventoPersonal::class, 'usuario_id', 'id_usuario');
    }

    public function eventosPublicados()
    {
        return $this->hasMany(AdminEvento::class, 'usuario_creador_id', 'id_usuario');
    }

    public function solicitudesPublicante()
    {
        return $this->hasMany(SolicitudPublicante::class, 'usuario_id', 'id_usuario');
    }

// notificaciones relacionadas al usuario, tanto generadas por el usuario como recibidas
    public function notificacionUsuarios()
    {
        return $this->hasMany(
            NotificacionUsuario::class,
            'id_usuario',
            'id_usuario'
        );
    }

    public function notificaciones()
    {
        return $this->belongsToMany(
            Notificacion::class,
            'notificacion_usuario',
            'id_usuario',
            'id_notificacion',
            'id_usuario',
            'id_notificacion'
        )
        ->withPivot([
            'id_notificacion_usuario',
            'leido_en',
            'created_at',
            'updated_at',
        ]);
    }
    //
}
