<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Proveedor de usuarios del guard `web`, capaz de encontrarlos **antes** de
 * que exista organización activa.
 *
 * Es la consecuencia directa de poner `BelongsToOrganization` en `User`: el
 * global scope exige un tenant fijado, y en el login todavía no lo hay. El
 * orden real es el contrario —la sesión trae al usuario y del usuario sale la
 * organización (`REQ-ORG-002` RB-4)—, así que la autenticación tiene que
 * consultar `users` sin el filtro.
 *
 * No es un agujero en el aislamiento, y la razón está escrita en la propia
 * lista blanca: `users.email` es **único en toda la plataforma**
 * (`GlobalUniqueExceptions`), precisamente para que un correo resuelva a un
 * único usuario sin saber la organización. Buscar sin filtro no puede
 * devolver el usuario de otro negocio por accidente porque no hay dos.
 *
 * Lo que sigue filtrado es todo lo demás: `User::query()` en el backoffice
 * —la lista del equipo, la asignación de un rol— pasa por el scope como
 * cualquier otra tabla del tenant.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * Consulta base del proveedor, sin el filtro por organización.
     *
     * Cubre las tres vías por las que Laravel llega a un usuario:
     * `retrieveById()` desde la sesión, `retrieveByCredentials()` en el login
     * y `retrieveByToken()` en el "recordarme". El broker de recuperación de
     * contraseña usa este mismo proveedor.
     *
     * @template TModel of Model
     *
     * @param  TModel|null  $model
     * @return Builder<TModel>
     */
    protected function newModelQuery($model = null)
    {
        // Equivale a `User::withoutTenantScope()`, y se escribe con
        // `withoutGlobalScope()` porque el proveedor es genérico sobre el
        // modelo del guard: el scope local solo existe en los modelos que
        // llevan el trait, y aquí el tipo es `Model`. Quitar un scope que no
        // está registrado no hace nada, así que es seguro con cualquier otro.
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
