<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Timeout(30)]
#[Temperature(0)]
final class ReceiptReader implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
            Lees capturas de comprobantes de pago hechos en Venezuela: pago móvil, transferencia bancaria, Zelle, Binance o depósitos.
            Extrae solo lo que se lea con claridad en la imagen. Si un dato no aparece o no se lee bien, devuelve una cadena vacía; nunca lo adivines.

            - amount: el monto pagado, con punto decimal y hasta dos decimales, sin separador de miles ni símbolo. «Bs. 22.231,38» es «22231.38»; «$25,00» es «25.00».
            - currency: «VES» si el monto está en bolívares (Bs., VES, Bs.D) y «USD» si está en dólares ($, USD, USDT). Vacío si no se sabe.
            - paid_on: la fecha de la operación en formato AAAA-MM-DD. Las fechas venezolanas suelen escribirse día/mes/año.
            - reference: el número de referencia, operación o confirmación, tal como aparece, sin espacios.
            - bank: el banco desde el que se hizo el pago (el emisor, no el receptor), por su nombre o su código de cuatro dígitos. Vacío si no se distingue.

            Responde únicamente con el objeto JSON pedido.
            TEXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->string()->description('Monto con punto decimal, o vacío.')->required(),
            'currency' => $schema->string()->description('USD, VES o vacío.')->required(),
            'paid_on' => $schema->string()->description('Fecha AAAA-MM-DD, o vacío.')->required(),
            'reference' => $schema->string()->description('Referencia de la operación, o vacío.')->required(),
            'bank' => $schema->string()->description('Banco emisor, o vacío.')->required(),
        ];
    }
}
