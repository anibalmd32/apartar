<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Account;

use App\Actions\Account\GoogleUserData;
use App\Domain\Account\UnverifiedGoogleEmailException;
use Laravel\Socialite\Two\User as GoogleUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleUserDataTest extends TestCase
{
    public function test_a_verified_profile_is_read_whole(): void
    {
        $data = GoogleUserData::fromSocialite($this->google(['email_verified' => true]));

        $this->assertSame('sub-1', $data->providerId);
        $this->assertSame('maria@gmail.test', $data->email);
        $this->assertSame('María Pérez', $data->displayName);
        $this->assertSame('https://lh3.googleusercontent.test/a/foto', $data->avatarUrl);
    }

    public function test_missing_name_and_avatar_have_defaults(): void
    {
        $data = GoogleUserData::fromSocialite($this->google(['email_verified' => true], name: '  ', avatar: null));

        $this->assertSame('maria@gmail.test', $data->displayName);
        $this->assertNull($data->avatarUrl);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    #[DataProvider('unverified')]
    public function test_an_unverified_email_is_rejected(array $raw): void
    {
        $this->expectException(UnverifiedGoogleEmailException::class);

        GoogleUserData::fromSocialite($this->google($raw));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unverified(): array
    {
        return [
            'ausente' => [[]],
            'falso' => [['email_verified' => false]],
            'cadena' => [['email_verified' => 'true']],
        ];
    }

    public function test_a_profile_without_sub_or_email_is_rejected(): void
    {
        foreach ([['', 'maria@gmail.test'], ['sub-1', '']] as [$sub, $email]) {
            try {
                GoogleUserData::fromSocialite($this->google(['email_verified' => true], sub: $sub, email: $email));
                $this->fail("Aceptó sub «{$sub}» y correo «{$email}».");
            } catch (UnverifiedGoogleEmailException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function google(
        array $raw,
        string $sub = 'sub-1',
        string $email = 'maria@gmail.test',
        string $name = 'María Pérez',
        ?string $avatar = 'https://lh3.googleusercontent.test/a/foto',
    ): GoogleUser {
        return (new GoogleUser)->setRaw($raw + ['sub' => $sub])->map([
            'id' => $sub,
            'email' => $email,
            'name' => $name,
            'avatar' => $avatar,
        ]);
    }
}
