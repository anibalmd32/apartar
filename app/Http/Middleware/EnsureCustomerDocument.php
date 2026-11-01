<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
        if (Auth::guard('account')->check()) {
            return $next($request);
        }

        $customer = Auth::guard('customer')->user();

        if ($customer instanceof Customer && $customer->document_number === null) {
            if (! $request->isMethod('GET')) {
                abort(Response::HTTP_CONFLICT, 'Escribe tu cédula para continuar.');
            }

            return redirect()->guest(route('portal.document'));
        }

        return $next($request);
    }
}
