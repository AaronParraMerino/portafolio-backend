<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Usuario;

class Perfil extends Model
{
    protected $table = 'perfiles';

    protected $primaryKey = 'id_perfil';


    protected $fillable = [
        'usuario_id',
        'biografia',
        'ciudad',
        'pais',
        'profesion',
        'foto_perfil',
        'foto_fondo',
        'es_publico',
    ];

    protected $casts = [
        'es_publico' => 'boolean',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}