<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

final class CompleteAccountDocumentAction
{
    public function handle(Account $account, DocumentNumber $document): Account
    {
        return DB::transaction(function () use ($account, $document): Account {
            /** @var Account $locked */
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->document_number === null) {
                $locked->forceFill(['document_number' => $document->value])->save();
            }

            return $locked;
        });
    }
}
