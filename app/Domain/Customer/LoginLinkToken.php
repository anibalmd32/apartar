<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * El token del enlace de acceso por correo (`REQ-IDN-003`).
 *
 * Tiene la forma de la invitación de equipo (`TeamInvitation`, `T-013`):
 * `{organization_id}.{secreto}`. El prefijo dice a qué negocio pertenece, y
 * eso es lo que permite fijar el tenant **antes** de buscar el hash, con un
 * índice `UNIQUE(organization_id, token_hash)` compuesto y sin abrir una cuarta
 * excepción en `GlobalUniqueExceptions` (`T-039`).
 *
 * En la fila solo se guarda el SHA-256 (RB-4): el texto en claro viaja una
 * vez, en el correo.
 */
final readonly class LoginLinkToken
{
    /** RB-4 · treinta minutos. */
    public const TTL_MINUTES = 30;

    private const SEPARATOR = '.';

    /** 256 bits, como el de la invitación. */
    private const SECRET_BYTES = 32;

    private function __construct(
        /** Texto en claro que viaja en el correo. No se persiste. */
        public string $plainToken,
        /** Lo único que se guarda. */
        public string $tokenHash,
        public CarbonImmutable $expiresAt,
    ) {}

    public static function issueFor(Organization $organization): self
    {
        $plainToken = $organization->getKey().self::SEPARATOR.bin2hex(random_bytes(self::SECRET_BYTES));

        return new self($plainToken, self::hash($plainToken), CarbonImmutable::now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * SHA-256 y no `bcrypt`: es un secreto aleatorio de 256 bits, no una
     * contraseña, y tiene que poder buscarse por índice.
     */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * La organización del prefijo, o `null` si el token no tiene la forma.
     * No lanza: un enlace mal copiado es el caso normal, no un error.
     */
    public static function organizationIdIn(string $plainToken): ?int
    {
        $prefix = Str::before($plainToken, self::SEPARATOR);

        return $prefix !== '' && $prefix !== $plainToken && ctype_digit($prefix) ? (int) $prefix : null;
    }

    public function url(): string
    {
        return route('portal.link.show', ['token' => $this->plainToken]);
    }
}
