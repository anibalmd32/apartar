<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Support\DocumentNumber;
use App\Support\FullTextTerm;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * Una sola caja que decide el criterio (`REQ-BOF-040` RB-1): teléfono,
 * documento, correo o nombre. La comparten el directorio de clientes y la
 * cobranza (`REQ-BOF-042` RB-3), para que buscar a la misma persona dé lo
 * mismo en las dos pantallas.
 *
 * El orden importa y no es arbitrario:
 *
 *  1. **Teléfono**, normalizado a E.164 (SP-3): diez dígitos o más, con su
 *     cero, su `+58` o sin ellos. Va primero porque es el canal real de
 *     contacto (RB-1) y porque un número de diez dígitos no es una cédula.
 *  2. **Solo cifras** (`12.345.678`, `0414123`, `4567`): el documento o el
 *     teléfono que las contienen. Una cédula sin letra puede ser también medio
 *     teléfono —`0414123` es las dos cosas—, y lo que se dicta por teléfono
 *     llega a trozos: una búsqueda exacta no encontraría nada.
 *  3. **Documento con su letra**, normalizado (`V-12345678`, SP-2), exacto.
 *  4. **Correo**, si lleva `@`: el que lo contiene, sin distinguir mayúsculas.
 *  5. **Lo demás** (`REQ-IDN-007` SP-6): el nombre —cada palabra tiene que
 *     aparecer, en cualquier orden— o el correo que lo contiene.
 *
 * Las búsquedas exactas usan los índices `(organization_id, document_number)`
 * y `(organization_id, phone)` (SP-5); las parciales, `LIKE`.
 */
final readonly class CustomerSearch
{
    private function __construct(
        public string $criterion,
        private string $value,
        /** @var list<string> */
        private array $patterns,
    ) {}

    /** El término, o `null` si no queda nada que buscar. */
    public static function from(mixed $raw): ?self
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $phone = PhoneNumber::tryFromInput($raw);

        if ($phone instanceof PhoneNumber) {
            return new self('phone', $phone->value, []);
        }

        $digits = self::digitsOf($raw);

        if ($digits !== null) {
            return new self('digits', $digits, ['%'.$digits.'%', self::phoneFragment($digits)]);
        }

        $document = DocumentNumber::tryFromInput($raw);

        if ($document instanceof DocumentNumber) {
            return new self('document', $document->value, []);
        }

        if (str_contains($raw, '@')) {
            return new self('email', self::contains(mb_strtolower($raw)), []);
        }

        $term = FullTextTerm::sanitize($raw);

        return $term instanceof FullTextTerm ? new self('name', self::contains(mb_strtolower($term->text)), $term->likePatterns()) : null;
    }

    /**
     * Acota una consulta de fichas al término.
     *
     * @param  Builder<Customer>  $customers
     * @return Builder<Customer>
     */
    public function apply(Builder $customers): Builder
    {
        return match ($this->criterion) {
            'phone' => $customers->where('customers.phone', $this->value),
            'document' => $customers->where('customers.document_number', $this->value),
            'digits' => $customers->where(fn (Builder $query) => $query
                ->where('customers.document_number', 'like', $this->patterns[0])
                ->orWhere('customers.phone', 'like', $this->patterns[1])),
            'email' => $customers->whereRaw('LOWER(customers.email) LIKE ?', [$this->value]),
            // `REQ-IDN-007` SP-6 · lo que no se normaliza busca en el nombre
            // —cada palabra— y en el correo.
            default => $customers->where(fn (Builder $query) => $query
                ->where(function (Builder $name): void {
                    foreach ($this->patterns as $pattern) {
                        $name->where('customers.full_name', 'like', $pattern);
                    }
                })
                ->orWhereRaw('LOWER(customers.email) LIKE ?', [$this->value])),
        };
    }

    /** El término como «contiene», con sus comodines escapados. */
    private static function contains(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }

    /**
     * Las cifras de un término que solo tiene cifras y separadores
     * (`0414 123`, `12.345`), si son al menos tres; `null` si no.
     */
    private static function digitsOf(string $raw): ?string
    {
        if (preg_match('/^[\d\s.\-+()]+$/', $raw) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $raw);

        return strlen($digits) >= 3 ? $digits : null;
    }

    /**
     * Las cifras como trozo de un teléfono, que se guarda en E.164: sin el
     * cero de delante (`0414…` es `+58414…`).
     */
    private static function phoneFragment(string $digits): string
    {
        $fragment = ltrim($digits, '0');

        return '%'.($fragment === '' ? $digits : $fragment).'%';
    }
}
