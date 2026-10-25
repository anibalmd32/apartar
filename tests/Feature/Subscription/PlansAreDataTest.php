<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Support\SourceFiles;
use Tests\TestCase;

final class PlansAreDataTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const PLAN_CODES = ['free', 'pro', 'business'];

    private const DECLARATION_SITE = 'app/Actions/Subscription/StartFreeSubscriptionAction.php';

    /**
     * @var array<string, string>
     */
    private const HOMONYMS = [
        "'business' => OrganizationBrand::of(" => 'El campo `business` del contrato `CatalogView` de [12] '
            .': es la identidad del negocio que publica el catálogo, no el plan Business.',
    ];

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

    public function test_the_declared_site_still_holds_the_free_plan_code(): void
    {
        $this->assertFileExists(base_path(self::DECLARATION_SITE));
        $this->assertSame('free', StartFreeSubscriptionAction::FREE_PLAN_CODE);
    }

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
        return SourceFiles::in($directory);
    }

    private function relative(string $file): string
    {
        return str_replace(str_replace('\\', '/', base_path()).'/', '', $file);
    }
}
