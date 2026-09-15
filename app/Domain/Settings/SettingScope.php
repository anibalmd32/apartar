<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * A qué alcanza el cambio de un ajuste (`REQ-ORG-006` RB-4).
 *
 * RB-4 es la regla que más se incumple sin querer, y no porque se programe
 * mal: porque nadie se lo pregunta. **Los cambios afectan solo al futuro**,
 * salvo lo que por su naturaleza se reevalúa. Un ajuste que retroactivara
 * vencimientos cambiaría el estado de apartados que ya se le comunicaron al
 * cliente, y esa conversación no la arregla ningún despliegue.
 *
 * Por eso el alcance es un dato de la clave y no un párrafo en una pantalla:
 * RB-4 exige que **cada clave declare el suyo en la UI**, y con la frase
 * escrita aquí la pantalla no puede decir una cosa distinta de la que hace el
 * cálculo.
 */
enum SettingScope: string
{
    /**
     * Se compara en el momento de usarlo, así que el cambio rige desde la
     * siguiente operación.
     */
    case Immediate = 'immediate';

    /**
     * Se reevalúa en el recálculo diario: el cambio se nota mañana, sobre lo
     * que siga vivo.
     */
    case Daily = 'daily';

    /**
     * Se congela al crear. Lo ya creado conserva lo que tenía, porque su
     * fecha o su cronograma ya se comunicaron.
     */
    case FutureOnly = 'future_only';

    /**
     * Lo que se le dice a quien está a punto de guardar.
     */
    public function description(): string
    {
        return match ($this) {
            self::Immediate => 'Se aplica desde la siguiente operación.',
            self::Daily => 'Se reevalúa en el recálculo de mañana, sobre lo que siga vigente.',
            self::FutureOnly => 'Solo afecta a lo que se cree a partir de ahora: no mueve fechas ya fijadas.',
        };
    }
}
