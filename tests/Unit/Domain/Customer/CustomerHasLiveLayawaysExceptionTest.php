<?php

declare(strict_types=1);

use App\Domain\Customer\CustomerHasLiveLayawaysException;

it('dice cuántos apartados en curso impiden eliminar al cliente', function (): void {
    expect((new CustomerHasLiveLayawaysException(1))->getMessage())->toContain('un apartado en curso')
        ->and((new CustomerHasLiveLayawaysException(3))->getMessage())->toContain('3 apartados en curso')
        ->and((new CustomerHasLiveLayawaysException(3))->live)->toBe(3);
});
