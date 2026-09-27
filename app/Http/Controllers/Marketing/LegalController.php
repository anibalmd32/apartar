<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las dos páginas legales de la plataforma: `/terminos` y `/privacidad`
 * ([05 §7.1], `REQ-IDN-005`).
 *
 * ## Están montadas, y **no están redactadas**
 *
 * Las va a enlazar el formulario de apartado de `T-045`, donde el cliente marca
 * una casilla de aceptación que se guarda con su IP como evidencia
 * (`REQ-IDN-005` RB-1, RB-2): alguien va a **aceptar** lo que diga aquí. Un
 * texto legal plausible escrito sin quien pueda redactarlo es peor que un
 * hueco visible —el hueco se rellena; el plausible se despliega—, así que lo
 * que hay es la ruta, la página y una marca de borrador que no se puede
 * confundir con el documento.
 *
 * `drafted` es esa marca en forma de dato: la página la pinta y
 * `LegalPagesTest` la afirma, así que el día que se redacte uno de los dos
 * alguien tiene que cambiarla aquí y el test se lo recuerda. `T-045` puede
 * preguntarla antes de pedir a nadie que acepte.
 *
 * Lo que sí lleva cada página es **qué tiene que cubrir**, según la spec, para
 * quien la redacte. Son referencias a requisitos, no cláusulas.
 */
final class LegalController extends Controller
{
    /**
     * Los dos documentos, con lo que la spec exige que cubran.
     *
     * @var array<string, array{title: string, drafted: bool, mustCover: list<string>}>
     */
    private const DOCUMENTS = [
        'terms' => [
            'title' => 'Términos de servicio',
            'drafted' => false,
            'mustCover' => [
                'Que el contrato de pago de un apartado es entre el cliente y el negocio, no con Apartar (REQ-IDN-005 RB-4).',
                'Qué se acepta al marcar la casilla del formulario de apartado, y que se guarda la fecha y la IP como constancia (REQ-IDN-005 RB-1, RB-2).',
                'Las condiciones de uso de la plataforma para los negocios: planes, suspensión por impago y cierre (REQ-SUB-007, REQ-ORG-008).',
            ],
        ],
        'privacy' => [
            'title' => 'Política de privacidad',
            'drafted' => false,
            'mustCover' => [
                'Qué datos del cliente final guarda cada negocio y que cada negocio solo ve los suyos (REQ-ORG-002).',
                'La IP guardada al aceptar los términos (REQ-IDN-005 RB-2).',
                'La telemetría, el banner de cookies, qué es esencial y cuánto se conserva (REQ-TLM-005).',
            ],
        ],
    ];

    /**
     * Si los dos documentos que acepta el cliente al apartar están redactados.
     *
     * Es la pregunta que hace el formulario de apartado (`T-045b`) antes de
     * enlazarlos en la casilla de consentimiento: mientras alguno diga que no
     * vale, la casilla no lo enlaza ni lo nombra. Se lee de aquí y no de una
     * copia para que redactarlos sea cambiar **una** marca.
     */
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
     * Los documentos, leídos como el tipo que declaran y no como el valor de
     * hoy: `drafted` es una marca que va a cambiar.
     *
     * @return array<string, array{title: string, drafted: bool, mustCover: list<string>}>
     */
    private static function documents(): array
    {
        return self::DOCUMENTS;
    }
}
