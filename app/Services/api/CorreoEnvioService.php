<?php

namespace App\Services\api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class CorreoEnvioService
{
    public function enviarTexto(string $toEmail, string $subject, string $body): void
    {
        if ($this->driver() === 'smtp') {
            Mail::raw($body, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)->subject($subject);
            });

            return;
        }

        $this->enviarPorSendGrid($toEmail, $subject, 'text/plain', $body);
    }

    public function enviarVista(string $toEmail, string $subject, string $view, array $data): void
    {
        $html = view($view, $data)->render();

        if ($this->driver() === 'smtp') {
            Mail::html($html, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)->subject($subject);
            });

            return;
        }

        $this->enviarPorSendGrid($toEmail, $subject, 'text/html', $html);
    }

    private function driver(): string
    {
        return strtolower((string) env('MAIL_PROVIDER', 'sendgrid'));
    }

    private function enviarPorSendGrid(string $toEmail, string $subject, string $type, string $content): void
    {
        $apiKey = (string) env('SENDGRID_API_KEY', '');
        $fromEmail = (string) env('SENDGRID_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''));
        $fromName = (string) env('SENDGRID_FROM_NAME', env('MAIL_FROM_NAME', 'Portafolio'));
        $apiUrl = (string) env('SENDGRID_API_URL', 'https://api.sendgrid.com/v3/mail/send');

        if ($apiKey === '' || $fromEmail === '') {
            throw new \RuntimeException('Falta SENDGRID_API_KEY o SENDGRID_FROM_ADDRESS en .env');
        }

        $response = Http::withToken($apiKey)->acceptJson()->post($apiUrl, [
            'personalizations' => [['to' => [['email' => $toEmail]]]],
            'from' => ['email' => $fromEmail, 'name' => $fromName],
            'subject' => $subject,
            'content' => [['type' => $type, 'value' => $content]],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('SendGrid API error '.$response->status().': '.$response->body());
        }
    }
}
