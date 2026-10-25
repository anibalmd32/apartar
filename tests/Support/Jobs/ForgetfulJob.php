<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Models\User;

final class ForgetfulJob
{
    public function handle(): void
    {
        User::query()->count();
    }
}
