<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use Illuminate\Support\Str;

/**
 * Lo que hace falta para registrar un negocio (`REQ-ORG-001`).
 *
 * Existe para que `RegisterOrganizationAction` reciba datos ya normalizados y
 * no un array suelto: quien la construye —hoy `RegisterOrganizationRequest`,
 * mañana un comando de consola o un seeder— decide de dónde salen los valores,
 * y la acción no tiene que volver a preguntárselo.
 *
 * El slug puede venir vacío: si el dueño no lo tocó, se deriva del nombre. Lo
 * que **no** hace esta clase es resolver colisiones; eso es de la acción,
 * porque para saber si un slug está libre hay que mirar la base de datos
 * dentro de la transacción.
 *
 * **Ya no declara `phone`.** El contrato de `REQ-ORG-001` lo menciona, pero la
 * propiedad se quedó huérfana desde `T-010`: no había columna donde guardarlo
 * y el formulario no lo pedía, así que era un parámetro que nadie escribía ni
 * leía. `T-014` lo resuelve por el otro lado —`organizations.phone` ya existe
 * y se captura en la pantalla de ajustes—, porque RB-8 quiere un alta **sin
 * fricción** y el teléfono pertenece al perfil del negocio, no a la puerta de
 * entrada. Un dato que el formulario pide y el sistema tira es peor que no
 * pedirlo; uno que el DTO declara y nadie usa, también.
 */
final readonly class RegisterOrganizationData
{
    /**
     * @param  string  $organizationName  nombre del negocio, tal como lo escribió
     * @param  string  $organizationSlug  slug pedido; vacío si se deriva del nombre
     * @param  string  $ownerName  nombre de la persona dueña
     * @param  string  $ownerEmail  correo del `owner`, único en toda la plataforma (RB-3)
     * @param  string  $ownerPassword  en claro; el modelo la cifra al guardar
     */
    public function __construct(
        public string $organizationName,
        public string $organizationSlug,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPassword,
    ) {}

    /**
     * Slug pedido, o el derivado del nombre si no se pidió ninguno.
     *
     * Devuelve cadena vacía cuando el nombre no produce ningún slug —un nombre
     * de solo emojis o de solo signos—, y ese vacío es información: SP-7 exige
     * entonces un slug escrito a mano, porque la alternativa sería inventarle
     * al negocio una URL que no se parece a su nombre.
     */
    public function preferredSlug(): string
    {
        return $this->organizationSlug !== ''
            ? Str::slug($this->organizationSlug)
            : Str::slug($this->organizationName);
    }

    /**
     * Correo normalizado.
     *
     * En minúsculas porque `users.email` es único en toda la plataforma
     * (RB-3): sin normalizar, `Ana@…` y `ana@…` serían dos cuentas de equipo
     * distintas y el login dejaría de ser inequívoco.
     */
    public function normalisedEmail(): string
    {
        return Str::lower(trim($this->ownerEmail));
    }
}
