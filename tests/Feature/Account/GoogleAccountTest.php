<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Domain\Account\AccountProvider;
use App\Domain\Account\UnverifiedGoogleEmailException;
use App\Http\Controllers\Portal\Auth\GoogleAccountController;
use App\Models\Account;
use App\Models\AccountIdentity;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class GoogleAccountTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Catalog $catalog;

    private string $origin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'client-id-de-prueba',
            'services.google.client_secret' => 'secreto-de-prueba',
            'services.google.redirect' => 'http://localhost/cuenta/google/callback',
        ]);

        $organization = Organization::factory()->create();
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan(Plan::factory()->create())->create());
        $this->catalog = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create());
        $this->origin = '/c/'.$this->catalog->slug;
        $this->withoutOrganization();
    }

    public function test_the_redirect_asks_google_for_exactly_three_scopes(): void
    {
        $location = (string) $this->get(route('account.google.redirect', ['desde' => $this->origin]))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame($this->origin, session(GoogleAccountController::ORIGIN_KEY));
    }

    public function test_an_inertia_visit_leaves_with_a_full_page_location(): void
    {
        $this->get(route('account.google.redirect'), ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');
    }

    public function test_a_foreign_origin_falls_back_to_the_portal_access(): void
    {
        foreach (['//malicioso.test/x', 'https://malicioso.test', '/\\malicioso.test', 'c/sin-barra'] as $foreign) {
            $this->get(route('account.google.redirect', ['desde' => $foreign]));

            $this->assertSame(route('portal.access', absolute: false), session(GoogleAccountController::ORIGIN_KEY), $foreign);
        }
    }

    public function test_a_first_login_creates_the_account_with_its_identity(): void
    {
        $this->signIn($this->google('sub-1', 'maria@gmail.test', name: 'María Pérez'))
            ->assertRedirect($this->origin);

        $account = Account::query()->sole();
        $identity = AccountIdentity::query()->sole();

        $this->assertSame('María Pérez', $account->display_name);
        $this->assertSame('maria@gmail.test', $account->email);
        $this->assertSame('https://lh3.googleusercontent.test/a/foto', $account->avatar_url);
        $this->assertNotNull($account->last_login_at);
        $this->assertSame($account->id, $identity->account_id);
        $this->assertSame(AccountProvider::Google, $identity->provider);
        $this->assertSame('sub-1', $identity->provider_id);
        $this->assertAuthenticatedAs($account, 'account');
    }

    public function test_a_second_login_refreshes_the_profile_without_duplicating(): void
    {
        $this->signIn($this->google('sub-1', 'maria@gmail.test', name: 'María Pérez'));
        $this->post(route('portal.logout'));
        $this->travel(1)->day();

        $this->signIn($this->google('sub-1', 'maria.nueva@gmail.test', name: 'María P.', avatar: null));

        $account = Account::query()->sole();
        $this->assertSame('María P.', $account->display_name);
        $this->assertSame('maria.nueva@gmail.test', $account->email);
        $this->assertNull($account->avatar_url);
        $this->assertTrue($account->last_login_at?->isToday() ?? false);
        $this->assertSame('maria.nueva@gmail.test', AccountIdentity::query()->sole()->email);
        $this->assertAuthenticatedAs($account, 'account');
    }

    public function test_the_same_email_with_another_provider_id_creates_a_new_account(): void
    {
        $existing = Account::factory()->create(['email' => 'maria@gmail.test']);
        AccountIdentity::factory()->for($existing)->create(['provider_id' => 'sub-viejo', 'email' => 'maria@gmail.test']);

        $this->signIn($this->google('sub-nuevo', 'maria@gmail.test'));

        $this->assertSame(2, Account::query()->where('email', 'maria@gmail.test')->count());
        $this->assertSame(2, AccountIdentity::query()->count());
        $this->assertAuthenticatedAs(AccountIdentity::query()->where('provider_id', 'sub-nuevo')->sole()->account()->sole(), 'account');
    }

    public function test_an_email_shared_with_a_team_user_is_accepted(): void
    {
        $user = User::factory()->create(['email' => 'duena@negocio.test']);
        $this->withoutOrganization();

        $this->signIn($this->google('sub-1', 'duena@negocio.test'))->assertRedirect($this->origin);

        $this->assertSame(1, Account::query()->where('email', 'duena@negocio.test')->count());
        $this->assertAuthenticated('account');
        $this->assertGuest('web');
        $this->assertGuest('customer');
        $this->assertSame($user->updated_at?->toIso8601String(), User::query()->withoutGlobalScopes()->findOrFail($user->id)->updated_at?->toIso8601String());
    }

    public function test_a_profile_without_a_verified_email_is_rejected(): void
    {
        foreach ([null, false] as $verified) {
            $this->signIn($this->google('sub-1', 'maria@gmail.test', verified: $verified))
                ->assertRedirect($this->origin)
                ->assertSessionHas('status', UnverifiedGoogleEmailException::MESSAGE);
        }

        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, AccountIdentity::query()->count());
        $this->assertGuest('account');
    }

    public function test_cancelling_at_google_does_not_break_navigation(): void
    {
        $this->get(route('account.google.redirect', ['desde' => $this->origin]));

        $this->get(route('account.google.callback', ['error' => 'access_denied', 'state' => 'x']))
            ->assertRedirect($this->origin)
            ->assertSessionHas('status', GoogleAccountController::CANCELLED_MESSAGE);

        $this->get($this->origin)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('catalog/CatalogShow')
                ->where('status', GoogleAccountController::CANCELLED_MESSAGE));

        $this->assertGuest('account');
        $this->assertSame(0, Account::query()->count());
    }

    public function test_an_invalid_state_answers_419_and_restarts_from_the_origin(): void
    {
        $this->get(route('account.google.redirect', ['desde' => $this->origin]));

        $this->get(route('account.google.callback', ['code' => 'codigo', 'state' => 'no-es-el-nuestro']))
            ->assertStatus(419)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/GoogleSignInExpired')
                ->where('retryUrl', route('account.google.redirect', ['desde' => $this->origin]))
                ->where('backUrl', $this->origin));

        $this->assertGuest('account');
    }

    public function test_google_being_down_is_told_without_a_technical_error(): void
    {
        Socialite::fake('google', fn () => throw new ConnectException('cURL error 28', new PsrRequest('POST', 'https://oauth2.googleapis.com/token')));
        $this->get(route('account.google.redirect', ['desde' => $this->origin]));

        $this->get(route('account.google.callback', ['code' => 'codigo', 'state' => 'x']))
            ->assertRedirect($this->origin)
            ->assertSessionHas('status', GoogleAccountController::UNAVAILABLE_MESSAGE);

        $this->assertGuest('account');
    }

    public function test_an_invalid_state_from_the_fake_is_the_same_419(): void
    {
        Socialite::fake('google', fn () => throw new InvalidStateException);

        $this->get(route('account.google.callback', ['code' => 'codigo', 'state' => 'x']))->assertStatus(419);
    }

    public function test_logging_out_keeps_the_account(): void
    {
        $this->signIn($this->google('sub-1', 'maria@gmail.test'));
        $token = session()->token();

        $this->post(route('portal.logout'))->assertRedirect(route('portal.access'));

        $this->assertGuest('account');
        $this->assertNotSame($token, session()->token());
        $this->assertSame(1, Account::query()->count());
        $this->assertSame(1, AccountIdentity::query()->count());
    }

    public function test_only_the_first_login_records_account_created(): void
    {
        $this->signIn($this->google('sub-1', 'maria@gmail.test'));
        $this->signIn($this->google('sub-1', 'maria@gmail.test'));

        $row = DB::table('telemetry_events')->where('event_name', 'account_created')->sole();

        $this->assertSame((int) Account::query()->sole()->getKey(), (int) $row->account_id);
        $this->assertNull($row->organization_id);
        $this->assertSame(['provider' => 'google'], json_decode($row->payload, true));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function signIn(GoogleUser $user): TestResponse
    {
        Socialite::fake('google', $user);
        $this->get(route('account.google.redirect', ['desde' => $this->origin]));

        return $this->get(route('account.google.callback', ['code' => 'codigo', 'state' => 'x']));
    }

    private function google(
        string $sub,
        string $email,
        ?bool $verified = true,
        string $name = 'María Pérez',
        ?string $avatar = 'https://lh3.googleusercontent.test/a/foto',
    ): GoogleUser {
        $raw = ['sub' => $sub, 'email' => $email, 'name' => $name, 'picture' => $avatar];

        if ($verified !== null) {
            $raw['email_verified'] = $verified;
        }

        return (new GoogleUser)->setRaw($raw)->map([
            'id' => $sub,
            'email' => $email,
            'name' => $name,
            'avatar' => $avatar,
        ]);
    }
}
