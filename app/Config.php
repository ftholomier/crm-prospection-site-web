<?php
declare(strict_types=1);

namespace App;

/**
 * Configuration applicative : valeurs par défaut fusionnées avec
 * data/config.json. Toutes les clés sont éditables depuis l'écran Réglages.
 */
final class Config
{
    /**
     * Empreintes des prompts de design livrés par défaut dans les versions
     * précédentes. Servent uniquement à reconnaître un réglage jamais modifié.
     */
    private const PROMPTS_OBSOLETES = [
        'ed6c9071564c1c368488ec83915fe371',
    ];

    private static array $data = [];
    private static bool $loaded = false;

    public static function path(): string
    {
        return DATA_DIR . '/config.json';
    }

    public static function load(bool $force = false): void
    {
        if (self::$loaded && !$force) {
            return;
        }
        $stored = Store::read(self::path());
        self::$data = self::mergeDeep(self::defaults(), $stored);
        self::$data['design']['global_prompt'] = self::migrateDesignPrompt(
            (string) (self::$data['design']['global_prompt'] ?? '')
        );
        self::$loaded = true;
    }

    /**
     * Le prompt de design est modifiable dans les Réglages, donc conservé tel
     * quel — sauf s'il est resté sur un ancien défaut. Ces textes décrivaient
     * une maquette écrite de zéro ; ils contrediraient aujourd'hui le socle,
     * qui impose sa feuille de style. Un prompt personnalisé, lui, n'est jamais
     * touché : seuls les défauts connus mot pour mot sont remplacés.
     */
    private static function migrateDesignPrompt(string $prompt): string
    {
        $empreinte = md5(trim($prompt));
        return in_array($empreinte, self::PROMPTS_OBSOLETES, true) || trim($prompt) === ''
            ? self::defaultDesignPrompt()
            : $prompt;
    }

    public static function all(): array
    {
        self::load();
        return self::$data;
    }

    /** Lecture par chemin pointé : Config::get('smtp.host'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        self::load();
        $node = self::$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    /** Écriture par chemin pointé, persistée immédiatement. */
    public static function set(string $path, mixed $value): bool
    {
        self::load();
        $keys = explode('.', $path);
        $node = &self::$data;
        foreach ($keys as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        $node = $value;
        unset($node);
        return self::save();
    }

    /**
     * Fusionne un tableau partiel dans la configuration puis persiste.
     *
     * Le résultat de l'écriture est rendu, et non plus jeté : sur un
     * hébergement où data/ n'est pas inscriptible, l'application annonçait
     * « Réglages enregistrés » sans rien avoir écrit — on cherchait ensuite la
     * panne du côté de la clé API alors qu'aucune n'avait jamais été stockée.
     */
    public static function merge(array $patch): bool
    {
        self::load();
        self::$data = self::mergeDeep(self::$data, $patch);
        return self::save();
    }

    public static function save(): bool
    {
        return Store::write(self::path(), self::$data);
    }

    /**
     * Pourquoi la configuration ne peut-elle pas être écrite ?
     *
     * Rendue en clair, la cause évite de chercher au mauvais endroit : c'est
     * presque toujours un dossier déposé par FTP sous un compte différent de
     * celui qui exécute PHP.
     */
    public static function raisonEcritureImpossible(): string
    {
        $fichier = self::path();
        $dossier = dirname($fichier);

        if (!is_dir($dossier)) {
            return 'le dossier ' . basename($dossier) . '/ n\'existe pas';
        }
        if (!is_writable($dossier)) {
            $proprietaire = function_exists('posix_getpwuid') && function_exists('fileowner')
                ? (string) (posix_getpwuid((int) @fileowner($dossier))['name'] ?? '?')
                : '?';
            $courant = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                : (string) (get_current_user() ?: '?');
            return 'le dossier ' . basename($dossier) . '/ n\'est pas inscriptible'
                . ' (droits ' . substr(sprintf('%o', @fileperms($dossier) ?: 0), -4)
                . ', propriétaire « ' . $proprietaire . ' », PHP s\'exécute sous « ' . $courant . ' »)';
        }
        if (is_file($fichier) && !is_writable($fichier)) {
            return 'le fichier ' . basename($fichier) . ' n\'est pas inscriptible'
                . ' (droits ' . substr(sprintf('%o', @fileperms($fichier) ?: 0), -4) . ')';
        }
        if (function_exists('disk_free_space') && @disk_free_space($dossier) === 0.0) {
            return 'le disque est plein';
        }
        return 'cause inconnue — vérifiez les droits du dossier data/ et les journaux d\'erreur PHP';
    }

    /** L'application est-elle initialisée (mot de passe défini) ? */
    public static function isInstalled(): bool
    {
        return (string) self::get('app.password_hash', '') !== '';
    }

    /** URL publique de base, devinée depuis la requête si non configurée. */
    public static function baseUrl(): string
    {
        $configured = trim((string) self::get('app.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return ($https ? 'https://' : 'http://') . $host . $dir;
    }

    private static function mergeDeep(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public static function defaults(): array
    {
        return [
            'app' => [
                'name' => 'Prospect Studio',
                'base_url' => '',
                'pretty_urls' => true,
                'email' => '',
                'password_hash' => '',
                'password_changed_at' => 0,
                'timezone' => 'Europe/Paris',
                'signature' => '',
                'cron_key' => '',
            ],
            // Fournisseur d'IA : « claude », « deepseek » ou « gemini ».
            'ai' => [
                'provider' => 'claude',
                // Un modèle par étape. Le brief décide de ce qu'on garde et de
                // ce qu'on s'interdit d'inventer : c'est là qu'un modèle faible
                // coûte cher en crédibilité. Les pages ne font que remplir un
                // gabarit, et pèsent l'essentiel des jetons. Laisser vide pour
                // suivre le fournisseur principal.
                'steps' => [
                    'brief' => ['provider' => '', 'model' => ''],
                    'pages' => ['provider' => '', 'model' => ''],
                ],
            ],
            'deepseek' => [
                'api_key' => '',
                'base_url' => '',
                'model' => 'deepseek-v4-flash',
                'max_tokens' => 8000,
                'timeout' => 600,
                // Tarifs corrigés à la main, par modèle :
                // [entrée creuse, sortie creuse, entrée pleine, sortie pleine].
                // Vides, ceux livrés avec l'application servent.
                'tarifs' => [],
            ],
            'gemini' => [
                'api_key' => '',
                'base_url' => '',
                'model' => 'gemini-2.5-flash-lite',
                'max_tokens' => 8000,
                'timeout' => 600,
            ],
            'claude' => [
                'api_key' => '',
                'model' => 'claude-opus-5',
                'effort' => 'high',
                'max_tokens' => 24000,
                'timeout' => 600,
            ],
            'design' => [
                'global_prompt' => self::defaultDesignPrompt(),
                'allow_google_fonts' => true,
                'use_site_images' => true,
                // « copie » : les images sont recopiées chez nous, la maquette
                // survit à une refonte du site d'origine. « liens » : on garde
                // les adresses distantes, rien n'est stocké.
                'assets_mode' => 'copie',
            ],
            // Les API facturent en dollars. Le taux est saisi ici plutôt que
            // deviné : un taux inventé rendrait faux ce qu'on cherche à rendre
            // juste. Zéro veut dire « n'affiche pas d'euros ».
            'billing' => [
                'eur_rate' => 0.0,
                'rate_note' => '',
            ],
            'offer' => [
                'monthly_price' => 79,
                'currency' => '€',
                'included' => [
                    'Toutes les pages de votre site, pas seulement ces trois-là',
                    'Reprise de vos contenus, textes et photos existants',
                    'Hébergement et sauvegardes quotidiennes',
                    'Mises à jour techniques et de sécurité',
                    'Modifications de contenu illimitées, à la demande',
                ],
            ],
            'smtp' => [
                'host' => '',
                'port' => 587,
                'security' => 'tls',
                'user' => '',
                'pass' => '',
                'from_email' => '',
                'from_name' => '',
                'reply_to' => '',
                'verify_peer' => true,
            ],
            'sequence' => [
                'enabled' => true,
                'delays_days' => [0, 4, 8],
                'daily_limit' => 40,
                'send_days' => [1, 2, 3, 4, 5],
                'send_from' => '09:00',
                'send_to' => '18:00',
                'min_gap_seconds' => 120,
                'stop_on_click' => true,
                'stop_on_view' => false,
            ],
            'enrichment' => [
                'mode' => 'site',
                'pappers_api_key' => '',
            ],
            'screenshot' => [
                'provider' => 'thumio',
                'api_key' => '',
                'custom_template' => '',
                'auto' => true,
                'send_to_model' => true,
            ],
            'about' => [
                'enabled' => true,
                'title' => 'Qui suis-je',
                'name' => 'Frédéric Tholomier',
                'role' => 'Fondateur de LE-DIGITAL.com — expert webmarketing depuis 27 ans',
                'bio' => "Je m'appelle Frédéric Tholomier. Je suis fondateur de LE-DIGITAL.com et je travaille "
                    . "dans le digital depuis 27 ans : formation, conseil et prestations pour des chefs "
                    . "d'entreprise — plusieurs centaines accompagnés à ce jour — entre Besançon, Montbéliard "
                    . "et Belfort, et partout en France.\n\n"
                    . "Les pages que vous venez de voir, c'est moi qui les ai faites, à partir de votre activité "
                    . "et de vos prestations. Pas un modèle acheté, pas une agence à qui je sous-traite : vous "
                    . "parlez directement à la personne qui conçoit votre site et qui l'entretiendra.",
                'quote' => 'Le digital provoque la conversation. La conversation provoque la conversion.',
                'points' => [
                    '27 ans de métier dans le digital',
                    'Des centaines de chefs d\'entreprise accompagnés',
                    'Besançon, Montbéliard, Belfort — et partout en France',
                ],
                'phone' => '',
                'whatsapp' => '',
                'zone' => '',
                'site_url' => 'https://le-digital.com',
                'site_label' => 'le-digital.com',
            ],
            'alerts' => [
                'email' => '',
                'on_interest' => true,
                'on_view' => false,
            ],
            'audit' => [
                'min_score_to_prospect' => 40,
            ],
            'scraper' => [
                'user_agent' => '',
                'retry_blocked' => true,
            ],
            'batch' => [
                'auto_analyze' => true,
                'auto_generate' => false,
                'per_run' => 3,
            ],
        ];
    }

    public static function defaultDesignPrompt(): string
    {
        return <<<TXT
Tu remplis un socle de maquettage existant pour convaincre un dirigeant de TPE/PME que son site actuel est dépassé.

LE SOCLE COMMANDE, PAS TOI
- La feuille de style socle.css est déjà écrite et déjà chargée. Tu n'écris aucun CSS : ni balise <style>, ni attribut style=, ni classe nouvelle.
- Tu n'écris aucun JavaScript. Les animations d'apparition et l'en-tête collant sont déjà gérés par le socle.
- Tu produis uniquement le contenu intérieur du <body>. L'en-tête du document, la police et la palette sont assemblés hors de ta réponse.
- La palette est calculée à partir des couleurs relevées sur le site du prospect, avec les contrastes vérifiés. Ne propose ni couleur ni police, et n'écris jamais une couleur en dur : les jetons var(--marque), var(--encre) et les autres sont déjà appliqués par les classes.
- Tu pars du gabarit fourni : mêmes classes, mêmes composants, même ordre de sections. C'est la structure du projet, pas une suggestion.

CE QUE TU CHANGES
- Les textes, les photos (src et alt), les liens, les coordonnées. Rien d'autre.
- La navigation entre les trois pages utilise exactement ces liens relatifs : accueil.html, a-propos.html, prestations.html, avec aria-current="page" sur la page courante.
- Les photos sont celles fournies dans photos_disponibles, à leur adresse exacte. Respecte l'orientation indiquée : une photo portrait ne va pas dans un bandeau panoramique.

CONTENU
- Conserve les informations réelles de l'entreprise : nom, ville, téléphone, métier, prestations effectivement proposées.
- N'invente jamais un chiffre, un tarif, un témoignage, un avis, une récompense, une certification ni une référence client.
- Si une section du gabarit n'a pas de matière réelle, SUPPRIME-LA entièrement. Une page plus courte se signe ; une page remplie de vide se repère au premier coup d'œil.
- Réécris les textes dans un français clair et orienté bénéfice client, sans jargon marketing creux. Le titrage nomme le métier et le territoire.
- Chaque page garde le même en-tête, le même pied de page et au moins un appel à l'action.
TXT;
    }
}