<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenRecuperacion extends Model
{
    protected $table = 'token_recuperaciones';
    protected $primaryKey = 'id_tokenR';

    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'token_hash',
        'estado',
        'fecha_expiracion',
        'fecha_creacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_expiracion' => 'datetime',
            'fecha_creacion' => 'datetime',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}