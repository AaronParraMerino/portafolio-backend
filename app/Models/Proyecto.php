<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proyecto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'proyectos';

    protected $primaryKey = 'id_proyecto';

    protected $fillable = [
        'titulo',
        'descripcion',
        'id_tipo_proyecto',
        'estado_publicacion',
        'estado_desarrollo',
        'fecha_inicio',
        'fecha_fin',
        'origen',
        'es_destacado',
        'publicado_at',
        'orden',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'publicado_at' => 'datetime',
        'es_destacado' => 'boolean',
    ];

    public function tipoProyecto()
    {
        return $this->belongsTo(TipoProyecto::class, 'id_tipo_proyecto', 'id_tipo_proyecto');
    }

    public function participaciones()
    {
        return $this->hasMany(Participacion::class, 'id_proyecto', 'id_proyecto');
    }

    public function github()
    {
        return $this->hasOne(ProyectoGithub::class, 'id_proyecto', 'id_proyecto');
    }

    public function evidencias()
    {
        return $this->hasMany(ProyectoEvidencia::class, 'id_proyecto', 'id_proyecto');
    }

    public function portada()
    {
        return $this->hasOne(ProyectoEvidencia::class, 'id_proyecto', 'id_proyecto')
            ->whereRaw('es_portada = true')
            ->latest('id_evidencia');
    }

    public function usosTecnologias()
    {
        return $this->hasMany(UsoTecnologia::class, 'id_proyecto', 'id_proyecto');
    }
}