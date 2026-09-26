<?php

declare(strict_types=1);

namespace App\Events\Layaway;

/**
 * Un estado vivo → `cancelled`: lo canceló el negocio (`REQ-LAY-009`); libera cupo (RB-6).
 */
final class LayawayCancelled extends LayawayTransitioned {}
