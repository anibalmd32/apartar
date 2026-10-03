<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;
use App\Events\Layaway\LayawayActivated;
use App\Events\Layaway\LayawayTransition;
use App\Events\Layaway\LayawayTransitioned;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Finder\Finder;

/**
 * Todo evento de `app/Events/Layaway/` implementa `LayawayTransitioned`
 * (`REQ-LAY-005` RB-7, `T-046`).
 *
 * El despachador de Laravel resuelve los listeners por clase concreta y por
 * interfaz, **nunca por clase padre**. Las notificaciones (`T-082`) se
 * suscriben a la interfaz una vez; un séptimo evento que no la implementara
 * no notificaría nada, y ningún otro test se pondría rojo. Este sí.
 *
 * Es la convención de «una lista de casos es propiedad del tipo, no del
 * consumidor»: el censo de los eventos lo lleva la carpeta, y lo que se
 * comprueba es que nadie quede fuera.
 */
const LAYAWAY_EVENTS_DIRECTORY = 'app/Events/Layaway';

/**
 * Las clases declaradas en la carpeta, por el nombre de su archivo.
 *
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

/**
 * Y recorre lo que tiene que recorrer: un test que comprueba un conjunto vacío
 * pasa también cuando está roto.
 */
it('encuentra los seis eventos de la máquina', function (): void {
    $concrete = array_values(array_filter(
        layawayEventClasses(),
        fn (string $class): bool => ! (new ReflectionClass($class))->isAbstract() && ! (new ReflectionClass($class))->isInterface(),
    ));

    expect(count($concrete))->toBeGreaterThanOrEqual(6)
        ->and($concrete)->toContain(LayawayActivated::class);
});

/**
 * La razón de ser de la interfaz, afirmada sobre el despachador: escuchar la
 * interfaz recibe el evento, y escuchar la clase base —que se registra sin
 * error— no recibe nada.
 */
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
