<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TraduccionContenido extends Model
{
    protected $table = 'traducciones_contenido';

    protected $primaryKey = 'id_traduccion';

    protected $fillable = [
        'usuario_id',
        'entidad_tipo',
        'entidad_id',
        'campo',
        'idioma',
        'texto_traducido',
        'origen',
        'estado',
    ];
}