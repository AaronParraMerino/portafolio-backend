<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatParticipante extends Model
{
    use HasFactory;

    protected $table = 'chat_participantes';
    protected $primaryKey = 'id_chat_participante';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_chat',
        'id_usuario',
        'rol',
        'estado',
        'archivado',
        'bloqueo_saliente',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'archivado' => 'boolean',
        'bloqueo_saliente' => 'boolean',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'id_chat', 'id_chat');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
