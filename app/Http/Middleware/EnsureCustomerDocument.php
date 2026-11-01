<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerDocument
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $holder = Auth::guard('account')->check() ? Auth::guard('account')->user() : Auth::guard('customer')->user();

        if (! ($holder instanceof Account || $holder instanceof Customer) || $holder->document_number !== null) {
            return $next($request);
        }

        if (! $request->isMethod('GET')) {
            abort(Response::HTTP_CONFLICT, 'Escribe tu cédula para continuar.');
        }

        return redirect()->guest(route('portal.document'));
    }
}
