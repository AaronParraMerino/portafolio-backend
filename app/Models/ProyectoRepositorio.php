<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProyectoRepositorio extends Model
{
    use SoftDeletes;

    protected $table = 'proyecto_repositorios';
    protected $primaryKey = 'id_proyecto_repositorio';

    protected $fillable = [
        'id_proyecto',
        'nombre',
        'tipo',
        'proveedor',
        'url_repositorio',
        'descripcion',
    ];

    public function github()
    {
        return $this->hasOne(RepositorioGithub::class, 'id_proyecto_repositorio', 'id_proyecto_repositorio');
    }
}
