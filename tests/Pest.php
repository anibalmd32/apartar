<?php

declare(strict_types=1);

pest()->extend(Tests\TestCase::class)
    ->use(Tests\Concerns\ReusesMigratedDatabase::class)
    ->in('Feature');
