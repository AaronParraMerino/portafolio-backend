<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CodigoRecuperacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $codigo,
        public readonly string $correo,
        public readonly int $minutosExpiracion = 2
    ) {
    }

    public function build(): self
    {
        return $this->subject('Codigo de recuperacion de cuenta')
            ->view('emails.codigo_recuperacion')
            ->with([
                'codigo' => $this->codigo,
                'correo' => $this->correo,
                'minutosExpiracion' => $this->minutosExpiracion,
            ]);
    }
}