<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\CompleteAccountDocumentAction;
use App\Models\Account;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CompleteAccountDocumentActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_it_writes_the_document_once_and_never_overwrites_it(): void
    {
        $account = Account::factory()->create(['document_number' => null]);

        $first = app(CompleteAccountDocumentAction::class)->handle($account, DocumentNumber::fromInput('V-20.111.222'));
        app(CompleteAccountDocumentAction::class)->handle($account, DocumentNumber::fromInput('V30111222'));

        $this->assertSame('V20111222', $first->document_number);
        $this->assertSame('V20111222', DB::table('accounts')->where('id', $account->getKey())->value('document_number'));
    }

    public function test_two_accounts_of_the_same_person_may_share_the_document(): void
    {
        [$one, $two] = [Account::factory()->create(['document_number' => null]), Account::factory()->create(['document_number' => null])];

        app(CompleteAccountDocumentAction::class)->handle($one, DocumentNumber::fromInput('V20111222'));
        app(CompleteAccountDocumentAction::class)->handle($two, DocumentNumber::fromInput('V20111222'));

        $this->assertSame(2, DB::table('accounts')->where('document_number', 'V20111222')->count());
    }
}
