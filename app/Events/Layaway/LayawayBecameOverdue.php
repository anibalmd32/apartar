<?php

declare(strict_types=1);

namespace App\Events\Layaway;

/**
 * `active` → `overdue`: venció una cuota y pasó la gracia (`REQ-LAY-007`).
 */
final class LayawayBecameOverdue extends LayawayTransitioned {}
