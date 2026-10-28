<?php

declare(strict_types=1);

use App\Domain\Catalog\ProductDescription;

it('conserva el formato permitido', function (): void {
    $html = '<p>Taladro <strong>percutor</strong> de <em>800 W</em><br />con maletín</p><ul><li><p>Broca</p></li></ul><ol><li>Uno</li></ol>';

    expect(ProductDescription::sanitize($html))->toBe($html);
});

it('quita los scripts, los estilos y los atributos', function (): void {
    $html = '<p class="x" style="color:red" onclick="robar()">Hola <b onmouseover="x()">mundo</b></p>'
        .'<script>alert(1)</script><style>p{}</style><iframe src="https://x.test">y</iframe>';

    expect(ProductDescription::sanitize($html))->toBe('<p>Hola <b>mundo</b></p>');
});

it('quita las etiquetas no permitidas sin perder su texto', function (): void {
    $html = '<h2>Título</h2><p>Mira <a href="javascript:robar()">esto</a> <img src="x" onerror="robar()"><u>ya</u></p>';

    expect(ProductDescription::sanitize($html))->toBe('<p>Título</p><p>Mira esto ya</p>');
});

it('deja vacía una descripción sin texto', function (string $html): void {
    expect(ProductDescription::sanitize($html))->toBe('');
})->with(['', '   ', '<p></p>', '<p><br></p>', '<p>&nbsp;</p><p> </p>', '<ul><li><p></p></li></ul>', '<p></p><script>x</script>']);

it('convierte en párrafos el texto plano que llega sin etiquetas', function (): void {
    expect(ProductDescription::sanitize("Línea 1\nLínea 2\n\nOtro <párrafo> & más"))
        ->toBe('<p>Línea 1<br />Línea 2</p><p>Otro &lt;párrafo&gt; &amp; más</p>');
});

it('lleva a HTML la descripción antigua en texto plano escapándola', function (): void {
    expect(ProductDescription::toHtml("a < b\nc\n\n\n<script>d</script>"))
        ->toBe('<p>a &lt; b<br />c</p><p>&lt;script&gt;d&lt;/script&gt;</p>');
});

it('deja intacta la descripción que ya es HTML y vacía la que no tiene nada', function (): void {
    expect(ProductDescription::toHtml('<p>Hola <strong>mundo</strong></p>'))->toBe('<p>Hola <strong>mundo</strong></p>');
    expect(ProductDescription::toHtml('   '))->toBe('');
});

it('vuelve a limpiar al leer el HTML guardado fuera del formulario', function (): void {
    expect(ProductDescription::toHtml('<p>Hola</p><script>robar()</script>'))->toBe('<p>Hola</p>');
});

it('saca el texto plano de la descripción guardada', function (): void {
    $html = '<p>Intro &amp; más</p><ul><li><p>uno</p></li><li><p>dos</p></li></ul><p>Fin<br>abajo&nbsp;</p>';

    expect(ProductDescription::plainText($html))->toBe("Intro & más\n\nuno\ndos\n\nFin\nabajo");
    expect(ProductDescription::plainText("  Texto antiguo\n  "))->toBe('Texto antiguo');
    expect(ProductDescription::plainText('<p></p>'))->toBe('');
});

it('cuenta los caracteres del texto sin los saltos de línea', function (): void {
    expect(ProductDescription::length('<p>ab</p><p>c<br>d</p>'))->toBe(4);
    expect(ProductDescription::length('<p><strong>ñandú</strong></p>'))->toBe(5);
    expect(ProductDescription::length("ab\ncd"))->toBe(4);
});
