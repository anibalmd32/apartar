<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\Mail\MailStatus;
use App\Http\Controllers\Controller;
use App\Models\SentMail;
use App\Queries\Platform\MailFilters;
use App\Queries\Platform\SentMailList;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SentMailController extends Controller
{
    public function index(Request $request, SentMailList $list): Response
    {
        $filters = MailFilters::fromRequest($request);
        $page = $list->list($filters);

        return Inertia::render('platform/mail/Sent', [
            'mails' => $page['rows'],
            'hasMore' => $page['hasMore'],
            'page' => $filters->page,
            'filters' => $filters->toArray(),
            'statuses' => collect(MailStatus::labels())->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'categories' => $list->categories(),
        ]);
    }

    public function show(SentMail $sentMail, SentMailList $list): Response
    {
        return Inertia::render('platform/mail/SentShow', ['mail' => $list->detail($sentMail)]);
    }
}
