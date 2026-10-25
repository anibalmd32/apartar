<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\GrantSupportAccessAction;
use App\Domain\Auditing\AuditEvent;
use App\Models\AuditEntry;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\TestCase;

final class RevokeSupportAccessActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use RefreshDatabase;

    public function test_another_operator_can_revoke_and_access_ends_on_the_next_request(): void
    {
        [$organization] = $this->businessWithOwner();
        $first = $this->operator();
        $grant = app(GrantSupportAccessAction::class)->handle($first, $organization, 'Revisión de un balance que el negocio reporta mal');

        $this->asOperator($first);
        $this->get($this->data($organization))->assertOk();

        $this->asOperator();
        $this->delete('/plataforma/organizaciones/'.$organization->slug.'/soporte/'.$grant->getKey())->assertRedirect();

        $this->asOperator($first);
        $this->get($this->data($organization))->assertForbidden();
        $this->assertNotNull($grant->refresh()->revoked_at);
        $this->assertSame(1, $this->withinOrganization($organization, fn (): int => AuditEntry::query()->ofEvent(AuditEvent::SupportAccessRevoked)->count()));
    }

    private function data(Organization $organization): string
    {
        return '/plataforma/organizaciones/'.$organization->slug.'/datos/apartados';
    }
}
