<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Support\SourceFiles;
use Tests\TestCase;

final class AccountBoundaryTest extends TestCase
{
    private const FORBIDDEN = 'account_id';

    public function test_no_api_resource_exposes_the_account_id(): void
    {
        $this->assertSame([], $this->offendersAmong(
            $this->phpFilesIn(app_path('Http/Resources')),
        ));
    }

    public function test_no_export_exposes_the_account_id(): void
    {
        $exports = array_filter(
            $this->phpFilesIn(app_path()),
            fn (string $file): bool => str_contains(basename($file), 'Export')
                || str_contains(basename($file), 'Sheet'),
        );

        $this->assertSame([], $this->offendersAmong(array_values($exports)));
    }

    public function test_no_resource_outside_its_directory_exposes_the_account_id(): void
    {
        $resources = array_filter(
            $this->phpFilesIn(app_path()),
            fn (string $file): bool => str_ends_with($file, 'Resource.php'),
        );

        $this->assertSame([], $this->offendersAmong(array_values($resources)));
    }

    public function test_no_frontend_type_declares_the_account_id(): void
    {
        $offenders = [];

        foreach ($this->frontendFilesIn(resource_path('js')) as $file) {
            if (str_contains((string) file_get_contents($file), self::FORBIDDEN)) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders, 'account_id declarado en el frontend: '.implode(', ', $offenders));
    }

    public function test_the_check_notices_an_exposed_account_id(): void
    {
        $exposed = $this->temporaryFile(<<<'PHP'
            <?php

            class CustomerResource
            {
                public function toArray(): array
                {
                    return ['id' => 1, 'account_id' => 7];
                }
            }
            PHP);

        $documented = $this->temporaryFile(<<<'PHP'
            <?php

            class SafeResource
            {
                                public function toArray(): array
                {
                    return ['id' => 1];
                }
            }
            PHP);

        try {
            $this->assertCount(1, $this->offendersAmong([$exposed, $documented]));
        } finally {
            unlink($exposed);
            unlink($documented);
        }
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function offendersAmong(array $files): array
    {
        $offenders = [];

        foreach ($files as $file) {
            if (str_contains($this->codeOf($file), self::FORBIDDEN)) {
                $offenders[] = $this->relative($file);
            }
        }

        return $offenders;
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
        return $this->filesIn($directory, ['php']);
    }

    /**
     * @return list<string>
     */
    private function frontendFilesIn(string $directory): array
    {
        return $this->filesIn($directory, ['ts', 'tsx']);
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesIn(string $directory, array $extensions): array
    {
        return SourceFiles::in($directory, $extensions);
    }

    private function relative(string $file): string
    {
        return str_replace(str_replace('\\', '/', base_path()).'/', '', $file);
    }

    private function temporaryFile(string $contents): string
    {
        $path = sys_get_temp_dir().'/account-boundary-'.bin2hex(random_bytes(6)).'.php';

        file_put_contents($path, $contents."\n");

        return $path;
    }
}
