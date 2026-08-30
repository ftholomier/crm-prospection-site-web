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
    public static function forPublic(string $html, array $links, string $inject = '', array $ressources = []): string
    {
        foreach ($links as $page => $target) {
            $target = htmlspecialchars((string) $target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            foreach ([$page . '.html', './' . $page . '.html', '/' . $page . '.html'] as $needle) {
                $html = str_replace(
                    ['href="' . $needle . '"', "href='" . $needle . "'"],
                    ['href="' . $target . '"', "href='" . $target . "'"],
                    $html
                );
            }
        }

        if (($ressources['palette'] ?? []) !== []) {
            $html = self::applyCharte($html, (array) $ressources['palette']);
        }
        // La ponctuation française se corrige ici, au moment de servir : le
        // modèle écrit du texte, pas de la typographie, et le résultat vaut
        // pour toutes les versions déjà produites sans rien régénérer.
        $html = self::typographie($html);
        $html = self::rewriteResources($html, $ressources);

        // Tout JavaScript est retiré, sauf le socle : c'est notre fichier, servi
        // depuis notre domaine, et sans lui l'en-tête collant et les apparitions
        // au défilement ne fonctionnent pas — la maquette perdrait la moitié de
        // ce qui la fait paraître moderne.
        $socle = (string) ($ressources['js'] ?? '');
        $html = preg_replace_callback(
            '~<script\b([^>]*)>(.*?)</script>~is',
            static function (array $m) use ($socle): string {
                $garde = $socle !== ''
                    && trim($m[2]) === ''
                    && str_contains($m[1], $socle);
                return $garde ? $m[0] : '';
            },
            $html
        ) ?? $html;
        $html = preg_replace('~\son[a-z]+\s*=\s*"[^"]*"~i', '', $html) ?? $html;
        $html = preg_replace("~\son[a-z]+\s*=\s*'[^']*'~i", '', $html) ?? $html;

        if ($inject !== '') {
            $html = self::injectBeforeBodyEnd($html, $inject);
        }
        return $html;
    }

    /**
     * Réécrit les adresses relatives de la maquette vers des URL servables.
     *
     * Les fichiers sont écrits une fois pour toutes avec des chemins relatifs
     * — socle.css, socle.js, assets/photo-01.jpg — ce qui les rend ouvrables
     * tels quels dans un navigateur. Au moment de les servir, ces chemins
     * deviennent des URL de notre domaine, éventuellement tokenisées.
     *
     * @param array{css?:string,js?:string,assets?:string} $ressources
     */
    public static function rewriteResources(string $html, array $ressources): string
    {
        foreach (['socle.css' => 'css', 'socle.js' => 'js'] as $fichier => $cle) {
            $cible = (string) ($ressources[$cle] ?? '');
            if ($cible === '') {
                continue;
            }
            // Les URL de repli portent des « & » : dans un attribut, ils
            // doivent être encodés.
            $cible = htmlspecialchars($cible, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            foreach ([$fichier, './' . $fichier] as $needle) {
                $html = str_replace(
                    ['="' . $needle . '"', "='" . $needle . "'"],
                    ['="' . $cible . '"', "='" . $cible . "'"],
                    $html
                );
            }
        }

        $base = (string) ($ressources['assets'] ?? '');
        if ($base !== '') {
            $html = preg_replace_callback(
                '~(src|href)=(["\'])\.?/?assets/([a-z0-9._-]+)\2~i',
                static fn (array $m): string => $m[1] . '=' . $m[2]
                    . htmlspecialchars(
                        str_replace('{f}', rawurlencode($m[3]), $base),
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) . $m[2],
                $html
            ) ?? $html;
        }
        return $html;
    }

    /**
     * URL des ressources d'une maquette, à passer à forPublic().
     *
     * Le socle est un fichier statique de notre domaine, servi tel quel ; les
     * actifs du prospect vivent hors de la racine web et passent par une route.
     * Le jeton {f} est laissé en place : rewriteResources() y met le nom du
     * fichier rencontré.
     */
    public static function resources(string $assetsBase): array
    {
        $base = rtrim(Config::baseUrl(), '/');
        return [
            'css' => $base . '/assets/maquette/socle.css',
            'js' => $base . '/assets/maquette/socle.js',
            'assets' => $assetsBase,
        ];
    }

    /** Les URL sont encodées : on rend au gabarit {f} sa forme lisible. */
    public static function assetPattern(string $url): string
    {
        return str_replace(['%7Bf%7D', '%7bf%7d'], '{f}', $url);
    }

    /**
     * Remplace la charte du document par celle en vigueur.
     *
     * La palette est écrite dans le fichier à la génération pour qu'il reste
     * ouvrable tel quel, mais elle n'y fait pas foi : c'est celle de la fiche
     * qui est servie. Une couleur corrigée s'applique donc immédiatement, sans
     * regénérer quoi que ce soit.
     */
    /**
     * Ponctuation française : les espaces qui ne doivent jamais couper.
     *
     * Le guillemet fermant se retrouvait seul sur sa ligne — « … se sent bien.
     * » — parce qu'une espace ordinaire le précédait. La règle française veut
     * une espace fine insécable avant » ! ? : ; et après « ; elle règle le
     * problème à la source plutôt que de bricoler la largeur du bloc.
     *
     * Seul le texte est touché : la réécriture saute tout ce qui se trouve
     * entre chevrons, donc les balises, les attributs et les adresses. Une
     * entité HTML n'a pas d'espace avant son point-virgule et reste donc
     * intacte, tout comme un « ? » d'adresse, qui n'en a pas non plus.
     */
    public static function typographie(string $html): string
    {
        // U+202F, espace fine insécable. Le point-virgule des entités HTML est
        // épargné : « &amp; » ne doit pas devenir « &amp ; ».
        return preg_replace_callback(
            '~>([^<]+)<~u',
            static function (array $m): string {
                $texte = $m[1];
                // Le point-virgule est traité avec les autres : une entité HTML
                // — &amp; &nbsp; — n'a jamais d'espace avant son point-virgule,
                // elle ne peut donc pas être touchée.
                $texte = preg_replace('~\s+([»!?:;])~u', "\u{202F}$1", $texte) ?? $texte;
                $texte = preg_replace('~«\s+~u', "«\u{202F}", $texte) ?? $texte;
                return '>' . $texte . '<';
            },
            $html
        ) ?? $html;
    }

    /** Dispositions de menu proposées, et leur libellé. */
    public const MENUS = [
        'lateral' => 'Panneau latéral (burger)',
        'horizontal' => 'Barre horizontale',
    ];

    /**
     * Impose la disposition de menu affichée d'emblée.
     *
     * Le balisage porte les deux menus quoi qu'il arrive ; seule la classe de
     * l'en-tête change. C'est ce qui permet au prospect de basculer sans
     * recharger, et à l'agence de choisir par quoi il commence.
     */
    public static function applyMenu(string $html, string $menu): string
    {
        if (!isset(self::MENUS[$menu])) {
            return $html;
        }
        $voulue = 'entete--' . $menu;
        return preg_replace_callback(
            '~(<header\b[^>]*\sclass=")([^"]*)(")~i',
            static function (array $m) use ($voulue): string {
                $classes = preg_split('~\s+~', trim($m[2])) ?: [];
                $classes = array_values(array_filter(
                    $classes,
                    static fn (string $c): bool => $c !== 'entete--lateral' && $c !== 'entete--horizontal'
                ));
                $classes[] = $voulue;
                return $m[1] . implode(' ', $classes) . $m[3];
            },
            $html,
            1
        ) ?? $html;
    }

    public static function applyCharte(string $html, array $palette): string
    {
        // La disposition de menu se pose ici, comme les couleurs : elle décide
        // seulement de ce que le prospect voit EN PREMIER — la bascule reste à
        // sa main. La figer à la génération obligerait à régénérer pour la
        // changer d'avis.
        $html = self::applyMenu($html, (string) ($palette['menu'] ?? ''));

        if (($palette['marque'] ?? '') === '') {
            return $html;
        }
        $bloc = Palette::rootBlock($palette, (string) ($palette['police'] ?? ''), '');
        $remplace = preg_replace(
            '~<style\s+data-charte[^>]*>.*?</style>~is',
            '<style data-charte>' . "\n" . $bloc . "\n" . '</style>',
            $html,
            1,
            $compte
        );
        if ($remplace !== null && $compte > 0) {
            return $remplace;
        }
        // Maquette produite avant le marquage : on pose la charte en fin de
        // <head>, où elle prend le pas sur le socle chargé au-dessus.
        $position = stripos($html, '</head>');
        $style = '<style data-charte>' . "\n" . $bloc . "\n" . '</style>' . "\n";
        return $position === false ? $html : substr($html, 0, $position) . $style . substr($html, $position);
    }

    /** Retire un éventuel bloc Markdown autour de la réponse du modèle. */
    public static function stripFence(string $raw): string
    {
        $html = trim($raw);
        if (preg_match('/```(?:html)?\s*(.+?)```/s', $html, $m)) {
            return trim($m[1]);
        }
        return $html;
    }

    /**
     * Contenu intérieur du <body>. Sert aussi bien à lire un gabarit qu'à
     * reprendre une page existante pour la faire retoucher.
     */
    public static function bodyOf(string $html): string
    {
        if (preg_match('~<body\b[^>]*>(.*)</body>~is', $html, $m)) {
            $corps = $m[1];
        } else {
            // Pas de <body> : le modèle a répondu avec le fragment attendu,
            // dont on retire seulement l'entête éventuelle.
            $corps = preg_replace('~<head\b.*?</head>~is', '', $html) ?? $html;
            $corps = preg_replace('~</?(?:!doctype|html|body)\b[^>]*>~i', '', $corps) ?? $corps;
        }
        $corps = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $corps) ?? $corps;
        return trim($corps);
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
        $corps = self::bodyOf($html);
        return [
            'size' => strlen($html),
            'responsive' => (bool) preg_match('/name=["\']viewport/i', $html),
            'socle' => str_contains($html, 'socle.css'),
            'palette' => (bool) preg_match('/--marque\s*:/', $html),
            // Le corps ne doit porter ni CSS ni JavaScript : tout vient du socle.
            'corps_propre' => !preg_match('/<(style|script)\b/i', $corps) && !preg_match('/\sstyle=/i', $corps),
            'nav_complete' => count(array_filter(
                array_keys(self::PAGES),
                static fn (string $page): bool => str_contains($html, $page . '.html')
            )) >= 2,
        ];
    }
}
