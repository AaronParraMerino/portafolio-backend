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
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}