<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Mail\SendOwnerMailAction;
use App\Domain\Mail\ComposeMode;
use App\Domain\Mail\MailAddress;
use App\Domain\Mail\MailBody;
use App\Domain\Mail\MailSubject;
use App\Http\Controllers\Controller;
use App\Models\InboundMail;
use App\Models\PlatformUser;
use App\Support\Mail\OutgoingMailRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

final class MailComposeController extends Controller
{
    public function create(Request $request): Response
    {
        $source = $request->integer('responder') > 0 ? InboundMail::query()->find($request->integer('responder')) : null;
        $mode = $source === null ? ComposeMode::New : (ComposeMode::tryFrom((string) $request->query('modo')) ?? ComposeMode::Reply);
        $mode = $mode === ComposeMode::New && $source !== null ? ComposeMode::Reply : $mode;
        $recipients = $source === null ? ['to' => [], 'cc' => []] : $mode->recipients($source, OutgoingMailRecorder::domain());

        return Inertia::render('platform/mail/Compose', [
            'mode' => $mode->value,
            'modeLabel' => $mode->label(),
            'from' => (string) config('mail.owner.address'),
            'source' => $source === null ? null : [
                'id' => $source->getKey(),
                'threadId' => $source->mail_thread_id,
                'from' => $source->from_name === null ? $source->from_address : $source->from_name.' <'.$source->from_address.'>',
                'subject' => $source->subject,
                'receivedAt' => $source->received_at->toIso8601String(),
                'excerpt' => mb_substr(MailBody::textOf($source->text_body, $source->html_body), 0, 600),
                'attachments' => $mode === ComposeMode::Forward ? $source->attachments()->whereNotNull('path')->pluck('filename')->all() : [],
            ],
            'defaults' => [
                'to' => implode(', ', $recipients['to']),
                'cc' => implode(', ', $recipients['cc']),
                'subject' => $source === null ? '' : $mode->subject($source->subject),
            ],
        ]);
    }

    public function store(Request $request, SendOwnerMailAction $send): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::enum(ComposeMode::class)],
            'source' => ['nullable', 'integer', 'exists:inbound_mails,id'],
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:'.MailSubject::MAX_LENGTH],
            'body' => ['required', 'string', 'max:'.MailBody::MAX_HTML_LENGTH],
        ], attributes: ['to' => 'para', 'cc' => 'copia', 'subject' => 'asunto', 'body' => 'mensaje']);

        $to = $this->addresses('to', $data['to']);
        $cc = $this->addresses('cc', $data['cc'] ?? '');
        $source = isset($data['source']) ? InboundMail::query()->find($data['source']) : null;
        $operator = $request->user('platform');
        abort_unless($operator instanceof PlatformUser, 401);

        try {
            $sent = $send->handle($operator, ComposeMode::from($data['mode']), $source, $to, $cc, $data['subject'], $data['body']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['to' => $exception->getMessage()]);
        }

        return redirect()->route('platform.mail.inbox.show', $sent->mail_thread_id)->with('status', 'Correo enviado.');
    }

    /**
     * @return list<string>
     */
    private function addresses(string $field, string $value): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $value) ?: []), fn (string $part): bool => $part !== ''));
        foreach ($parts as $part) {
            if (MailAddress::parse($part) === null) {
                throw ValidationException::withMessages([$field => sprintf('«%s» no es un correo válido.', mb_substr($part, 0, 80))]);
            }
        }

        return MailAddress::emails($parts);
    }
}
