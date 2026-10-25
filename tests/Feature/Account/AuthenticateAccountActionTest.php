<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\AuthenticateAccountAction;
use App\Actions\Account\GoogleUserData;
use App\Models\Account;
use App\Models\AccountIdentity;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthenticateAccountActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_account_and_opens_the_session(): void
    {
        $account = $this->authenticate('sub-1', 'maria@gmail.test');

        $this->assertTrue($account->exists);
        $this->assertSame('sub-1', AccountIdentity::query()->sole()->provider_id);
        $this->assertAuthenticatedAs($account, 'account');
    }

    public function test_it_refreshes_the_same_account_by_provider_id(): void
    {
        $first = $this->authenticate('sub-1', 'maria@gmail.test', 'María');
        $second = $this->authenticate('sub-1', 'otra@gmail.test', 'María P.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('María P.', $second->display_name);
        $this->assertSame('otra@gmail.test', $second->email);
        $this->assertSame(1, Account::query()->count());
    }

    public function test_a_lost_insertion_race_is_retried_without_orphans(): void
    {
        $raced = false;
        AccountIdentity::creating(function () use (&$raced): void {
            if (! $raced) {
                $raced = true;

                throw new UniqueConstraintViolationException('mysql', 'insert into account_identities', [], new Exception('Duplicate entry'));
            }
        });

        $account = $this->authenticate('sub-1', 'maria@gmail.test');

        $this->assertTrue($raced);
        $this->assertSame(1, Account::query()->count());
        $this->assertSame($account->id, AccountIdentity::query()->sole()->account_id);
        $this->assertAuthenticatedAs($account, 'account');
    }

    private function authenticate(string $sub, string $email, string $name = 'María Pérez'): Account
    {
        return app(AuthenticateAccountAction::class)->handle(new GoogleUserData($sub, $email, $name, null));
    }
}
