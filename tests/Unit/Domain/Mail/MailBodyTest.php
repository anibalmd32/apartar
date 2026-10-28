<?php

declare(strict_types=1);

use App\Domain\Mail\MailBody;

it('conserva el formato del editor y quita lo peligroso', function (): void {
    $html = '<p class="x" onclick="robar()">Hola <strong>Ana</strong></p><script>alert(1)</script>'
        .'<ul><li><p>Uno</p></li></ul><p>Mira <a href="javascript:robar()">esto</a><img src="x" onerror="y()"></p>';

    expect(MailBody::sanitize($html))->toBe('<p>Hola <strong>Ana</strong></p><ul><li><p>Uno</p></li></ul><p>Mira esto</p>');
});

it('deja vacío un cuerpo sin texto', function (string $html): void {
    expect(MailBody::sanitize($html))->toBe('');
})->with(['', '<p></p>', '<p><br></p>', '<script>x</script>']);

it('saca el texto plano del cuerpo escrito', function (): void {
    expect(MailBody::plainText('<p>Hola</p><p>Ana &amp; Luis</p>'))->toBe("Hola\n\nAna & Luis");
});

it('prefiere el texto del correo y si no lo hay lo saca del HTML', function (): void {
    expect(MailBody::textOf(' Hola ', '<p>otro</p>'))->toBe('Hola')
        ->and(MailBody::textOf(null, '<style>p{}</style><p>Hola</p><div>Ana<br>Luis</div>'))->toBe("Hola\nAna\nLuis")
        ->and(MailBody::textOf('', null))->toBe('');
});

it('cita el original escapado en HTML y en texto', function (): void {
    $html = MailBody::quoteHtml('El 28 de octubre, ana@example.com escribió:', "Hola <b>\nadiós");

    expect($html)->toContain('<p>El 28 de octubre, ana@example.com escribió:</p>')
        ->toContain('<blockquote')
        ->toContain('Hola &lt;b&gt;<br>')
        ->not->toContain('<b>');

    expect(MailBody::quoteText('Ana escribió:', "Hola\nadiós"))->toBe("Ana escribió:\n> Hola\n> adiós");
});
