<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class SupportAccessRequiredException extends RuntimeException
{
    public const STATUS = Response::HTTP_FORBIDDEN;

    public const NO_GRANT = 'Para ver datos de este negocio hace falta un acceso de soporte vigente.';

    public const READ_ONLY = 'El acceso de soporte es de solo lectura: no se puede actuar en nombre del negocio.';

    public function __construct(string $message, public readonly ?string $organizationSlug = null)
    {
        parent::__construct($message);
    }

    public static function noGrant(Organization $organization): self
    {
        return new self(self::NO_GRANT, $organization->slug);
    }

    public static function readOnly(): self
    {
        return new self(self::READ_ONLY);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], self::STATUS);
        }

        return Inertia::render('platform/SupportRequired', [
            'message' => $this->getMessage(),
            'organizationUrl' => $this->organizationSlug === null ? null : route('platform.organizations.show', $this->organizationSlug),
        ])->toResponse($request)->setStatusCode(self::STATUS);
    }
}
