<?php

declare(strict_types=1);

namespace App\Http\Middleware;

/**
 * Middleware que declara que su ruta está **fuera** del modelo de tenancy.
 *
 * Es la otra mitad de `DeclaresTenantSurface`, y existe por el mismo motivo
 * que aquel: la suite de fuga de `REQ-QAX-008` SP-1 se construye recorriendo
 * el router, y **toda ruta que no declare nada hace fallar el gate**. Sin una
 * forma de decir "aquí no hay tenant", la única manera de que el marketing o
 * el formulario de acceso pasaran el gate sería una lista de excepciones
 * escrita a mano en la suite —que es exactamente lo que SP-1 prohíbe— o
 * aflojar la regla hasta volverla decorativa.
 *
 * La afirmación no es "esta ruta no consulta nada": es "esta ruta no
 * pertenece a ninguna superficie de `REQ-ORG-002` RB-4". Si de ella cuelga
 * una consulta a una tabla del tenant, el global scope lanza
 * `MissingTenantContextException` en el primer intento, que es el fallo
 * ruidoso que se busca.
 */
interface DeclaresNoTenantSurface {}
