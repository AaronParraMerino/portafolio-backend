<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatLectura extends Model
{
    use HasFactory;

    protected $table = 'chat_lecturas';
    protected $primaryKey = 'id_chat_lectura';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_chat',
        'id_usuario',
        'ultimo_mensaje_leido_id',
        'leido_at',
    ];

    protected $casts = [
        'leido_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'id_chat', 'id_chat');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function ultimoMensajeLeido()
    {
        return $this->belongsTo(ChatMensaje::class, 'ultimo_mensaje_leido_id', 'id_chat_mensaje');
    }
}
