<?php
declare(strict_types=1);

namespace App;

/**
 * Configuration applicative : valeurs par défaut fusionnées avec
 * data/config.json. Toutes les clés sont éditables depuis l'écran Réglages.
 */
final class Config
{
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
        self::$loaded = true;
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
    public static function set(string $path, mixed $value): void
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
        self::save();
    }

    /** Fusionne un tableau partiel dans la configuration puis persiste. */
    public static function merge(array $patch): void
    {
        self::load();
        self::$data = self::mergeDeep(self::$data, $patch);
        self::save();
    }

    public static function save(): void
    {
        Store::write(self::path(), self::$data);
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
            ],
            'offer' => [
                'monthly_price' => 79,
                'currency' => '€',
                'included' => [
                    'Hébergement haute performance',
                    'Sauvegardes quotidiennes',
                    'Mises à jour techniques et sécurité',
                    'Mises à jour de contenu illimitées',
                    'Sans engagement, résiliable à tout moment',
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
            'alerts' => [
                'email' => '',
                'on_interest' => true,
                'on_view' => false,
            ],
            'audit' => [
                'min_score_to_prospect' => 40,
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
Tu conçois une maquette de refonte destinée à convaincre un dirigeant de TPE/PME que son site actuel est dépassé.

RÈGLES TECHNIQUES ABSOLUES
- Uniquement du HTML5 et du CSS3. Aucun JavaScript, aucun framework, aucune bibliothèque.
- Tout le CSS dans une unique balise <style> placée dans le <head>. Pas de fichier CSS séparé.
- Seules ressources externes autorisées : les polices Google Fonts et les photos existantes du site d'origine (via leur URL absolue). Rien d'autre.
- Pour toute illustration non photographique, utilise des dégradés CSS, des formes CSS ou du SVG inline.
- Responsive impeccable : conception mobile-first, grilles flexibles, points de rupture à 900px et 600px.
- Accessibilité : contrastes conformes, hiérarchie de titres cohérente, attributs alt renseignés, texte courant à 16px minimum.
- La navigation entre les trois pages utilise exactement ces liens relatifs : accueil.html, a-propos.html, prestations.html.

DIRECTION ARTISTIQUE
- Interface moderne et épurée, espaces blancs généreux, typographie soignée et hiérarchisée.
- Respecte l'univers du site d'origine : même secteur, même ton, couleurs de marque reprises si elles sont identifiables, même nom d'entreprise.
- Le résultat doit ressembler à un site professionnel conçu en 2026, jamais à un modèle générique.

CONTENU
- Conserve les informations réelles de l'entreprise : nom, ville, téléphone, métier, prestations effectivement proposées.
- N'invente jamais de chiffres, de tarifs, de témoignages clients, de récompenses ni de références.
- Réécris les textes dans un français clair et orienté bénéfice client, sans jargon marketing creux.
- Chaque page comporte la même navigation, un pied de page cohérent et au moins un appel à l'action.
TXT;
    }
}