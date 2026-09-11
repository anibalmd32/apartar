# `components/vendor` — primitivos heredados del starter kit

shadcn/Radix tal como llegó con `laravel/react-starter-kit`. **No es el sistema
de componentes del proyecto**: ese es daisyUI 5 en
[`../ui/`](../ui/README.md).

Están aquí y no allí porque `T-003` liberó `components/ui/` sin reescribir el
andamiaje de autenticación, que sigue funcionando desde este directorio.
`T-010` y `T-012` migran esas páginas a daisyUI al adaptarlas; según lo hagan,
este directorio se encoge hasta poder borrarse.

**No añadas nada aquí.** Un componente nuevo va en `../ui/`.
