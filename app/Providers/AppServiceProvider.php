<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Domain\Account\PortalContext;
use App\Domain\Notification\PushReportHandler;
use App\Domain\Tenancy\TenantContext;
use App\Support\FrameworkMails;
use App\Support\Mail\OutgoingMailContext;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushChannel;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        $this->app->singleton(Settings::class, fn (Application $app): Settings => new Settings(
            $app->make(TenantContext::class),
            $app->make(CacheFactory::class)->store(),
        ));

        $this->app->scoped(PortalContext::class, fn (): PortalContext => PortalContext::fromSession());
        $this->app->scoped(OutgoingMailContext::class);
    }

    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(6)->letters()->numbers()->uncompromised());
        FrameworkMails::register();

        $this->app->when(WebPushChannel::class)
            ->needs(ReportHandlerInterface::class)
            ->give(PushReportHandler::class);

        Auth::provider('tenant-aware', function (Application $app, array $config): TenantAwareUserProvider {
            /** @var class-string<Model> $model */
            $model = $config['model'];

            return new TenantAwareUserProvider($app->make(Hasher::class), $model);
        });

        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('registro', fn (Request $request) => Limit::perHour(5)->by($request->ip() ?? 'sin-ip'));
        RateLimiter::for('registro-enlace', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip() ?? 'sin-ip'),
            Limit::perHour(120)->by('h:'.($request->ip() ?? 'sin-ip')),
        ]);
        RateLimiter::for('apartados', fn (Request $request) => Limit::perMinute(self::LAYAWAY_SUBMISSIONS_PER_MINUTE)->by($request->ip() ?? 'sin-ip'));
        RateLimiter::for('comprobantes', fn (Request $request) => Limit::perHour(self::RECEIPT_SUBMISSIONS_PER_HOUR)
            ->by('ficha:'.(Auth::guard('customer')->id() ?? $request->ip() ?? 'sin-ip')));
        RateLimiter::for('mensajes', fn (Request $request) => Limit::perMinute(self::CHAT_MESSAGES_PER_MINUTE)
            ->by('chat:'.(Auth::guard('account')->id() ?? Auth::guard('customer')->id() ?? $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'sin-ip')));
        RateLimiter::for('avisos', fn (Request $request) => Limit::perHour(self::MANUAL_PUSHES_PER_HOUR)
            ->by('avisos:'.($request->user()?->getAttribute('organization_id') ?? $request->ip() ?? 'sin-ip')));
        RateLimiter::for('reenvio-codigo', fn (Request $request) => Limit::perHour(self::CODE_RESENDS_PER_HOUR)
            ->by('apartado:'.self::routeKeyOf($request->route('layaway'))));
        RateLimiter::for('telemetria', fn (Request $request) => Limit::perMinute(self::TELEMETRY_BATCHES_PER_MINUTE)
            ->by('telemetria:'.($request->ip() ?? 'sin-ip')));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(self::WEBHOOK_CALLS_PER_MINUTE)
            ->by('webhooks:'.($request->ip() ?? 'sin-ip')));
        RateLimiter::for('correo-manual', fn (Request $request) => Limit::perHour(self::OWNER_MAILS_PER_HOUR)
            ->by('correo-manual:'.($request->user('platform')?->getAuthIdentifier() ?? $request->ip() ?? 'sin-ip')));
    }

    public const OWNER_MAILS_PER_HOUR = 60;

    public const WEBHOOK_CALLS_PER_MINUTE = 300;

    public const CODE_RESENDS_PER_HOUR = 3;

    private static function routeKeyOf(mixed $bound): string
    {
        return match (true) {
            $bound instanceof Model => (string) $bound->getKey(),
            is_string($bound) => $bound,
            default => 'sin-apartado',
        };
    }

    public const RECEIPT_SUBMISSIONS_PER_HOUR = 10;

    public const CHAT_MESSAGES_PER_MINUTE = 20;

    public const MANUAL_PUSHES_PER_HOUR = 3;

    public const TELEMETRY_BATCHES_PER_MINUTE = 60;

    public const LAYAWAY_SUBMISSIONS_PER_MINUTE = 10;
}
