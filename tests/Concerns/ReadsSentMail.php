<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Email;

trait ReadsSentMail
{
    /**
     * @return list<Email>
     */
    protected function sentEmails(): array
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $emails = [];

        foreach ($transport->messages() as $message) {
            $original = $message->getOriginalMessage();

            if ($original instanceof Email) {
                $emails[] = $original;
            }
        }

        return $emails;
    }

    protected function lastEmail(): Email
    {
        $emails = $this->sentEmails();
        $this->assertNotSame([], $emails, 'No salió ningún correo.');

        return $emails[array_key_last($emails)];
    }
}
