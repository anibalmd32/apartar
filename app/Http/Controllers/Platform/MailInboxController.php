<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\InboundMailAttachment;
use App\Models\MailThread;
use App\Queries\Platform\MailInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MailInboxController extends Controller
{
    public function index(Request $request, MailInbox $inbox): Response
    {
        $unreadOnly = $request->query('filtro') === 'no-leidos';
        $search = $request->query('q');
        $search = is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 100) : null;
        $page = max(1, min(1000, $request->integer('pagina', 1)));
        $threads = $inbox->threads($unreadOnly, $search, $page);

        return Inertia::render('platform/mail/Inbox', [
            'threads' => $threads['rows'],
            'hasMore' => $threads['hasMore'],
            'page' => $page,
            'filters' => ['filtro' => $unreadOnly ? 'no-leidos' : null, 'q' => $search],
            'unreadThreads' => MailThread::query()->where('unread_count', '>', 0)->count(),
        ]);
    }

    public function show(MailThread $thread, MailInbox $inbox): Response
    {
        $messages = $inbox->conversation($thread);

        if ($thread->unread_count > 0) {
            $thread->inboundMails()->whereNull('read_at')->update(['read_at' => now()]);
            $thread->refreshCounters();
        }

        return Inertia::render('platform/mail/Thread', [
            'thread' => ['id' => $thread->getKey(), 'subject' => $thread->subject, 'counterpart' => $thread->counterpart],
            'messages' => $messages,
        ]);
    }

    public function unread(MailThread $thread): RedirectResponse
    {
        $latest = $thread->inboundMails()->latest('received_at')->latest('id')->first();

        if ($latest !== null) {
            $latest->forceFill(['read_at' => null])->save();
            $thread->refreshCounters();
        }

        return redirect()->route('platform.mail.inbox.index')->with('status', 'Conversación marcada como no leída.');
    }

    public function attachment(InboundMailAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk(InboundMailAttachment::DISK);

        abort_unless($attachment->path !== null && $disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
