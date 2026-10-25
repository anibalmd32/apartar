<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;
use App\Events\Layaway\LayawayActivated;
use App\Events\Layaway\LayawayTransition;
use App\Events\Layaway\LayawayTransitioned;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Finder\Finder;

const LAYAWAY_EVENTS_DIRECTORY = 'app/Events/Layaway';

/**
 * @return list<class-string>
 */
function layawayEventClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(base_path(LAYAWAY_EVENTS_DIRECTORY))->name('*.php') as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], str_replace('\\', '/', $file->getRelativePathname()));

        /** @var class-string $class */
        $class = 'App\\Events\\Layaway\\'.$relative;
        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

it('exige que todo evento del apartado implemente la interfaz', function (): void {
    $offenders = array_values(array_filter(
        layawayEventClasses(),
        fn (string $class): bool => $class !== LayawayTransitioned::class && ! is_subclass_of($class, LayawayTransitioned::class),
    ));

    expect($offenders)->toBe([], "Estos eventos de app/Events/Layaway no implementan LayawayTransitioned:\n"
        .implode("\n", $offenders)
        ."\nQuien escucha todas las transiciones se suscribe a esa interfaz: sin ella, el evento no notifica nada. "
        .'Extiende LayawayTransition, que ya la implementa.');
});

it('encuentra los seis eventos de la máquina', function (): void {
    $concrete = array_values(array_filter(
        layawayEventClasses(),
        fn (string $class): bool => ! (new ReflectionClass($class))->isAbstract() && ! (new ReflectionClass($class))->isInterface(),
    ));

    expect(count($concrete))->toBeGreaterThanOrEqual(6)
        ->and($concrete)->toContain(LayawayActivated::class);
});

it('entrega los eventos a quien escucha la interfaz y no a quien escucha la base', function (): void {
    /** @var list<int> $byInterface */
    $byInterface = [];
    /** @var list<int> $byParent */
    $byParent = [];

    Event::listen(LayawayTransitioned::class, function (LayawayTransitioned $event) use (&$byInterface): void {
        $byInterface[] = $event->layawayId();
    });
    Event::listen(LayawayTransition::class, function (LayawayTransition $event) use (&$byParent): void {
        $byParent[] = $event->layawayId;
    });

    event(new LayawayActivated(7, 1, LayawayStatus::PendingDownPayment, LayawayStatus::Active, CarbonImmutable::now()));

    expect($byInterface)->toBe([7])
        ->and($byParent)->toBe([]);
});
