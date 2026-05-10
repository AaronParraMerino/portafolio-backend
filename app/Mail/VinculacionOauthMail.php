<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VinculacionOauthMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $codigo,
        public readonly string $correo,
        public readonly string $provider,
        public readonly int $minutosExpiracion = 10
    ) {}

    public function build(): self
    {
        return $this->subject('Confirma la vinculación de tu cuenta')
            ->view('emails.vinculacion_oauth')
            ->with([
                'codigo'             => $this->codigo,
                'correo'             => $this->correo,
                'provider'           => $this->provider,
                'minutosExpiracion'  => $this->minutosExpiracion,
            ]);
    }
}
