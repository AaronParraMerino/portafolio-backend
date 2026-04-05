<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Usuario;

class Perfil extends Model
{
    protected $table = 'perfiles';

    protected $primaryKey = 'id_perfil';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'biografia',
        'ciudad',
        'pais',
        'profesion',
        'foto_perfil',
        'foto_fondo',
        'es_publico',
        'fecha_modificacion',
    ];

    protected $casts = [
        'es_publico' => 'boolean',
        'fecha_modificacion' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}