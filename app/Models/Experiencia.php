<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Experiencia extends Model
{
    protected $table = 'experiencias';
    protected $primaryKey = 'id_experiencia';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'tipo',
        'institucion',
        'cargo',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'es_actual',
        'es_publico',
        'fecha_modificacion',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'es_actual' => 'boolean',
        'es_publico' => 'boolean',
        'fecha_modificacion' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}