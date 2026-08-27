<?php
declare(strict_types=1);

namespace App;

/**
 * Dérivation de la palette d'une maquette à partir de la couleur de marque.
 *
 * C'est le point où les maquettes générées échouent le plus souvent : une
 * couleur de charte tient rarement 4,5:1 sur tous les fonds où on veut la
 * poser. Le calcul est donc fait ici, mécaniquement, plutôt que confié au
 * modèle qui l'estimerait à l'œil.
 *
 * La teinte et la saturation ne bougent jamais : seule la luminosité est
 * ajustée, si bien que le prospect reconnaît sa couleur.
 */
final class Palette
{
    /** Seuil WCAG AA pour le texte courant. */
    private const SEUIL = 4.5;

    /** Teinte de repli, utilisée seulement faute de mieux. */
    public const REPLI = '#2563eb';

    private const GOOGLE = 'https://fonts.googleapis.com/css2?family=';

    private const GENERIQUES = ['inherit', 'initial', 'unset', 'sans-serif', 'serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'var'];

    private const SYSTEME = ['arial', 'helvetica', 'helvetica neue', 'verdana', 'tahoma', 'georgia', 'times', 'times new roman', 'courier', 'courier new', 'segoe ui', 'roboto', '-apple-system', 'blinkmacsystemfont', 'ui-sans-serif'];

    /**
     * Familles Google Fonts que l'on accepte de charger. La liste est courte
     * volontairement : une adresse construite au hasard renverrait un 400 et
     * la maquette s'afficherait sans sa police.
     */
    private const GOOGLE_CONNUES = [
        'montserrat', 'lato', 'open sans', 'raleway', 'poppins', 'nunito', 'nunito sans',
        'inter', 'work sans', 'source sans pro', 'source sans 3', 'pt sans', 'rubik',
        'karla', 'mulish', 'manrope', 'barlow', 'oswald', 'quicksand', 'jost',
        'dm sans', 'outfit', 'figtree', 'plus jakarta sans', 'cabin', 'josefin sans',
        'playfair display', 'merriweather', 'lora', 'libre baskerville', 'cormorant garamond',
        'crimson text', 'pt serif', 'roboto slab', 'bitter', 'archivo', 'exo 2',
        'titillium web', 'ubuntu', 'fira sans', 'noto sans', 'heebo', 'assistant',
    ];

    public const FOND = '#ffffff';
    public const FOND_TEINTE = '#faf7f3';
    public const SOMBRE = '#2b2724';

    /**
     * Construit les jetons de couleur d'un prospect.
     * @return array{marque:string,fonce:string,texte:string,claire:string,voile:string,mesures:array}
     */
    public static function derive(string $brand): array
    {
        $marque = self::normalize($brand) ?? '#2563eb';

        $fonce = self::assombrirJusqua($marque, self::FOND, self::SEUIL);
        $texte = self::assombrirJusqua($marque, self::FOND_TEINTE, self::SEUIL);
        $claire = self::eclaircirJusqua($marque, self::SOMBRE, self::SEUIL);
        $voile = self::melanger($marque, self::FOND, 0.08);

        return [
            'marque' => $marque,
            'fonce' => $fonce,
            'texte' => $texte,
            'claire' => $claire,
            'voile' => $voile,
            'mesures' => [
                'fonce_sur_blanc' => round(self::contraste($fonce, self::FOND), 2),
                'texte_sur_teinte' => round(self::contraste($texte, self::FOND_TEINTE), 2),
                'claire_sur_sombre' => round(self::contraste($claire, self::SOMBRE), 2),
                'marque_sur_blanc' => round(self::contraste($marque, self::FOND), 2),
            ],
        ];
    }

    /**
     * Palette complète d'un prospect, déduite de son site.
     *
     * C'est ici que se joue la ressemblance : la maquette reprend la structure
     * du socle, mais ses couleurs et sa police viennent du site analysé. Quand
     * rien d'exploitable n'a été trouvé, on le dit plutôt que de faire passer
     * une teinte de repli pour la couleur du prospect.
     *
     * @return array{marque:string,fonce:string,texte:string,claire:string,voile:string,mesures:array,police:string,police_import:string,source:string,candidats:array}
     */
    public static function forAnalysis(array $analysis): array
    {
        $candidats = array_values(array_filter([
            ...(array) ($analysis['colors']['palette'] ?? []),
            (string) ($analysis['colors']['dominant'] ?? ''),
        ]));
        $choisie = self::pick($candidats);

        $palette = self::derive($choisie ?? self::REPLI);
        $police = self::police($analysis);

        return $palette + [
            'police' => $police['stack'],
            'police_import' => $police['import'],
            'police_nom' => $police['nom'],
            'source' => $choisie !== null ? 'site' : 'repli',
            'candidats' => array_slice($candidats, 0, 8),
        ];
    }

    /**
     * Police du prospect, ramenée à une pile utilisable.
     *
     * Une police relevée sur un site n'est pas forcément servable chez nous :
     * on ne charge que celles réellement présentes sur Google Fonts, et
     * uniquement si le réglage l'autorise. Sinon on garde le nom en tête de
     * pile — le navigateur du prospect l'a peut-être installée — avec un repli
     * système derrière, ce qui ne casse jamais.
     */
    private static function police(array $analysis): array
    {
        $systeme = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
        $defaut = ['nom' => 'Montserrat', 'stack' => '"Montserrat", ' . $systeme, 'import' => self::GOOGLE . 'Montserrat:wght@300;400;500;600&display=swap'];

        foreach ((array) ($analysis['fonts'] ?? []) as $brute) {
            $nom = trim((string) $brute, " \t\n\r\0\x0B\"'");
            if ($nom === '' || in_array(strtolower($nom), self::GENERIQUES, true)) {
                continue;
            }
            // Les familles système sont déjà dans la pile de repli : les
            // reprendre en tête n'apporterait rien.
            if (in_array(strtolower($nom), self::SYSTEME, true)) {
                continue;
            }
            $stack = '"' . str_replace('"', '', $nom) . '", ' . $systeme;
            $import = '';
            if (Config::get('design.allow_google_fonts', true) && in_array(strtolower($nom), self::GOOGLE_CONNUES, true)) {
                $import = self::GOOGLE . rawurlencode($nom) . ':wght@300;400;500;600&display=swap';
            }
            return ['nom' => $nom, 'stack' => $stack, 'import' => $import];
        }

        return Config::get('design.allow_google_fonts', true)
            ? $defaut
            : ['nom' => 'système', 'stack' => $systeme, 'import' => ''];
    }

    /**
     * Bloc :root à poser après le socle. Seuls les jetons qui changent d'un
     * prospect à l'autre sont réécrits : le reste du socle reste intact.
     */
    public static function rootBlock(array $palette, string $police = '', string $importPolice = ''): string
    {
        $m = $palette['mesures'];
        $police = $police !== '' ? $police : (string) ($palette['police'] ?? 'inherit');
        [$r, $v, $b] = self::toRgb($palette['marque']);
        $import = $importPolice !== '' ? $importPolice : (string) ($palette['police_import'] ?? '');

        $css = '';
        if ($import !== '') {
            $css .= "@import url('" . str_replace(["'", "\n"], '', $import) . "');\n";
        }
        return $css . ":root{\n"
            . "  --marque: {$palette['marque']};\n"
            . "  --marque-fonce: {$palette['fonce']};   /* {$m['fonce_sur_blanc']}:1 sur blanc */\n"
            . "  --marque-texte: {$palette['texte']};   /* {$m['texte_sur_teinte']}:1 sur le fond teinté */\n"
            . "  --marque-claire: {$palette['claire']}; /* {$m['claire_sur_sombre']}:1 sur le fond sombre */\n"
            . "  --marque-voile: {$palette['voile']};\n"
            . "  --police: {$police};\n"
            . "  --ombre-active: 0 4px 14px rgba({$r}, {$v}, {$b}, .16), 0 24px 60px rgba(43, 39, 36, .14);\n"
            . "}";
    }

    // ------------------------------------------------------- Dérivations

    /** Assombrit par pas de luminosité jusqu'à atteindre le seuil demandé. */
    private static function assombrirJusqua(string $hex, string $fond, float $seuil): string
    {
        [$h, $s, $l] = self::toHsl($hex);
        for ($i = 0; $i <= 100; $i++) {
            $candidat = self::fromHsl($h, $s, max(0.0, $l - $i / 100));
            if (self::contraste($candidat, $fond) >= $seuil) {
                return $candidat;
            }
        }
        return '#000000';
    }

    /** Éclaircit par pas de luminosité jusqu'à atteindre le seuil demandé. */
    private static function eclaircirJusqua(string $hex, string $fond, float $seuil): string
    {
        [$h, $s, $l] = self::toHsl($hex);
        for ($i = 0; $i <= 100; $i++) {
            $candidat = self::fromHsl($h, $s, min(1.0, $l + $i / 100));
            if (self::contraste($candidat, $fond) >= $seuil) {
                return $candidat;
            }
        }
        return '#ffffff';
    }

    /** Mélange deux couleurs, $ratio étant la part de la première. */
    public static function melanger(string $a, string $b, float $ratio): string
    {
        [$ra, $ga, $ba] = self::toRgb($a);
        [$rb, $gb, $bb] = self::toRgb($b);
        return sprintf(
            '#%02x%02x%02x',
            (int) round($ra * $ratio + $rb * (1 - $ratio)),
            (int) round($ga * $ratio + $gb * (1 - $ratio)),
            (int) round($ba * $ratio + $bb * (1 - $ratio))
        );
    }

    // ------------------------------------------------------- Mesures WCAG

    /** Rapport de contraste entre deux couleurs opaques. */
    public static function contraste(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return ($la > $lb ? ($la + 0.05) / ($lb + 0.05) : ($lb + 0.05) / ($la + 0.05));
    }

    /** Luminance relative, selon la définition WCAG. */
    public static function luminance(string $hex): float
    {
        $canal = static function (float $v): float {
            $v /= 255;
            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::toRgb($hex);
        return 0.2126 * $canal((float) $r) + 0.7152 * $canal((float) $g) + 0.0722 * $canal((float) $b);
    }

    // ------------------------------------------------------- Conversions

    /** Normalise « #abc », « abcdef », « #ABCDEF » en « #abcdef ». */
    public static function normalize(string $hex): ?string
    {
        $hex = strtolower(trim($hex));
        $hex = ltrim($hex, '#');
        if (preg_match('/^[0-9a-f]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return preg_match('/^[0-9a-f]{6}$/', $hex) ? '#' . $hex : null;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function toRgb(string $hex): array
    {
        $hex = self::normalize($hex) ?? '#000000';
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /** @return array{0:float,1:float,2:float} teinte 0-1, saturation 0-1, luminosité 0-1 */
    public static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, self::toRgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match (true) {
            $max === $r => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6,
            $max === $g => (($b - $r) / $d + 2) / 6,
            default => (($r - $g) / $d + 4) / 6,
        };
        return [$h, $s, $l];
    }

    public static function fromHsl(float $h, float $s, float $l): string
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255);
            return sprintf('#%02x%02x%02x', $v, $v, $v);
        }
        $canal = static function (float $p, float $q, float $t): float {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }
            return $p;
        };
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        return sprintf(
            '#%02x%02x%02x',
            (int) round($canal($p, $q, $h + 1 / 3) * 255),
            (int) round($canal($p, $q, $h) * 255),
            (int) round($canal($p, $q, $h - 1 / 3) * 255)
        );
    }

    /**
     * Choisit la couleur de marque la plus plausible parmi celles relevées
     * sur le site : on écarte les gris, qui ne sont jamais une couleur de
     * charte, et on préfère la plus saturée parmi les plus fréquentes.
     */
    public static function pick(array $candidates): ?string
    {
        $best = null;
        $bestScore = -1.0;
        foreach (array_slice($candidates, 0, 10) as $rang => $hex) {
            $normalise = self::normalize((string) $hex);
            if ($normalise === null) {
                continue;
            }
            [, $s, $l] = self::toHsl($normalise);
            // Une couleur de charte n'est ni un gris, ni un fond très clair :
            // les crèmes et beiges dominent souvent le CSS sans rien signer.
            if ($s < 0.20 || $l < 0.12 || $l > 0.72) {
                continue;
            }
            // La fréquence compte, mais une teinte franche et de luminosité
            // moyenne — celle d'un bouton ou d'un lien — l'emporte.
            $score = (10 - $rang) * 0.6 + $s * 6 + (1 - abs($l - 0.45)) * 4;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $normalise;
            }
        }
        return $best;
    }
}
