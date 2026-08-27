<?php
declare(strict_types=1);

namespace App;

/**
 * Contrôles d'image partagés par la capture du site et le portrait.
 *
 * Un fichier n'est jamais cru sur parole : son type vient de ses octets, et
 * une image tronquée annonce des dimensions correctes tout en n'ayant plus de
 * pixels. On la décode donc réellement quand GD est disponible.
 */
final class Image
{
    public const ACCEPTED = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
    ];

    /**
     * Identifie et valide des octets d'image.
     * @return array{media_type:string,extension:string,width:int,height:int}|null
     */
    public static function probe(string $binary, bool $deepCheck = true): ?array
    {
        if ($binary === '' || !function_exists('getimagesizefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || !isset(self::ACCEPTED[$info[2]])) {
            return null;
        }

        if ($deepCheck && function_exists('imagecreatefromstring')) {
            $decoded = @imagecreatefromstring($binary);
            if ($decoded === false) {
                return null;
            }
            $complete = imagesx($decoded) === (int) $info[0] && imagesy($decoded) === (int) $info[1];
            imagedestroy($decoded);
            if (!$complete) {
                return null;
            }
        }

        [$mediaType, $extension] = self::ACCEPTED[$info[2]];
        return [
            'media_type' => $mediaType,
            'extension' => $extension,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /** Contrôle allégé d'un fichier : lit l'en-tête seulement. */
    public static function probeFile(string $path): ?array
    {
        $info = @getimagesize($path);
        if ($info === false || !isset(self::ACCEPTED[$info[2]])) {
            return null;
        }
        [$mediaType, $extension] = self::ACCEPTED[$info[2]];
        return [
            'media_type' => $mediaType,
            'extension' => $extension,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /** Réduit une image au côté maximal demandé, si GD est disponible. */
    public static function downscale(string $binary, array $probe, int $maxEdge): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
            return null;
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }
        $ratio = $maxEdge / max($probe['width'], $probe['height']);
        $scaled = @imagescale($image, (int) round($probe['width'] * $ratio), (int) round($probe['height'] * $ratio));
        imagedestroy($image);
        if ($scaled === false) {
            return null;
        }

        ob_start();
        $ok = @imagejpeg($scaled, null, 82);
        $output = (string) ob_get_clean();
        imagedestroy($scaled);

        return $ok && $output !== '' ? $output : null;
    }
}
