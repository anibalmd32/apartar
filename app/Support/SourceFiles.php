<?php

declare(strict_types=1);

namespace App\Support;

final class SourceFiles
{
    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function in(string $directory, array $extensions = ['php']): array
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $pending = [$directory];

        while ($pending !== []) {
            $current = array_pop($pending);
            $entries = scandir($current);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current.'/'.$entry;

                if (is_dir($path)) {
                    if (! is_link($path)) {
                        $pending[] = $path;
                    }

                    continue;
                }

                if (in_array(pathinfo($entry, PATHINFO_EXTENSION), $extensions, true)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }
}
