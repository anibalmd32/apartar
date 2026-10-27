<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\LayawayCodeNotification;
use App\Support\FrameworkMails;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MailTemplateTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const LARAVEL_LEFTOVERS = ['Laravel', 'Regards', 'Hello!', 'Whoops', 'All rights reserved', 'having trouble clicking', 'notification-logo'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function formats(): iterable
    {
        yield 'html' => ['html'];
        yield 'texto' => ['text'];
    }

    #[DataProvider('formats')]
    public function test_every_mail_wears_the_apartar_frame_in_spanish(string $format): void
    {
        $mail = (new LayawayCodeNotification('AP-7K3M9QRT', 'Moda Valentina', 'Vestido lino', 'https://apartar.store/mi-cuenta'))->toMail(new \stdClass);
        $rendered = $this->render($mail, $format);

        $this->assertStringContainsString('Aquí tienes tu código', $rendered);
        $this->assertStringContainsString('Recibes este correo por un apartado, una cuenta o un negocio en Apartar.', $rendered);
        $this->assertStringContainsString(url('/terminos'), $rendered);
        $this->assertStringContainsString(url('/privacidad'), $rendered);

        foreach (self::LARAVEL_LEFTOVERS as $leftover) {
            $this->assertStringNotContainsString($leftover, $rendered, $leftover);
        }
    }

    public function test_the_html_has_the_brand_mark_the_button_and_the_spanish_fallback_link(): void
    {
        $html = $this->render((new MailMessage)->line('Una línea.')->action('Consultar mi apartado', 'https://apartar.store/mi-cuenta'), 'html');

        $this->assertStringContainsString('class="brand-mark"', $html);
        $this->assertStringContainsString('#5046e5', $html);
        $this->assertStringContainsString('Si el botón «Consultar mi apartado» no funciona, copia este enlace en tu navegador:', $html);
    }

    public function test_the_password_reset_mail_is_in_spanish_and_links_to_the_backoffice_form(): void
    {
        $user = User::factory()->make(['email' => 'valentina@apartar.test']);
        $mail = (new ResetPassword('token-123'))->toMail($user);

        $this->assertSame('Restablece tu contraseña de Apartar', $mail->subject);
        $this->assertSame(route('password.reset', ['token' => 'token-123', 'email' => 'valentina@apartar.test']), $mail->actionUrl);
        $this->assertStringContainsString('El enlace vale 60 minutos', $this->render($mail, 'html'));
    }

    public function test_the_email_verification_mail_is_in_spanish(): void
    {
        $mail = FrameworkMails::verifyEmail('https://apartar.store/verify-email/1/abc');
        $notification = new VerifyEmail;

        $this->assertSame('Confirma tu correo en Apartar', $mail->subject);
        $this->assertSame('Confirmar mi correo', $mail->actionText);
        $this->assertSame('Confirma tu correo en Apartar', $notification->toMail(User::factory()->make(['id' => 1]))->subject);
    }

    private function render(MailMessage $mail, string $format): string
    {
        $markdown = app(\Illuminate\Mail\Markdown::class);
        $view = $mail->markdown ?? 'notifications::email';

        return (string) ($format === 'html' ? $markdown->render($view, $mail->data()) : $markdown->renderText($view, $mail->data()));
    }
}
