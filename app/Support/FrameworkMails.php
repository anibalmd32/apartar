<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

final class FrameworkMails
{
    public static function register(): void
    {
        ResetPassword::toMailUsing(fn (object $notifiable, string $token): MailMessage => self::resetPassword($notifiable, $token));
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => self::verifyEmail($url));
    }

    public static function resetPassword(object $notifiable, string $token): MailMessage
    {
        $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : null;
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Restablece tu contraseña de Apartar')
            ->greeting('Restablece tu contraseña')
            ->line('Pediste cambiar la contraseña de tu cuenta del back office de Apartar.')
            ->action('Elegir una contraseña nueva', url(route('password.reset', ['token' => $token, 'email' => $email], false)))
            ->line(sprintf('El enlace vale %d minutos y sirve una sola vez.', $minutes))
            ->line('Si no lo pediste, ignora este correo: tu contraseña sigue siendo la misma.');
    }

    public static function verifyEmail(string $url): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirma tu correo en Apartar')
            ->greeting('Confirma tu correo')
            ->line('Pulsa el botón para confirmar que este correo es tuyo.')
            ->action('Confirmar mi correo', $url)
            ->line('Si no creaste una cuenta en Apartar, ignora este correo.');
    }
}
