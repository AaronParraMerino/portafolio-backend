<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalizacionPortafolio extends Model
{
    protected $table = 'personalizaciones_portafolio';
    protected $primaryKey = 'id_personalizacion';

    protected $fillable = [
        'usuario_id',
        'hero_color',
        'hero_bg_source',
        'hero_pattern',
        'avatar_bg_source',
        'avatar_color',
        'accent_color',
        'card_bg',
        'text_color_auto',
        'text_color',
        'font_id',
        'frame_id',
        'disponible',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
    }
}
