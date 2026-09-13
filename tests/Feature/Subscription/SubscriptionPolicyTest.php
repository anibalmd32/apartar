<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Policies\SubscriptionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * `SubscriptionPolicy` después de mudarse al descubrimiento por convención.
 *
 * `T-009` la registró como *gates* con nombre porque `Subscription` no
 * existía. Con el modelo creado, Laravel la encuentra sola desde
 * `App\Models\Subscription` y los métodos reciben la fila, lo que añade una
 * comprobación que antes no cabía: que la suscripción sea la de la
 * organización de quien actúa.
 */
final class SubscriptionPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Que la encuentre por convención es lo que se acaba de cambiar, así que
     * se comprueba explícitamente: sin esto, un `Gate::authorize()` sobre una
     * suscripción respondería que no hay policy y **denegaría** —o peor,
     * dejaría pasar— sin que nadie supiera por qué.
     */
    public function test_it_is_discovered_by_convention(): void
    {
        $this->assertInstanceOf(SubscriptionPolicy::class, Gate::getPolicyFor(Subscription::class));
    }

    /**
     * Las dos áreas de gobierno que gestiona: el `owner` entra, el `member`
     * no (`REQ-ORG-003` RB-1).
     */
    public function test_only_the_owner_governs_the_subscription(): void
    {
        [$owner, $member, $subscription] = $this->scenario();

        $this->assertTrue(Gate::forUser($owner)->allows('view', $subscription));
        $this->assertTrue(Gate::forUser($owner)->allows('changePlan', $subscription));
        $this->assertTrue(Gate::forUser($owner)->allows('viewInvoices', $subscription));
        $this->assertTrue(Gate::forUser($owner)->allows('uploadPaymentProof', $subscription));

        $this->assertFalse(Gate::forUser($member)->allows('view', $subscription));
        $this->assertFalse(Gate::forUser($member)->allows('changePlan', $subscription));
        $this->assertFalse(Gate::forUser($member)->allows('viewInvoices', $subscription));
        $this->assertFalse(Gate::forUser($member)->allows('uploadPaymentProof', $subscription));
    }

    /**
     * Y la comprobación que la mudanza hizo posible: el `owner` de un negocio
     * no toca la suscripción del de al lado.
     *
     * Por la URL no llegaría —el binding de otra organización da `404`
     * (`REQ-ORG-002` RB-6)—, pero una `Action` que resuelva la fila por su
     * cuenta se queda sin esa red.
     */
    public function test_an_owner_does_not_govern_the_subscription_next_door(): void
    {
        [$owner] = $this->scenario();

        $neighbour = $this->neighbourOrganization();

        $foreign = $this->withinOrganization(
            $neighbour,
            fn (): Subscription => Subscription::factory()->create(),
        );

        $this->withoutOrganization();

        $this->assertFalse(Gate::forUser($owner)->allows('view', $foreign));
        $this->assertFalse(Gate::forUser($owner)->allows('changePlan', $foreign));
    }

    /**
     * @return array{0: User, 1: User, 2: Subscription}
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        /** @var array{0: User, 1: User, 2: Subscription} $scenario */
        $scenario = $this->withinOrganization($organization, fn (): array => [
            User::factory()->state(['role' => OrganizationRole::Owner])->create(),
            User::factory()->state(['role' => OrganizationRole::Member])->create(),
            Subscription::factory()->create(),
        ]);

        $this->withoutOrganization();

        return $scenario;
    }
}
