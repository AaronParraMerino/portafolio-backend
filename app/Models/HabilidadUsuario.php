<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HabilidadUsuario extends Model
{
    protected $table = 'habilidades_usuario';
    protected $primaryKey = 'id_habilidad_usuario';

    protected $fillable = [
        'usuario_id',
        'habilidad_id',
        'nivel',
        'es_visible',
        'fecha_modificacion',
    ];

    protected $casts = [
        'es_visible' => 'boolean',
        'fecha_modificacion' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }

    public function habilidad()
    {
        return $this->belongsTo(Habilidad::class, 'habilidad_id', 'id_habilidad');
    }
}