<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Domain\Account\UnverifiedGoogleEmailException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

final readonly class GoogleUserData
{
    public function __construct(
        public string $providerId,
        public string $email,
        public string $displayName,
        public ?string $avatarUrl,
    ) {}

    /**
     * @throws UnverifiedGoogleEmailException
     */
    public static function fromSocialite(SocialiteUser $user): self
    {
        $providerId = (string) $user->getId();
        $email = trim((string) $user->getEmail());

        if ($providerId === '' || $email === '') {
            throw new UnverifiedGoogleEmailException('perfil sin sub o sin correo');
        }

        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];

        if (($raw['email_verified'] ?? null) !== true) {
            throw new UnverifiedGoogleEmailException('email_verified ausente o falso');
        }

        $name = trim((string) $user->getName());
        $avatar = trim((string) $user->getAvatar());

        return new self(
            providerId: $providerId,
            email: $email,
            displayName: $name !== '' ? $name : $email,
            avatarUrl: $avatar !== '' ? mb_substr($avatar, 0, 512) : null,
        );
    }
}
