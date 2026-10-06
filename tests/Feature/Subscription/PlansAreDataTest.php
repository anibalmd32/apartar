<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Support\SourceFiles;
use Tests\TestCase;

/**
 * `REQ-SUB-001` RB-1 · los planes son **datos, no código**.
 *
 * La regla suena obvia y se incumple sola: basta un `if ($plan->code ===
 * 'free')` en una pantalla para que la matriz deje de ser ajustable sin
 * desplegar y haya dos fuentes de verdad —la fila y el `if`—, que divergen en
 * cuanto alguien sube un tope.
 *
 * Esto la comprueba por búsqueda, con los comentarios descontados: explicar
 * por escrito por qué no se compara contra `'free'` no es compararlo.
 *
 * Hoy pasa sobre un conjunto casi vacío —hay una sola aparición legítima— y
 * crece solo: el día que alguien escriba la comparación, falla sin que nadie
 * tenga que acordarse de mirar.
 */
final class PlansAreDataTest extends TestCase
{
    /**
     * Códigos de la matriz aprobada.
     *
     * @var list<string>
     */
    private const PLAN_CODES = ['free', 'pro', 'business'];

    /**
     * La única aparición legítima: resolver **el plan con el que empieza todo
     * el mundo** por su identificador estable.
     *
     * `REQ-SUB-002` RB-2 obliga a que el alta sea siempre en Free, así que
     * alguien tiene que nombrarlo. Lo que la regla prohíbe es decidir un
     * **límite** comparando el código, y esa acción no mira ni uno.
     */
    private const DECLARATION_SITE = 'app/Actions/Subscription/StartFreeSubscriptionAction.php';

    /**
     * Homónimos: la misma palabra que un código de plan, y ninguna comparación.
     *
     * Se descuenta **la forma exacta**, no el archivo ni la palabra: excluir
     * `'business' =>` a secas dejaría pasar un `match` sobre el código del
     * plan, que es exactamente la comparación que esta regla busca. Cada
     * entrada lleva su motivo.
     *
     * @var array<string, string>
     */
    private const HOMONYMS = [
        "'business' => OrganizationBrand::of(" => 'El campo `business` del contrato `CatalogView` de [12] '
            .'(REQ-CAT-021): es la identidad del negocio que publica el catálogo, no el plan Business.',
    ];

    /**
     * Los homónimos no abren un agujero: la exclusión es de una forma exacta,
     * así que un `match` sobre el código con esa misma palabra sigue contando
     * como comparación.
     */
    public function test_a_homonym_does_not_hide_a_real_comparison(): void
    {
        $comparison = "match (\$plan->code) { 'business' => 10 };";

        $this->assertSame(1, preg_match("/(['\"])business\\1/", str_replace(array_keys(self::HOMONYMS), '', $comparison)));

        foreach (self::HOMONYMS as $form => $reason) {
            $this->assertGreaterThan(60, mb_strlen($reason), "El homónimo «{$form}» no explica por qué lo es.");
        }
    }

    public function test_no_application_code_compares_against_a_plan_code(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $relative = $this->relative($file);

            if ($relative === self::DECLARATION_SITE) {
                continue;
            }

            $code = str_replace(array_keys(self::HOMONYMS), '', $this->codeOf($file));

            foreach (self::PLAN_CODES as $planCode) {
                if (preg_match('/([\'"])'.$planCode.'\1/', $code) === 1) {
                    $offenders[] = $relative.' menciona el código de plan «'.$planCode.'»';
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Y tampoco se compara la columna, la escriba como la escriba.
     */
    public function test_no_application_code_branches_on_the_plan_column(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $code = $this->codeOf($file);

            if (preg_match('/->\s*code\s*(===|==|!==|!=)/', $code) === 1) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * La aparición declarada existe de verdad: si alguien renombra la
     * constante o mueve la acción, este archivo deja de proteger lo que cree
     * proteger.
     */
    public function test_the_declared_site_still_holds_the_free_plan_code(): void
    {
        $this->assertFileExists(base_path(self::DECLARATION_SITE));
        $this->assertSame('free', StartFreeSubscriptionAction::FREE_PLAN_CODE);
    }

    /**
     * Código de un archivo sin sus comentarios.
     */
    private function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : $token[1];
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        // Por `SourceFiles`: un iterador de SPL pierde archivos en el montaje
        // de desarrollo (`T-058b`).
        return SourceFiles::in($directory);
    }

    private function relative(string $file): string
    {
        return str_replace(str_replace('\\', '/', base_path()).'/', '', $file);
    }
}
