<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Models\User;

/**
 * Job que toca una tabla del tenant **sin** usar el trait.
 *
 * Es el descuido que SP-3 contempla, y existe para demostrar que el sistema no
 * depende de que nadie se acuerde: sin tenant fijado, el global scope lanza y
 * el job se cae. Es preferible un job caído a una fuga.
 */
final class ForgetfulJob
{
    public function handle(): void
    {
        User::query()->count();
    }
}
