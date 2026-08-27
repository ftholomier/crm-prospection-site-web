<?php
declare(strict_types=1);

namespace App;

/**
 * Authentification à mot de passe unique : session PHP, jeton CSRF et
 * limitation des tentatives pour bloquer le bruteforce.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 8;
    private const LOCK_SECONDS = 900;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_name('prospectstudio');
        session_start();
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['authenticated']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            Util::redirect(Router::url('login'));
        }
    }

    /** Vérifie le mot de passe et ouvre la session. */
    public static function attempt(string $password): bool
    {
        self::start();
        if (self::isLocked()) {
            return false;
        }
        $hash = (string) Config::get('app.password_hash', '');
        if ($hash === '' || !password_verify($password, $hash)) {
            self::recordFailure();
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['login_at'] = time();
        unset($_SESSION['failures'], $_SESSION['locked_until']);
        Events::log(null, 'login', []);
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    /** Définit le mot de passe initial lors de l'installation. */
    public static function install(string $password): bool
    {
        if (Config::isInstalled() || strlen($password) < 8) {
            return false;
        }
        Config::set('app.password_hash', password_hash($password, PASSWORD_DEFAULT));
        self::start();
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        return true;
    }

    /** Change le mot de passe depuis les réglages. */
    public static function changePassword(string $current, string $new): bool
    {
        if (strlen($new) < 8) {
            return false;
        }
        $hash = (string) Config::get('app.password_hash', '');
        if ($hash !== '' && !password_verify($current, $hash)) {
            return false;
        }
        Config::set('app.password_hash', password_hash($new, PASSWORD_DEFAULT));
        return true;
    }

    public static function isLocked(): bool
    {
        self::start();
        return isset($_SESSION['locked_until']) && $_SESSION['locked_until'] > time();
    }

    public static function lockedFor(): int
    {
        self::start();
        return max(0, (int) ($_SESSION['locked_until'] ?? 0) - time());
    }

    private static function recordFailure(): void
    {
        $_SESSION['failures'] = (int) ($_SESSION['failures'] ?? 0) + 1;
        if ($_SESSION['failures'] >= self::MAX_ATTEMPTS) {
            $_SESSION['locked_until'] = time() + self::LOCK_SECONDS;
            $_SESSION['failures'] = 0;
        }
    }
}
