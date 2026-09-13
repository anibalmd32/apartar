<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\ReservedSlugs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registra un negocio y lo deja dentro (`REQ-ORG-001`).
 *
 * **Cuatro creaciones en una sola transacción** (RB-1): la organización, su
 * usuario `owner`, la suscripción Free y los once ajustes por defecto. La
 * atomicidad no es una precaución genérica, es lo que impide dos estados de
 * los que el sistema no sabe salir:
 *
 *  - una organización **sin suscripción**, que `REQ-SUB-002` RB-6 prohíbe
 *    —no tiene límites que comprobar y no aparece en ningún informe—;
 *  - un `owner` **huérfano**, que ocupa para siempre un correo único de toda
 *    la plataforma (RB-3) sin que nadie pueda usarlo ni recuperarlo.
 *
 * El orden importa y es el de [05 §5.1]: primero la organización, porque las
 * otras tres cuelgan de ella, y a partir de ahí todo ocurre **dentro de su
 * contexto de tenant**, de modo que el trait rellena `organization_id` y nadie
 * lo asigna a mano (`REQ-ORG-002` RB-2).
 *
 * La autenticación va **después del commit**: iniciar sesión para una
 * organización que después se deshace dejaría al navegador con una sesión
 * apuntando a filas que ya no existen.
 */
final class RegisterOrganizationAction
{
    /**
     * Variantes de slug que se prueban antes de rendirse y sufijar al azar.
     *
     * Cincuenta homónimos en la plataforma es un escenario que no se ha dado
     * nunca; el tope existe para que el bucle no sea el que decida cuándo
     * parar.
     */
    private const MAX_SLUG_VARIANTS = 50;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StartFreeSubscriptionAction $startFreeSubscription,
    ) {}

    /**
     * @throws SlugTakenException si el slug pedido está ocupado o reservado
     * @throws InvalidArgumentException si el nombre no produce ningún slug y no se pidió uno
     */
    public function handle(RegisterOrganizationData $data): Organization
    {
        $slug = $this->resolveSlug($data);

        [$organization, $owner] = DB::transaction(
            fn (): array => $this->createEverything($data, $slug),
        );

        Auth::guard('web')->login($owner);

        return $organization;
    }

    /**
     * Las cuatro creaciones, en orden y bajo el tenant recién nacido.
     *
     * @return array{0: Organization, 1: User}
     */
    private function createEverything(RegisterOrganizationData $data, string $slug): array
    {
        $organization = $this->createOrganization($data, $slug);

        return $this->context->runFor($organization, function () use ($organization, $data): array {
            $owner = User::query()->create([
                'name' => $data->ownerName,
                'email' => $data->normalisedEmail(),
                'password' => $data->ownerPassword,
                'role' => OrganizationRole::Owner,
                // El correo nace sin verificar y eso **no** bloquea el
                // backoffice (RB-5): solo impedirá publicar un catálogo. Un
                // registro que exige verificar antes de dejar mirar nada
                // pierde a quien solo quería probar.
                'must_change_password' => false,
            ]);

            $this->startFreeSubscription->handle($organization);
            $this->seedDefaultSettings();

            return [$organization, $owner];
        });
    }

    /**
     * @throws SlugTakenException si otra organización se adelantó entre la
     *                            comprobación y el `INSERT`
     */
    private function createOrganization(RegisterOrganizationData $data, string $slug): Organization
    {
        try {
            return Organization::query()->create([
                'name' => trim($data->organizationName),
                'slug' => $slug,
                // El RIF es opcional en el registro y solo hace falta para la
                // primera factura de suscripción (RB-7): pedirlo aquí sería
                // un trámite antes de haber visto el producto.
                'document' => null,
                'status' => OrganizationStatus::Active,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos registros con el mismo slug a la vez: los dos pasaron la
            // comprobación de arriba y solo uno pasa el índice. El que pierde
            // recibe la misma sugerencia que habría recibido un segundo antes.
            throw SlugTakenException::suggesting($slug, $this->firstFreeVariant($slug));
        }
    }

    /**
     * Siembra los once ajustes con sus valores por defecto ([02 §9.1]).
     *
     * Se escriben como filas y no se dejan implícitos: un ajuste ausente
     * obligaría a que cada lectura conociera el default, que es la segunda
     * fuente de verdad que `SettingKey` existe para evitar.
     */
    private function seedDefaultSettings(): void
    {
        foreach (SettingKey::cases() as $key) {
            OrganizationSetting::query()->create([
                'key' => $key,
                'value' => $key->defaultValue(),
                // Nadie lo ha cambiado todavía: es el valor sembrado.
                'updated_by' => null,
            ]);
        }
    }

    /**
     * El slug con el que nace la organización.
     *
     * @throws SlugTakenException
     * @throws InvalidArgumentException
     */
    private function resolveSlug(RegisterOrganizationData $data): string
    {
        $slug = $data->preferredSlug();

        if ($slug === '') {
            // SP-7: un nombre de solo emojis o de solo signos no produce
            // enlace. Inventarle uno le daría al negocio una URL que no se
            // parece a su nombre y que no sabría explicar a sus clientes.
            throw new InvalidArgumentException(
                'El nombre del negocio no produce ningún enlace: hace falta escribir uno.',
            );
        }

        if (ReservedSlugs::contains($slug)) {
            throw SlugTakenException::reserved($slug, $this->firstFreeVariant($slug));
        }

        if ($this->slugExists($slug)) {
            throw SlugTakenException::suggesting($slug, $this->firstFreeVariant($slug));
        }

        return $slug;
    }

    /**
     * Primera variante libre: `ferreteria-el-tornillo-2`, `-3`, y así.
     *
     * Sufijo numérico y no aleatorio porque lo va a leer una persona que
     * acaba de escribir el nombre de su negocio, y `-2` se entiende sin
     * explicación.
     */
    private function firstFreeVariant(string $slug): string
    {
        for ($suffix = 2; $suffix <= self::MAX_SLUG_VARIANTS; $suffix++) {
            $candidate = $this->truncatedVariant($slug, (string) $suffix);

            if (! ReservedSlugs::contains($candidate) && ! $this->slugExists($candidate)) {
                return $candidate;
            }
        }

        return $this->truncatedVariant($slug, (string) random_int(1_000, 9_999));
    }

    /**
     * Variante que cabe en la columna, que admite cien caracteres.
     *
     * Se recorta el nombre y no el sufijo: un slug cortado sigue siendo
     * reconocible, uno sin sufijo volvería a chocar.
     */
    private function truncatedVariant(string $slug, string $suffix): string
    {
        $room = 100 - (strlen($suffix) + 1);

        return substr($slug, 0, $room).'-'.$suffix;
    }

    private function slugExists(string $slug): bool
    {
        return Organization::query()->where('slug', $slug)->exists();
    }
}
