<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Models\User;
use App\Notifications\ExportFinishedNotification;
use Tests\TestCase;

/**
 * El aviso de que la exportación terminó (`REQ-BOF-043` RB-6, SP-2,
 * `T-070`): por correo, en la cola ordinaria, con el enlace a la pantalla de
 * exportaciones —que firma uno nuevo cada vez— y no al archivo.
 */
final class ExportFinishedNotificationTest extends TestCase
{
    public function test_ready_and_failed_say_what_happened_and_link_to_the_exports(): void
    {
        $ready = (new ExportFinishedNotification(ExportType::Customers, ExportStatus::Completed, 'https://apartar.test/backoffice/exportaciones'))->toMail(new User);
        $failed = (new ExportFinishedNotification(ExportType::Inventory, ExportStatus::Failed, 'https://apartar.test/backoffice/exportaciones'))->toMail(new User);

        $this->assertSame('Tu exportación de clientes está lista', $ready->subject);
        $this->assertSame('https://apartar.test/backoffice/exportaciones', $ready->actionUrl);
        $this->assertStringContainsString('siete días', implode(' ', $ready->introLines));
        $this->assertSame('No pudimos generar tu exportación de inventario', $failed->subject);
        $this->assertSame('Ver exportaciones', $failed->actionText);
    }

    public function test_it_goes_by_mail_on_the_default_queue(): void
    {
        $notification = new ExportFinishedNotification(ExportType::Collections, ExportStatus::Completed, 'https://apartar.test');

        $this->assertSame(['mail'], $notification->via(new User));
        $this->assertSame('default', $notification->queue);
    }
}
