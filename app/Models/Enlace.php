<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enlace extends Model
{
    protected $table = 'enlaces';
    protected $primaryKey = 'id_enlace';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_usuario',
        'nombre',
        'link',
        'descripcion',
        'es_visible',
    ];

    protected function casts(): array
    {
        return [
            'es_visible' => 'boolean',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}