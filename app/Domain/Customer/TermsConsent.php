<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class TermsConsent
{
    public CarbonImmutable $acceptedAt;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(CarbonInterface $acceptedAt, public string $ipAddress)
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(sprintf(
                'La evidencia de consentimiento necesita la IP desde la que se aceptó, y «%s» no es una.',
                $ipAddress,
            ));
        }

        $this->acceptedAt = CarbonImmutable::instance($acceptedAt)->setTimezone(date_default_timezone_get());
    }

    public static function acceptedNowFrom(string $ipAddress): self
    {
        return new self(CarbonImmutable::now(), $ipAddress);
    }
}
