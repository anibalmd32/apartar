<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class LegalController extends Controller
{
    /**
     * @var array<string, array{title: string, drafted: bool, mustCover: list<string>}>
     */
    private const DOCUMENTS = [
        'terms' => [
            'title' => 'Términos de servicio',
            'drafted' => false,
            'mustCover' => [
                'Que el contrato de pago de un apartado es entre el cliente y el negocio, no con Apartar.',
                'Qué se acepta al marcar la casilla del formulario de apartado, y que se guarda la fecha y la IP como constancia.',
                'Las condiciones de uso de la plataforma para los negocios: planes, suspensión por impago y cierre.',
            ],
        ],
        'privacy' => [
            'title' => 'Política de privacidad',
            'drafted' => false,
            'mustCover' => [
                'Qué datos del cliente final guarda cada negocio y que cada negocio solo ve los suyos.',
                'La IP guardada al aceptar los términos.',
                'La telemetría, el banner de cookies, qué es esencial y cuánto se conserva.',
            ],
        ],
    ];

    public static function platformTermsAreDrafted(): bool
    {
        foreach (self::documents() as $document) {
            if (! $document['drafted']) {
                return false;
            }
        }

        return true;
    }

    public function terms(): Response
    {
        return $this->render('terms');
    }

    public function privacy(): Response
    {
        return $this->render('privacy');
    }

    private function render(string $document): Response
    {
        return Inertia::render('marketing/Legal', ['document' => ['key' => $document] + self::documents()[$document]]);
    }

    /**
     * @return array<string, array{title: string, drafted: bool, mustCover: list<string>}>
     */
    private static function documents(): array
    {
        return self::DOCUMENTS;
    }
}
