<?php
declare(strict_types=1);

namespace App;

/**
 * Stockage et versionnage des maquettes.
 *
 * Chaque version vit dans data/mockups/{prospect}/v{n}/ sous forme de trois
 * fichiers HTML autonomes. Les fichiers restent ouvrables directement dans un
 * navigateur : la navigation interne est écrite en liens relatifs, réécrits à
 * la volée quand la maquette est servie au prospect via son lien sécurisé.
 */
final class Mockup
{
    public const PAGES = [
        'accueil' => 'Accueil',
        'a-propos' => 'À propos',
        'prestations' => 'Prestations',
    ];

    public static function dir(string $prospectId, ?string $version = null): string
    {
        $base = DATA_DIR . '/mockups/' . preg_replace('/[^a-z0-9]/i', '', $prospectId);
        return $version === null ? $base : $base . '/' . preg_replace('/[^a-z0-9]/i', '', $version);
    }

    public static function pagePath(string $prospectId, string $version, string $page): string
    {
        return self::dir($prospectId, $version) . '/' . self::safePage($page) . '.html';
    }

    public static function safePage(string $page): string
    {
        return array_key_exists($page, self::PAGES) ? $page : 'accueil';
    }

    /** Liste des versions présentes sur le disque, de la plus récente à la plus ancienne. */
    public static function versions(string $prospectId): array
    {
        $dirs = glob(self::dir($prospectId) . '/v*', GLOB_ONLYDIR) ?: [];
        $versions = array_map('basename', $dirs);
        usort($versions, static fn (string $a, string $b): int => (int) substr($b, 1) <=> (int) substr($a, 1));
        return $versions;
    }

    /** Nom de la prochaine version à créer. */
    public static function nextVersion(string $prospectId): string
    {
        $versions = self::versions($prospectId);
        $highest = 0;
        foreach ($versions as $version) {
            $highest = max($highest, (int) substr($version, 1));
        }
        return 'v' . ($highest + 1);
    }

    public static function writePage(string $prospectId, string $version, string $page, string $html): bool
    {
        $dir = self::dir($prospectId, $version);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return @file_put_contents(self::pagePath($prospectId, $version, $page), $html) !== false;
    }

    public static function readPage(string $prospectId, string $version, string $page): ?string
    {
        $path = self::pagePath($prospectId, $version, $page);
        if (!is_file($path)) {
            return null;
        }
        $html = @file_get_contents($path);
        return $html === false ? null : $html;
    }

    /** Une version est complète quand ses trois pages existent. */
    public static function isComplete(string $prospectId, string $version): bool
    {
        foreach (array_keys(self::PAGES) as $page) {
            if (!is_file(self::pagePath($prospectId, $version, $page))) {
                return false;
            }
        }
        return true;
    }

    public static function meta(string $prospectId, string $version): array
    {
        return Store::read(self::dir($prospectId, $version) . '/meta.json');
    }

    public static function writeMeta(string $prospectId, string $version, array $meta): void
    {
        Store::write(self::dir($prospectId, $version) . '/meta.json', $meta);
    }

    public static function deleteVersion(string $prospectId, string $version): void
    {
        Store::removeTree(self::dir($prospectId, $version));
    }

    /**
     * Prépare le HTML pour l'affichage public : réécrit la navigation interne
     * vers les URL tokenisées, neutralise les scripts éventuels et injecte la
     * barre avant/après ainsi que les appels à l'action suivis.
     */
    public static function forPublic(string $html, array $links, string $inject = ''): string
    {
        foreach ($links as $page => $target) {
            foreach ([$page . '.html', './' . $page . '.html', '/' . $page . '.html'] as $needle) {
                $html = str_replace(
                    ['href="' . $needle . '"', "href='" . $needle . "'"],
                    ['href="' . $target . '"', "href='" . $target . "'"],
                    $html
                );
            }
        }

        // Le modèle ne doit pas produire de JavaScript ; on le retire malgré tout,
        // puisque cette page est servie à un tiers depuis notre domaine.
        $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? $html;
        $html = preg_replace('~\son[a-z]+\s*=\s*"[^"]*"~i', '', $html) ?? $html;
        $html = preg_replace("~\son[a-z]+\s*=\s*'[^']*'~i", '', $html) ?? $html;

        if ($inject !== '') {
            $html = self::injectBeforeBodyEnd($html, $inject);
        }
        return $html;
    }

    /** Insère un fragment juste avant </body>, ou en fin de document à défaut. */
    public static function injectBeforeBodyEnd(string $html, string $fragment): string
    {
        $position = strripos($html, '</body>');
        if ($position === false) {
            return $html . $fragment;
        }
        return substr($html, 0, $position) . $fragment . substr($html, $position);
    }

    /**
     * Nettoie la sortie du modèle : retire les blocs Markdown éventuels et
     * garantit un document HTML complet.
     */
    public static function sanitizeOutput(string $raw): string
    {
        $html = trim($raw);
        if (preg_match('/```(?:html)?\s*(.+?)```/s', $html, $m)) {
            $html = trim($m[1]);
        }
        $start = stripos($html, '<!DOCTYPE');
        if ($start === false) {
            $start = stripos($html, '<html');
        }
        if ($start !== false && $start > 0) {
            $html = substr($html, $start);
        }
        $end = strripos($html, '</html>');
        if ($end !== false) {
            $html = substr($html, 0, $end + 7);
        }
        if (stripos($html, '<html') === false) {
            $html = "<!DOCTYPE html>\n<html lang=\"fr\">\n<head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"></head>\n<body>\n" . $html . "\n</body>\n</html>";
        }
        return $html;
    }

    /** Contrôles rapides de qualité affichés après génération. */
    public static function inspect(string $html): array
    {
        return [
            'size' => strlen($html),
            'responsive' => (bool) preg_match('/name=["\']viewport/i', $html),
            'has_style' => (bool) preg_match('/<style/i', $html),
            'has_media_query' => str_contains($html, '@media'),
            'no_script' => !preg_match('/<script/i', $html),
            'no_external' => !preg_match('~(src|href)=["\']https?://~i', preg_replace('~<a\b[^>]*>~i', '', $html) ?? $html),
            'nav_complete' => count(array_filter(
                array_keys(self::PAGES),
                static fn (string $page): bool => str_contains($html, $page . '.html')
            )) >= 2,
        ];
    }
}
