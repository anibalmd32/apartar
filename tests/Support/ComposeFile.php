<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Lector de los archivos de `docker compose` del proyecto.
 *
 * No es un parser de YAML: lee el subconjunto que usan `docker-compose.yml` y
 * `docker-compose.override.yml`, que son archivos nuestros y de forma fija
 * (secciones de primer nivel, servicios con dos espacios de sangría y el
 * texto crudo de cada bloque). Evita añadir una dependencia solo para los
 * tests de infraestructura.
 */
final class ComposeFile
{
    /**
     * @param  array<string, array<string, string>>  $sections  sección → clave → bloque crudo
     */
    private function __construct(private readonly array $sections) {}

    /**
     * Lee un archivo de compose de la raíz del proyecto.
     */
    public static function read(string $path): self
    {
        $contents = file_get_contents(base_path($path));

        if ($contents === false) {
            throw new \RuntimeException("No se pudo leer {$path}.");
        }

        return new self(self::parse($contents));
    }

    /**
     * Une el archivo base con el override, como hace `docker compose` sin -f.
     */
    public static function merged(): self
    {
        $base = self::read('docker-compose.yml')->sections;
        $override = self::read('docker-compose.override.yml')->sections;

        foreach ($override as $section => $entries) {
            foreach ($entries as $key => $block) {
                $base[$section][$key] = ($base[$section][$key] ?? '')."\n".$block;
            }
        }

        return new self($base);
    }

    /**
     * Nombres de las claves de una sección de primer nivel.
     *
     * @return list<string>
     */
    public function keys(string $section): array
    {
        return array_keys($this->sections[$section] ?? []);
    }

    /**
     * Texto crudo de una clave dentro de una sección.
     */
    public function block(string $section, string $key): string
    {
        return $this->sections[$section][$key] ?? '';
    }

    /**
     * Texto crudo de un servicio, con el override ya aplicado si procede.
     */
    public function service(string $name): string
    {
        return $this->block('services', $name);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function parse(string $contents): array
    {
        $sections = [];
        $section = null;
        $key = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^([a-z_][a-z0-9_-]*):\s*$/', $line, $matches) === 1) {
                $section = $matches[1];
                $sections[$section] ??= [];
                $key = null;

                continue;
            }

            if ($section === null) {
                continue;
            }

            if (preg_match('/^ {2}([a-z_][a-z0-9_-]*):\s*$/', $line, $matches) === 1) {
                $key = $matches[1];
                $sections[$section][$key] ??= '';

                continue;
            }

            if ($key !== null) {
                $sections[$section][$key] .= $line."\n";
            }
        }

        return $sections;
    }
}
