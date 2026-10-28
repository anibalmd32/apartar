<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class LegalController extends Controller
{
    public const UPDATED_ON = '2026-10-28';

    /**
     * @var array<string, array{title: string, file: string}>
     */
    private const DOCUMENTS = [
        'terms' => ['title' => 'Términos de servicio', 'file' => 'terms.md'],
        'privacy' => ['title' => 'Política de privacidad', 'file' => 'privacy.md'],
    ];

    public function terms(): Response
    {
        return $this->render('terms');
    }

    public function privacy(): Response
    {
        return $this->render('privacy');
    }

    private function render(string $key): Response
    {
        $document = self::DOCUMENTS[$key];
        $markdown = File::get(resource_path('markdown/'.$document['file']));

        return Inertia::render('marketing/Legal', ['document' => [
            'key' => $key,
            'title' => $document['title'],
            'updatedOn' => CarbonImmutable::parse(self::UPDATED_ON)->locale('es')->translatedFormat('j \d\e F \d\e Y'),
            'html' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]]);
    }
}
