<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use RuntimeException;

final class CustomerHasLiveLayawaysException extends RuntimeException
{
    public function __construct(public readonly int $live)
    {
        parent::__construct($live === 1
            ? 'Este cliente tiene un apartado en curso. Cancélalo o espera a que termine para poder eliminarlo.'
            : sprintf('Este cliente tiene %d apartados en curso. Cancélalos o espera a que terminen para poder eliminarlo.', $live));
    }
}
