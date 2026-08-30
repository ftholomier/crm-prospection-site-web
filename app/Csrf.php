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

    /**
     * Bloque la requête si le jeton est absent ou invalide.
     *
     * Un cas se déguise en jeton manquant et n'en est pas un : un envoi plus
     * gros que post_max_size arrive avec un $_POST entièrement vide, jeton
     * compris. PHP ne lève rien, et l'utilisateur lit « session expirée » alors
     * que sa session est parfaitement valide — il vient simplement de coller
     * une page de 3 Mo. Le cas se reconnaît à un corps annoncé mais non
     * analysé, et il se dit en clair.
     */
    public static function requireValid(): void
    {
        if (self::verify($_POST['_csrf'] ?? null)) {
            return;
        }

        $annonce = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($_POST === [] && $annonce > 0) {
            $limite = (string) ini_get('post_max_size');
            http_response_code(413);
            exit('Envoi refusé : ' . Scraper::humanSize($annonce) . ' reçus, au-delà de ce que votre '
                . 'hébergement accepte (post_max_size = ' . $limite . '). Le contenu n\'a pas été lu. '
                . 'Collez une page à la fois, ou faites relever cette limite par votre hébergeur.');
        }

        http_response_code(419);
        exit('Session expirée. Rechargez la page et réessayez.');
    }
}
