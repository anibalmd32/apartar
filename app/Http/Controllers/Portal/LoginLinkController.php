<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Customer\ConsumeLoginTokenAction;
use App\Actions\Customer\IssueLoginLinkAction;
use App\Domain\Customer\UnusableLoginLinkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\RequestLoginLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class LoginLinkController extends Controller
{
    public function store(RequestLoginLinkRequest $request, IssueLoginLinkAction $issue): RedirectResponse
    {
        $request->issue($issue);

        return redirect()->route('portal.access')->with('status', RequestLoginLinkRequest::SENT_MESSAGE);
    }

    public function show(string $token, ConsumeLoginTokenAction $consume): Response
    {
        return Inertia::render('portal/LoginLink', [
            'token' => $token,
            'preview' => $consume->preview($token),
        ]);
    }

    public function consume(Request $request, string $token, ConsumeLoginTokenAction $consume): RedirectResponse|HttpResponse
    {
        try {
            $consume->handle($token, $request->boolean('remember', true));
        } catch (UnusableLoginLinkException $unusable) {
            Log::warning('portal.login_link.unusable', ['ip' => $request->ip(), 'reason' => $unusable->reason]);

            return Inertia::render('portal/LoginLinkUnusable', ['message' => $unusable->getMessage()])
                ->toResponse($request)
                ->setStatusCode(HttpResponse::HTTP_NOT_FOUND);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }
}
