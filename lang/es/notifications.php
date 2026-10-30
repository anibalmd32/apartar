<?php

declare(strict_types=1);

return [
    'sender' => 'Apartar · en nombre de :negocio',
    'open_portal' => 'Ver mi apartado',
    'report_payment' => 'Reportar un pago',

    'installment_reminder' => [
        'title' => ':negocio · recordatorio de pago',
        'line_upcoming' => '«:product» (:code): la cuota :number de :amount vence el :date.',
        'line_due' => '«:product» (:code): la cuota :number de :amount vence hoy.',
        'line_overdue' => '«:product» (:code): la cuota :number de :amount venció el :date.',
    ],
    'down_payment_reminder' => [
        'title' => ':negocio · tu apartado vence pronto',
        'line' => 'Para confirmar «:product» (:code), paga la inicial de :amount antes del :date. Si no llega, el apartado se cancela.',
        'title_partial' => ':negocio · completa la inicial de tu apartado',
        'line_partial' => 'Ya abonaste :paid de la inicial de :amount de «:product» (:code). Tu apartado ya no vence: para activarlo, paga los :missing que faltan.',
    ],
    'layaway_activated' => [
        'title' => ':negocio · tu apartado está activo',
        'line' => 'Recibimos la inicial de «:product» (:code). Ya tienes tu cronograma de cuotas en el portal.',
        'line_without_down_payment' => '«:product» (:code) no lleva inicial: tu apartado ya está activo y lo pagas solo en cuotas. Tu cronograma está en el portal.',
    ],
    'payment_credited' => [
        'title' => ':negocio · acreditamos tu pago',
        'line' => 'Acreditamos :amount en «:product» (:code). Te queda por pagar :remaining.',
    ],
    'receipt_rejected' => [
        'title' => ':negocio · no pudimos aprobar tu comprobante',
        'line' => 'El comprobante de «:product» (:code) no se aprobó: :reason',
        'extended' => 'Tienes hasta el :date para pagar la inicial.',
    ],
    'layaway_completed' => [
        'title' => ':negocio · ¡pagaste tu apartado!',
        'line' => '«:product» (:code) está pagado por completo. El negocio te contactará para la entrega.',
    ],
    'layaway_cancelled' => [
        'title' => ':negocio · tu apartado fue cancelado',
        'line' => 'El negocio canceló el apartado de «:product» (:code). Escríbele si tienes dudas.',
    ],
    'layaway_expired' => [
        'title' => ':negocio · tu apartado venció',
        'line' => 'No llegó el pago de la inicial de «:product» (:code) a tiempo, y el apartado se canceló.',
    ],
    'layaway_delivered' => [
        'title' => ':negocio · entregado',
        'line' => '«:product» (:code) se marcó como entregado. ¡Que lo disfrutes!',
    ],
    'catalog_digest' => [
        'title' => ':negocio',
        'line' => '{1} 1 producto nuevo en «:catalog».|[2,*] :count productos nuevos en «:catalog».',
    ],
];
