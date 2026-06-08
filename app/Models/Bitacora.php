<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Usuario;

class Bitacora extends Model
{
    protected $table = 'bitacoras';

    protected $primaryKey = 'id_bitacora';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'usuario_referencia_id',
        'accion',
        'descripcion',
        'ip_address',
        'user_agent',
        'tabla_afectada',
        'registro_afectado_id',
        'fecha',
    ];

    protected $casts = [
        'fecha' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }

    public function usuarioReferencia()
    {
        return $this->belongsTo(Usuario::class, 'usuario_referencia_id', 'id_usuario');
    }
}
