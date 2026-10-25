<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Notification;

use App\Actions\Notification\ManualPushData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ManualPushDataTest extends TestCase
{
    public function test_exactly_one_mode(): void
    {
        $this->assertSame([1, 2], (new ManualPushData('Hola', 'Texto', null, [1, 2], null))->customerIds);
        $this->assertSame(['preset' => 'due_today'], (new ManualPushData('Hola', 'Texto', null, null, ['preset' => 'due_today']))->segment);

        foreach ([[[1], ['preset' => 'due_today']], [null, null]] as [$customers, $segment]) {
            try {
                new ManualPushData('Hola', 'Texto', null, $customers, $segment);
                $this->fail('Aceptó dos modos o ninguno.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_text_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ManualPushData(str_repeat('a', ManualPushData::TITLE_MAX + 1), 'Texto', null, [1], null);
    }
}
