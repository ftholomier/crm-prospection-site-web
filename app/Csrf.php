<?php
declare(strict_types=1);

namespace App;

/** Jeton anti-CSRF pour tous les formulaires de l'administration. */
final class Csrf
{
    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        Auth::start();
        $expected = (string) ($_SESSION['csrf'] ?? '');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /** Bloque la requête si le jeton est absent ou invalide. */
    public static function requireValid(): void
    {
        if (!self::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Session expirée. Rechargez la page et réessayez.');
        }
    }
}
