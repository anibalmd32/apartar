<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * El enlace con el que alguien entra a un equipo (`REQ-ORG-004` RB-4).
 *
 * Es una **credencial**, así que se trata como tal y no como un identificador:
 * vive 48 horas, sirve una sola vez, el reenvío invalida el anterior y lo que
 * se guarda en la fila es su hash. El texto en claro existe solo dentro de
 * este objeto, viaja al correo y no vuelve a estar disponible nunca —ni
 * siquiera para el `owner` que invitó—, que es exactamente lo que se espera de
 * algo con lo que se entra a un sitio.
 *
 * **El token lleva delante el identificador de su organización**, y eso no es
 * un adorno. `users` es una tabla del tenant: buscarla exige una organización
 * fijada, y quien llega por el enlace no tiene sesión de la que sacarla. Con
 * el prefijo, la organización se resuelve de `organizations` —que no está
 * sujeta al tenant— **antes** de tocar `users`, y la búsqueda del token ocurre
 * ya filtrada. La alternativa era un `UNIQUE(invitation_token_hash)` global,
 * que habría necesitado una cuarta excepción en `GlobalUniqueExceptions`
 * (`REQ-QAX-008` RB-4) para ahorrarse un `WHERE`.
 *
 * Va el **identificador** y no el `slug` porque el `slug` es editable por el
 * `owner` mientras no tenga catálogos publicados (`REQ-ORG-006`): un cambio de
 * enlace de la vitrina no puede romper las invitaciones en vuelo.
 */
final readonly class TeamInvitation
{
    /**
     * Horas que vive el enlace (RB-4).
     *
     * Dos días es lo que tarda en abrir el correo quien estaba de viaje, y
     * poco más de lo que un enlace robado sirve para algo.
     */
    public const TTL_HOURS = 48;

    /**
     * Separador entre la organización y el secreto.
     *
     * Un punto y no un guion: el secreto es hexadecimal y la parte de delante
     * un número, así que no hay ambigüedad posible al partir por el primero.
     */
    private const SEPARATOR = '.';

    /**
     * Bytes aleatorios del secreto. 32 bytes son 256 bits: el mismo orden que
     * el `remember_token` del framework, para algo que abre la misma puerta.
     */
    private const SECRET_BYTES = 32;

    private function __construct(
        /** Texto en claro que viaja en el correo. No se persiste. */
        public string $plainToken,
        /** Lo único que se guarda en la fila. */
        public string $tokenHash,
        public Carbon $expiresAt,
    ) {}

    /**
     * Emite un enlace nuevo para esa organización.
     *
     * Emitir **es** invalidar el anterior (RB-4, SP-6): quien llame guarda el
     * hash nuevo sobre el viejo, y el enlace anterior deja de resolver a nada.
     * No hace falta una lista de revocados porque solo hay un enlace vivo por
     * persona.
     */
    public static function issueFor(Organization $organization): self
    {
        $plainToken = $organization->getKey().self::SEPARATOR.bin2hex(random_bytes(self::SECRET_BYTES));

        return new self(
            plainToken: $plainToken,
            tokenHash: self::hash($plainToken),
            expiresAt: Carbon::now()->addHours(self::TTL_HOURS),
        );
    }

    /**
     * Huella de un token en claro, tal como se guarda en la fila.
     *
     * SHA-256 y no `bcrypt`: el token es un secreto de 256 bits generado por
     * nosotros, no una contraseña elegida por una persona, así que no hay
     * diccionario contra el que estirar el cómputo. Y el hash tiene que poder
     * **buscarse por índice**, que con un algoritmo salado no se puede.
     */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Organización a la que pertenece un token, leída de su prefijo.
     *
     * Devuelve `null` si el token no tiene la forma esperada. Es lo primero
     * que se hace con lo que llega por la URL, y de ahí que no lance: un
     * enlace mal copiado es el caso normal de SP-3, no un error del sistema.
     */
    public static function organizationIdIn(string $plainToken): ?int
    {
        $prefix = Str::before($plainToken, self::SEPARATOR);

        return $prefix !== '' && ctype_digit($prefix) ? (int) $prefix : null;
    }

    /**
     * URL absoluta del enlace, la que va en el correo.
     */
    public function url(): string
    {
        return route('team.invitation.show', ['token' => $this->plainToken]);
    }
}
