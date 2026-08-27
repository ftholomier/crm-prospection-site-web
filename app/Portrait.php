<?php
declare(strict_types=1);

namespace App;

/**
 * Portrait affiché dans la section « Qui suis-je » de la page de proposition.
 *
 * Un visage rassure sur un message de prospection à froid : c'est la seule
 * preuve qu'il y a quelqu'un derrière la maquette. Le fichier vit hors de la
 * racine web et n'est servi que s'il est réellement décodable.
 */
final class Portrait
{
    private const MAX_BYTES = 3145728; // 3 Mo
    private const MAX_EDGE = 800;      // suffisant pour une vignette ronde

    public static function dir(): string
    {
        $dir = DATA_DIR . '/brand';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Chemin du fichier, sans contrôle de validité. */
    public static function rawPath(): ?string
    {
        foreach (array_column(Image::ACCEPTED, 1) as $extension) {
            $file = self::dir() . '/portrait.' . $extension;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /** Chemin du fichier, uniquement si les octets sont bien une image. */
    public static function path(): ?string
    {
        $path = self::rawPath();
        return ($path !== null && Image::probeFile($path) !== null) ? $path : null;
    }

    public static function exists(): bool
    {
        return self::path() !== null;
    }

    public static function mediaType(string $path): string
    {
        return Image::probeFile($path)['media_type'] ?? 'image/jpeg';
    }

    /** Enregistre le portrait envoyé depuis les Réglages. */
    public static function store(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Aucun fichier reçu.'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Image trop lourde (3 Mo maximum).'];
        }

        $binary = @file_get_contents($file['tmp_name']);
        if ($binary === false) {
            return ['ok' => false, 'error' => 'Fichier illisible.'];
        }

        $probe = Image::probe($binary);
        if ($probe === null) {
            return ['ok' => false, 'error' => 'Formats acceptés : JPEG, PNG, WebP et GIF.'];
        }

        // Une photo de 4 000 pixels n'apporte rien à une vignette ronde et
        // ralentirait la page vue par le prospect.
        if (max($probe['width'], $probe['height']) > self::MAX_EDGE) {
            $reduced = Image::downscale($binary, $probe, self::MAX_EDGE);
            if ($reduced !== null) {
                $binary = $reduced;
                $probe = Image::probe($binary) ?? $probe;
            }
        }

        self::clear();
        $path = self::dir() . '/portrait.' . $probe['extension'];
        if (@file_put_contents($path, $binary) === false) {
            return ['ok' => false, 'error' => 'Écriture impossible dans data/brand.'];
        }
        return ['ok' => true, 'error' => ''];
    }

    public static function clear(): void
    {
        foreach (array_column(Image::ACCEPTED, 1) as $extension) {
            @unlink(self::dir() . '/portrait.' . $extension);
        }
    }

    /** Initiales, affichées à la place de la photo quand il n'y en a pas. */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $letters !== '' ? $letters : '?';
    }
}
