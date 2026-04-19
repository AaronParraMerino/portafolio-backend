<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use SendGrid;
use SendGrid\Mail\Mail as SendGridMail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('sendgrid', function () {
            return new class(config('services.sendgrid.api_key')) extends AbstractTransport {
                public function __construct(private string $apiKey)
                {
                    parent::__construct();
                }

                protected function doSend(SentMessage $message): void
                {
                    $email = MessageConverter::toEmail($message->getOriginalMessage());

                    $sendgridMail = new SendGridMail();
                    $sendgridMail->setFrom(
                        $email->getFrom()[0]->getAddress(),
                        $email->getFrom()[0]->getName()
                    );
                    $sendgridMail->setSubject($email->getSubject());

                    foreach ($email->getTo() as $address) {
                        $sendgridMail->addTo($address->getAddress(), $address->getName());
                    }

                    $sendgridMail->addContent('text/html', $email->getHtmlBody() ?? $email->getTextBody());

                    if ($email->getTextBody()) {
                        $sendgridMail->addContent('text/plain', $email->getTextBody());
                    }

                    $sendgrid = new SendGrid($this->apiKey);
                    $response = $sendgrid->send($sendgridMail);

                    if ($response->statusCode() >= 400) {
                        throw new \RuntimeException(
                            'SendGrid API error ' . $response->statusCode() . ': ' . $response->body()
                        );
                    }
                }

                public function __toString(): string
                {
                    return 'sendgrid';
                }
            };
        });
    }
}
