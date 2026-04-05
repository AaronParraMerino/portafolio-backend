<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Usuario;

class VisibilidadCampo extends Model
{
    protected $table = 'visibilidad_campos';

    protected $primaryKey = 'id_visibilidad';


    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'campo',
        'visible',
    ];


    protected $casts = [
        'visible' => 'boolean',
    ];


    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}