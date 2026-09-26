<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\NameDivergence;
use App\Domain\Customer\TermsConsent;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Customer;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\DocumentNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Encuentra o crea la ficha del cliente en la organización fijada
 * (`REQ-IDN-001`).
 *
 * La llama `CreateLayawayAction` (`T-045`) **dentro de su transacción**
 * (RB-6), y es el único sitio donde nace una ficha: no hay endpoint de «crear
 * cliente» ni alta manual en el backoffice (RB-8). Sin tenant fijado no llega
 * a leer nada —el global scope lanza `MissingTenantContextException`
 * (SP-8)—, así que no existe una ficha sin organización.
 *
 * ## Reutilizar es sobrescribir desde una puerta abierta
 *
 * RB-4: si el documento ya existe en el negocio, la ficha se reutiliza y su
 * nombre, teléfono y correo se sustituyen por los nuevos. El formulario que
 * los manda es **público y sin sesión**, y lo único que acredita identidad es
 * saberse el documento: quien conozca la cédula de un cliente puede cambiar el
 * teléfono con el que el negocio lo contacta. No se bloquea —el cliente que
 * cambió de número es el caso normal y la venta no se detiene (SP-3)—, así que
 * **la auditoría es la única mitigación**, y por eso toda sobrescritura deja
 * `customer.contact_updated` con el antes y el después y la IP desde la que se
 * hizo (`REQ-BOF-005` RB-1).
 *
 * `customer.identity_conflict` es otra cosa: la alarma para revisión humana
 * cuando el nombre nuevo no se parece en nada al anterior (`NameDivergence`).
 * Sale **además** de la sobrescritura, no en su lugar, y sale poco.
 *
 * ## El consentimiento se renueva, y el anterior queda en la auditoría
 *
 * `REQ-IDN-005` RB-3: cada apartado renueva la marca —evidencia por
 * operación— «con rastro en auditoría». La ficha guarda la **última**
 * aceptación; las anteriores sobreviven como el «antes» de cada entrada
 * `customer.contact_updated`. Por eso esa entrada sale también cuando vuelve
 * un cliente con el contacto idéntico: lo que se sobrescribió entonces es la
 * evidencia de su apartado anterior, y perderla sin rastro sería justo lo que
 * RB-3 prohíbe.
 *
 * ## SP-5: dos peticiones con el mismo documento nuevo
 *
 * Las dos leen, las dos ven que no existe, las dos insertan; el índice único
 * deja pasar a una y a la otra le devuelve una colisión. Esa colisión se
 * captura y **se vuelve a leer una vez**, y la relectura tiene que ser
 * **con bloqueo**: en `REPEATABLE READ` una lectura normal dentro de la misma
 * transacción usa la foto tomada en la primera, anterior a que la otra
 * confirmase, y devolvería otra vez «no existe». Medido en el motor en
 * `ResolveCustomerConcurrencyTest`.
 *
 * La primera lectura **no** bloquea, y también es a propósito: según el
 * comportamiento documentado de InnoDB, un `FOR UPDATE` sobre un documento que
 * no existe toma un bloqueo de hueco, dos peticiones lo toman a la vez, y las
 * dos inserciones acaban en un *deadlock* que deshace la transacción entera
 * del apartado en lugar de una colisión que se puede capturar.
 */
final class ResolveCustomerAction
{
    use LogsSensitiveActivity;

    /**
     * Quién sobrescribe, para la descripción de la entrada (`REQ-BOF-005`
     * RB-7): no hay usuario, hay un visitante sin sesión.
     */
    public const PROCESS = 'formulario público de apartado';

    /**
     * Lo que viaja al antes y después de `customer.contact_updated`.
     *
     * El contacto de RB-4 y la evidencia de consentimiento: la IP desde la que
     * se sobrescribió un teléfono es el dato que el dueño va a necesitar el día
     * que un cliente reclame que dejaron de llegarle los avisos.
     *
     * @var list<string>
     */
    private const AUDITED_FIELDS = ['full_name', 'phone', 'email', 'terms_accepted_at', 'terms_ip_address'];

    /**
     * La ficha del documento en este negocio, creada o reutilizada.
     *
     * @param  TermsConsent  $consent  **obligatorio en los dos caminos**: la ficha nueva nace con él y la reutilizada lo renueva
     *
     * @throws MissingTenantContextException sin organización fijada (SP-8)
     */
    public function handle(CustomerContactData $contact, TermsConsent $consent): Customer
    {
        return DB::transaction(function () use ($contact, $consent): Customer {
            $collision = null;

            if (! $this->exists($contact->documentNumber)) {
                try {
                    return $this->create($contact, $consent);
                } catch (UniqueConstraintViolationException $exception) {
                    // SP-5 · otra petición la creó entre nuestra lectura y
                    // nuestra inserción. Se reutiliza la suya.
                    $collision = $exception;
                }
            }

            $customer = $this->lockedFind($contact->documentNumber);

            if (! $customer instanceof Customer) {
                // Una sola relectura, y si tampoco la ve no se insiste: lo que
                // colisionó ya no está, y adivinar por qué es peor que decirlo.
                throw $collision ?? new LogicException(sprintf(
                    'La ficha %s existía al leerla y había desaparecido al bloquearla.',
                    $contact->documentNumber->value,
                ));
            }

            return $this->reuse($customer, $contact, $consent);
        });
    }

    /**
     * Lectura sin bloqueo: decide qué camino tomar, nada más.
     */
    private function exists(DocumentNumber $document): bool
    {
        return Customer::query()->where('document_number', $document->value)->exists();
    }

    /**
     * La fila bloqueada y **en su última versión confirmada**, que es lo que
     * lee un `FOR UPDATE` aunque la transacción tenga ya su foto.
     */
    private function lockedFind(DocumentNumber $document): ?Customer
    {
        return Customer::query()->where('document_number', $document->value)->lockForUpdate()->first();
    }

    /**
     * La inserción va en su propio *savepoint*: si colisiona, se deshace ella
     * sola y la transacción del apartado sigue viva para reutilizar la ficha.
     */
    private function create(CustomerContactData $contact, TermsConsent $consent): Customer
    {
        return DB::transaction(function () use ($contact, $consent): Customer {
            $customer = new Customer;

            $customer->forceFill([
                'document_number' => $contact->documentNumber->value,
                'document_kind' => $contact->documentNumber->kind,
                ...$this->contactAttributes($contact, $consent),
            ])->save();

            return $customer;
        });
    }

    /**
     * RB-4 · sobrescribe contacto y consentimiento, y lo deja escrito.
     */
    private function reuse(Customer $customer, CustomerContactData $contact, TermsConsent $consent): Customer
    {
        $before = $this->auditedFieldsOf($customer);

        $customer->forceFill($this->contactAttributes($contact, $consent))->save();

        $after = $this->auditedFieldsOf($customer);

        if ($before !== $after) {
            $this->audit(
                AuditEvent::CustomerContactUpdated,
                subject: $customer,
                before: $before,
                after: $after,
                process: self::PROCESS,
            );
        }

        // SP-3 · se acepta y se actualiza igual; lo que se añade es la alarma.
        if (NameDivergence::isRadical((string) $before['full_name'], $customer->full_name)) {
            $this->audit(
                AuditEvent::CustomerIdentityConflict,
                subject: $customer,
                before: ['full_name' => $before['full_name']],
                after: ['full_name' => $customer->full_name],
                process: self::PROCESS,
            );
        }

        return $customer;
    }

    /**
     * Lo que se escribe en los dos caminos. Es un solo sitio a propósito: si el
     * consentimiento se escribiera aparte en cada uno, el día que alguien
     * tocara la reutilización podría olvidarlo sin que nada lo notara.
     *
     * **Un correo ausente no borra el almacenado** (corrección de RB-4, hecha en
     * `T-045`): este formulario es el único camino de escritura de la ficha, así
     * que un nulo es siempre una omisión y nunca una decisión, y borrarlo le
     * quitaría al cliente el enlace de acceso de `REQ-IDN-003`, que pide
     * documento **y** correo. Ausente no es vacío: el día que exista una
     * superficie que permita borrarlo, mandará una señal propia.
     *
     * @return array<string, mixed>
     */
    private function contactAttributes(CustomerContactData $contact, TermsConsent $consent): array
    {
        $attributes = [
            'full_name' => $contact->fullName,
            'phone' => $contact->phone->value,
            'terms_accepted_at' => $consent->acceptedAt,
            'terms_ip_address' => $consent->ipAddress,
        ];

        if ($contact->email !== null) {
            $attributes['email'] = $contact->email;
        }

        return $attributes;
    }

    /**
     * Los valores reales, sin redactar: el diff se calcula sobre ellos y se
     * sanea al serializar (`REQ-BOF-005` RB-5b). Ninguno de estos es de los que
     * RB-5 prohíbe.
     *
     * @return array<string, string|null>
     */
    private function auditedFieldsOf(Customer $customer): array
    {
        $fields = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $value = $customer->getAttribute($field);

            $fields[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value === null => null,
                default => (string) $value,
            };
        }

        return $fields;
    }
}
