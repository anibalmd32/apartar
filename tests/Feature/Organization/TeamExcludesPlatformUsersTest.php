<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TeamExcludesPlatformUsersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function teamPermissionFiles(): array
    {
        $files = [
            'app/Policies/UserPolicy.php',
            'app/Policies/OrganizationPolicy.php',
            'app/Policies/SubscriptionPolicy.php',
            'app/Http/Middleware/EnsureIsOwner.php',
            'app/Domain/Tenancy/OrganizationRole.php',
            'app/Domain/Tenancy/OrganizationCapability.php',
            'app/Domain/Tenancy/LastOwnerGuard.php',
        ];

        return array_combine($files, array_map(fn (string $file): array => [$file], $files));
    }

    public function test_a_team_query_does_not_reach_platform_operators(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->seedPlatformUser('operadora@plataforma.test');

        $member = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->actingAsOrganization($organization);

        $team = User::query()->get();

        $this->assertCount(1, $team);
        $this->assertTrue($team->first()?->is($member));
    }

    public function test_a_platform_operator_is_not_found_by_email_in_the_team(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->seedPlatformUser('buscada@plataforma.test');

        $this->actingAsOrganization($organization);

        $this->assertFalse(User::query()->where('email', 'buscada@plataforma.test')->exists());
    }

    public function test_the_same_address_may_exist_in_both_tables(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->seedPlatformUser('doble@ejemplo.test');

        $user = $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->create(['email' => 'doble@ejemplo.test']),
        );

        $this->assertTrue($user->exists);
        $this->assertSame(1, DB::table('platform_users')->where('email', 'doble@ejemplo.test')->count());
    }

    #[DataProvider('teamPermissionFiles')]
    public function test_the_team_permission_code_never_names_the_platform(string $file): void
    {
        $code = $this->codeOf($file);

        $this->assertStringNotContainsString('platform_users', $code);
        $this->assertStringNotContainsString('PlatformUser', $code);
        $this->assertStringNotContainsString("guard('platform')", $code);
    }

    private function seedPlatformUser(string $email): void
    {
        DB::table('platform_users')->insert([
            'name' => 'Operadora de plataforma',
            'email' => $email,
            'password' => Hash::make('password'),
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path($file))) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : $token[1];
        }

        return $code;
    }
}
