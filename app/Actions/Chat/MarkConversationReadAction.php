<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\MessageSenderType;
use App\Models\Conversation;

final class MarkConversationReadAction
{
    public function handle(Conversation $conversation, MessageSenderType $reader): void
    {
        $conversation->markReadBy($reader);
    }
}
