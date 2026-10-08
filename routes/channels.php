<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Canales privados de broadcast ([05 §7.7], `REQ-INF-005` RB-7).
|
| Lo que decide quién escucha un canal es **esta autorización y nada más**:
| Reverb solo comprueba la firma que la aplicación dio. Una fuga por aquí no
| la vería ningún test HTTP (`REQ-QAX-008` SP-7), y por eso
| `BroadcastChannelIsolationTest` recorre todo canal registrado —el que se
| añada mañana también— y exige que rechace a un miembro de otra organización
| y acepte a uno de la propia.
|
| El endpoint de autorización (`/broadcasting/auth`) va **fuera del tenant**
| (`outside-tenancy`, en `bootstrap/app.php`): es uno solo para los dos
| guards, y el canal de la conversación (H7) lo abrirá el cliente. El tenant
| no lo resuelve nadie antes; lo comprueba cada canal contra su nombre.
*/

/*
| La bandeja de comprobantes (`REQ-PAY-004` RB-1, SP-7).
|
| Un usuario del guard `web` **de esa organización**, activo y con la
| capacidad de revisar comprobantes, que es la misma que pide abrir el archivo
| (`ReceiptPolicy`). Hoy la tienen los dos roles; si algún día un rol la
| pierde, deja de oír la bandeja sin tocar esto.
|
| **Un cliente no entra**, por dos cerrojos: `guards => ['web']` hace que el
| broadcaster ni siquiera mire la sesión del portal —sin usuario de `web`, es
| `403` antes de llamar aquí—, y si alguna vez llegara una `Customer`, el
| `instanceof` la rechaza. La suspensión no cierra el canal (RB-9): aprobar
| comprobantes es lo que se conserva.
|
| `$user` admite `null` a propósito: un nombre **sin** el prefijo `private-`
| se salta la comprobación de usuario del broadcaster y llega aquí sin nadie.
| Sin el `null` en la firma, eso era un `TypeError` —un `500` que cualquiera
| podía provocar sin sesión— en vez de un `403`.
|
| `$orgId` llega como texto del nombre del canal; se compara como texto
| canónico para que «07» o «7abc» no pasen por un 7. El nombre es el que usa
| `ReceiptSubmitted::channelFor()`, y `ReceiptInboxChannelTest` lo comprueba.
*/
Broadcast::channel(
    'organization.{orgId}.receipts',
    function (User|Customer|null $user, int|string $orgId): bool {
        if (! $user instanceof User || $user->disabled_at !== null) {
            return false;
        }

        if ((string) $user->organization_id !== (string) $orgId) {
            return false;
        }

        // Una organización cerrada ya no tiene equipo: el backoffice cierra su
        // sesión en la siguiente petición (`EnsureOrganizationIsActive`), y
        // este endpoint no pasa por ahí. La suspendida sí entra (RB-9).
        if ($user->organization?->status === OrganizationStatus::Closed) {
            return false;
        }

        return $user->role->can(OrganizationCapability::Receipts);
    },
    ['guards' => ['web']],
);
