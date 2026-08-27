<?php
declare(strict_types=1);

namespace App;

/** Petites fonctions utilitaires partagées. */
final class Util
{
    /** Identifiant court non séquentiel. */
    public static function id(string $prefix = ''): string
    {
        return $prefix . base_convert((string) time(), 10, 36) . bin2hex(random_bytes(4));
    }

    /** Jeton opaque non devinable pour les liens publics. */
    public static function token(int $bytes = 16): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Normalise une URL saisie à la main (ajoute le schéma, retire le suivi). */
    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !str_contains($parts['host'], '.')) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        return $scheme . '://' . $host . $port . ($path === '' ? '/' : $path);
    }

    /** Domaine nu d'une URL (sans www.). */
    public static function domain(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $host) ?: $host;
    }

    /** Résout une URL relative par rapport à une URL de base. */
    public static function absoluteUrl(string $link, string $base): ?string
    {
        $link = trim($link);
        if ($link === '' || str_starts_with($link, '#')
            || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $link)) {
            return null;
        }
        if (preg_match('~^https?://~i', $link)) {
            return $link;
        }
        $parts = parse_url($base);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($link, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $link;
        }
        if (str_starts_with($link, '/')) {
            return $origin . $link;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $link;
    }

    /** Tronque proprement un texte sur une limite de mots. */
    public static function truncate(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space ? mb_substr($cut, 0, $space) : $cut) . '…';
    }

    /** Slug utilisable en nom de fichier. */
    public static function slug(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '');
        return trim($text, '-') ?: 'sans-nom';
    }

    /** Sépare un nom complet en prénom / nom. */
    public static function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full) ?? '');
        if ($full === '') {
            return ['', ''];
        }
        $parts = explode(' ', $full);
        $first = array_shift($parts);
        return [$first, implode(' ', $parts)];
    }

    /** Validation d'adresse email tolérante mais utile. */
    public static function isEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /** Détecte les emails inutilisables en prospection (no-reply, images…). */
    public static function isJunkEmail(string $email): bool
    {
        $email = strtolower($email);
        if (!self::isEmail($email)) {
            return true;
        }
        if (preg_match('/\.(png|jpe?g|gif|webp|svg|css|js)$/i', $email)) {
            return true;
        }
        return (bool) preg_match('/^(no-?reply|ne-?pas-?repondre|postmaster|abuse|mailer-daemon)@/i', $email);
    }

    /** Normalise un numéro de téléphone français pour l'affichage. */
    public static function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '33')) {
            $digits = '0' . substr($digits, 2);
        }
        if (strlen($digits) !== 10) {
            return trim($phone);
        }
        return trim(chunk_split($digits, 2, ' '));
    }

    /** Redirection HTTP puis arrêt. */
    public static function redirect(string $location): never
    {
        header('Location: ' . $location, true, 302);
        exit;
    }

    /** Clamp d'un entier dans un intervalle. */
    public static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
