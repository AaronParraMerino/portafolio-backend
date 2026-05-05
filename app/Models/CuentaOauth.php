<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaOauth extends Model
{
    protected $table = 'cuentas_oauth';
    protected $primaryKey = 'id_cuenta_oauth';

    protected $fillable = [
        'usuario_id',
        'provider',
        'provider_user_id',
        'email',
        'nombre',
        'foto_url',
        'access_token',
        'refresh_token',
        'token_scopes',
        'token_expires_at',
        'token_updated_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'token_updated_at' => 'datetime',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}