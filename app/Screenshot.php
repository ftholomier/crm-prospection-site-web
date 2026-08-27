<?php
declare(strict_types=1);

namespace App;

/**
 * Capture de la page d'accueil du prospect, pour le comparatif avant/après et
 * pour donner au modèle une vision réelle du site.
 *
 * Un hébergement mutualisé n'a pas de navigateur headless : la capture passe
 * donc par un service externe dont l'URL est configurable, ou par un import
 * manuel depuis la fiche prospect.
 */
final class Screenshot
{
    private const MAX_BYTES = 4194304; // 4 Mo, borne haute acceptée par l'API vision
    private const MAX_EDGE = 1568;     // au-delà, l'API réduit l'image de toute façon

    /** Formats d'image acceptés, à l'affichage comme à l'envoi à l'API. */
    private const ACCEPTED = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
    ];

    /** Fournisseurs proposés dans les réglages. {url} et {enc} sont substitués. */
    public const PROVIDERS = [
        'thumio' => [
            'label' => 'Thum.io (sans clé, gratuit)',
            'template' => 'https://image.thum.io/get/width/1280/crop/1000/noanimate/{url}',
            'needs_key' => false,
        ],
        'screenshotone' => [
            'label' => 'ScreenshotOne (clé requise)',
            'template' => 'https://api.screenshotone.com/take?access_key={key}&url={enc}&viewport_width=1280&viewport_height=1000&format=jpg&block_cookie_banners=true',
            'needs_key' => true,
        ],
        'apiflash' => [
            'label' => 'ApiFlash (clé requise)',
            'template' => 'https://api.apiflash.com/v1/urltoimage?access_key={key}&url={enc}&width=1280&height=1000&format=jpeg&response_type=image',
            'needs_key' => true,
        ],
        'custom' => [
            'label' => 'URL personnalisée',
            'template' => '',
            'needs_key' => false,
        ],
        'none' => [
            'label' => 'Désactivé (import manuel uniquement)',
            'template' => '',
            'needs_key' => false,
        ],
    ];

    public static function dir(string $prospectId): string
    {
        $dir = DATA_DIR . '/mockups/' . preg_replace('/[^a-z0-9]/i', '', $prospectId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function path(string $prospectId): ?string
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $file = self::dir($prospectId) . '/avant.' . $extension;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Une capture réellement affichable est-elle disponible ?
     *
     * La seule présence du fichier ne suffit pas : un service de capture peut
     * avoir renvoyé une page d'erreur, enregistrée telle quelle. Servir ces
     * octets produit une image cassée sur la page vue par le prospect.
     * Le contrôle ne lit que l'en-tête, il reste donc peu coûteux.
     */
    public static function exists(string $prospectId): bool
    {
        return self::usablePath($prospectId) !== null;
    }

    /** Chemin de la capture, uniquement si les octets sont bien une image. */
    public static function usablePath(string $prospectId): ?string
    {
        $path = self::path($prospectId);
        if ($path === null) {
            return null;
        }
        $info = @getimagesize($path);
        return ($info !== false && isset(self::ACCEPTED[$info[2]])) ? $path : null;
    }

    /** Le prospect a-t-il un fichier de capture inexploitable ? */
    public static function hasBrokenFile(string $prospectId): bool
    {
        return self::path($prospectId) !== null && self::usablePath($prospectId) === null;
    }

    /**
     * Supprime un fichier de capture inexploitable.
     * Appelé depuis l'administration : une page publique ne doit rien écrire.
     */
    public static function purgeIfInvalid(string $prospectId): bool
    {
        if (!self::hasBrokenFile($prospectId)) {
            return false;
        }
        self::clear($prospectId);
        return true;
    }

    public static function mediaType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** URL de capture construite depuis les réglages, ou null si désactivé. */
    public static function providerUrl(string $targetUrl): ?string
    {
        $provider = (string) Config::get('screenshot.provider', 'thumio');
        if ($provider === 'none') {
            return null;
        }
        $template = $provider === 'custom'
            ? (string) Config::get('screenshot.custom_template', '')
            : (self::PROVIDERS[$provider]['template'] ?? '');
        if (trim($template) === '') {
            return null;
        }
        $key = (string) Config::get('screenshot.api_key', '');
        if ((self::PROVIDERS[$provider]['needs_key'] ?? false) && $key === '') {
            return null;
        }
        return strtr($template, [
            '{url}' => $targetUrl,
            '{enc}' => rawurlencode($targetUrl),
            '{key}' => rawurlencode($key),
        ]);
    }

    /**
     * Récupère et stocke la capture. Silencieux en cas d'échec : la capture est
     * un bonus, elle ne doit jamais bloquer la génération d'une maquette.
     * @return array{ok:bool,error:string,path:?string}
     */
    public static function capture(string $prospectId, string $targetUrl): array
    {
        $source = self::providerUrl($targetUrl);
        if ($source === null) {
            return ['ok' => false, 'error' => 'Aucun service de capture configuré.', 'path' => null];
        }

        $response = Http::get($source, 45);
        if (!$response['ok'] || $response['body'] === '') {
            return ['ok' => false, 'error' => $response['error'] !== '' ? $response['error'] : 'Le service de capture a répondu ' . $response['status'], 'path' => null];
        }
        if ($response['size'] > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Capture trop volumineuse (' . Scraper::humanSize($response['size']) . ').', 'path' => null];
        }

        // L'en-tête Content-Type ne prouve rien : certains services renvoient une
        // page d'erreur annoncée comme image. Seuls les octets font foi.
        $probe = self::probe($response['body']);
        if ($probe === null) {
            $type = strtolower($response['headers']['content-type'] ?? '');
            return [
                'ok' => false,
                'error' => 'Le service n\'a pas renvoyé une image exploitable (' . ($type ?: 'type inconnu') . ').',
                'path' => null,
            ];
        }
        $extension = $probe['extension'];

        self::clear($prospectId);
        $path = self::dir($prospectId) . '/avant.' . $extension;
        if (@file_put_contents($path, $response['body']) === false) {
            return ['ok' => false, 'error' => 'Écriture impossible dans data/mockups.', 'path' => null];
        }
        return ['ok' => true, 'error' => '', 'path' => $path];
    }

    /** Enregistre une capture importée manuellement depuis la fiche prospect. */
    public static function storeUpload(string $prospectId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Aucun fichier reçu.'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Image trop lourde (4 Mo maximum).'];
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'error' => 'Ce fichier n\'est pas une image valide.'];
        }
        $extension = match ($info[2]) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_JPEG => 'jpg',
            default => null,
        };
        if ($extension === null) {
            return ['ok' => false, 'error' => 'Formats acceptés : JPEG, PNG, WebP.'];
        }
        self::clear($prospectId);
        $path = self::dir($prospectId) . '/avant.' . $extension;
        if (!@move_uploaded_file($file['tmp_name'], $path)) {
            return ['ok' => false, 'error' => 'Impossible d\'enregistrer le fichier.'];
        }
        return ['ok' => true, 'error' => ''];
    }

    public static function clear(string $prospectId): void
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            @unlink(self::dir($prospectId) . '/avant.' . $extension);
        }
    }

    /**
     * Identifie réellement des octets d'image.
     * @return array{media_type:string,extension:string,width:int,height:int}|null
     */
    private static function probe(string $binary): ?array
    {
        if ($binary === '' || !function_exists('getimagesizefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || !isset(self::ACCEPTED[$info[2]])) {
            return null;
        }

        // L'en-tête seul ne suffit pas : un fichier tronqué l'annonce
        // correctement mais n'a plus de pixels, et l'API le refuse. On décode
        // donc réellement l'image quand GD est disponible.
        if (function_exists('imagecreatefromstring')) {
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

    /**
     * Bloc image prêt à être joint à une requête Messages API.
     *
     * Le type déclaré est déduit des octets, jamais de l'extension du fichier :
     * une incohérence entre les deux fait rejeter toute la requête. Les images
     * trop grandes sont réduites, l'API les redimensionnant de toute façon.
     */
    public static function toImageBlock(string $prospectId): ?array
    {
        $path = self::path($prospectId);
        if ($path === null) {
            return null;
        }
        $binary = @file_get_contents($path);
        if ($binary === false) {
            return null;
        }

        $probe = self::probe($binary);
        if ($probe === null) {
            return null;
        }

        $longEdge = max($probe['width'], $probe['height']);
        if ($longEdge > self::MAX_EDGE) {
            $reduced = self::downscale($binary, $probe);
            if ($reduced === null) {
                return null;
            }
            $binary = $reduced;
            $probe = self::probe($binary) ?? $probe;
        }

        // La limite porte sur la charge encodée, qui pèse un tiers de plus.
        if ((int) (strlen($binary) * 4 / 3) > self::MAX_BYTES) {
            return null;
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $probe['media_type'],
                'data' => base64_encode($binary),
            ],
        ];
    }

    /** Réduit une image trop grande, si GD est disponible. */
    private static function downscale(string $binary, array $probe): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
            return null;
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }
        $ratio = self::MAX_EDGE / max($probe['width'], $probe['height']);
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
