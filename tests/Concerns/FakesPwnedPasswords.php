<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

trait FakesPwnedPasswords
{
    /**
     * @var array<string, string>
     */
    private array $pwnedRanges = [];

    protected function fakeUncompromisedPasswordService(): void
    {
        Http::fake([
            'api.pwnedpasswords.com/range/*' => function (Request $request) {
                $prefix = strtoupper(basename(parse_url($request->url(), PHP_URL_PATH) ?: ''));

                return Http::response($this->pwnedRanges[$prefix] ?? '');
            },
        ]);
    }

    protected function fakePwnedPassword(string $password, int $appearances = 42): void
    {
        [$prefix, $suffix] = $this->pwnedHashParts($password);

        $this->pwnedRanges[$prefix] = $suffix.':'.$appearances;
    }

    protected function assertPwnedServiceWasAsked(string $password): void
    {
        [$prefix] = $this->pwnedHashParts($password);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/range/'.$prefix));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pwnedHashParts(string $password): array
    {
        $hash = strtoupper(sha1($password));

        return [substr($hash, 0, 5), substr($hash, 5)];
    }
}
