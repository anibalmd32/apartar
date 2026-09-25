<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La portada de la plataforma ([05 §7.1]).
 *
 * Le habla a alguien que **todavía no es cliente**: qué es Apartar, cuánto
 * cuesta empezar y por dónde se entra. No consulta nada —ni planes ni
 * negocios—: las cifras están en `/precios`, que es la única página que las
 * lee de `plans` (`REQ-SUB-001` RB-8), y repetirlas aquí sería una segunda
 * copia de la matriz.
 *
 * Fuera del modelo de tenancy: no hay organización de la que hablar.
 */
final class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('marketing/Home');
    }
}
