<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatInvitacion extends Model
{
    use HasFactory;

    protected $table = 'chat_invitaciones';
    protected $primaryKey = 'id_chat_invitacion';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_chat',
        'id_invitador',
        'id_invitado',
        'estado',
        'expires_at',
        'respondida_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'respondida_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'id_chat', 'id_chat');
    }

    public function invitador()
    {
        return $this->belongsTo(Usuario::class, 'id_invitador', 'id_usuario');
    }

    public function invitado()
    {
        return $this->belongsTo(Usuario::class, 'id_invitado', 'id_usuario');
    }
}
