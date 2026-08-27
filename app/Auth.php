<?php
declare(strict_types=1);

namespace App;

/**
 * Authentification du back-office : identifiant (l'adresse email) et mot de
 * passe, avec récupération par email.
 *
 * Les tentatives échouées sont comptées côté serveur, par adresse IP, dans
 * data/auth.json : un compteur en session serait contourné en supprimant le
 * cookie. Le jeton de réinitialisation n'est jamais stocké en clair, il est
 * conservé haché comme un mot de passe.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 8;
    private const LOCK_SECONDS = 900;

    private const RESET_TTL = 3600;
    private const RESET_MIN_INTERVAL = 120;
    private const RESET_MAX_PER_HOUR = 5;

    public static function storePath(): string
    {
        return DATA_DIR . '/auth.json';
    }

    // ------------------------------------------------------------- Session

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
        if (empty($_SESSION['authenticated'])) {
            return false;
        }
        // Un changement de mot de passe invalide les sessions ouvertes avant lui,
        // y compris sur les autres appareils.
        $changedAt = (int) Config::get('app.password_changed_at', 0);
        if ($changedAt > 0 && (int) ($_SESSION['login_at'] ?? 0) < $changedAt) {
            self::logout();
            return false;
        }
        return true;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            Util::redirect(Router::url('login'));
        }
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    private static function openSession(): void
    {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['login_at'] = time();
    }

    // ---------------------------------------------------------- Connexion

    /** Identifiant de connexion configuré (adresse email). */
    public static function identifier(): string
    {
        return strtolower(trim((string) Config::get('app.email', '')));
    }

    /**
     * Vérifie identifiant et mot de passe, puis ouvre la session.
     * L'identifiant est comparé sans tenir compte de la casse.
     */
    public static function attempt(string $identifier, string $password): bool
    {
        self::start();
        if (self::isLocked()) {
            return false;
        }

        $hash = (string) Config::get('app.password_hash', '');
        if ($hash === '') {
            return false;
        }

        $expected = self::identifier();
        $given = strtolower(trim($identifier));
        // Un compte créé avant l'ajout de l'identifiant n'en a pas encore :
        // on n'exige alors que le mot de passe, et les Réglages réclament
        // l'adresse manquante.
        $identifierOk = $expected === '' || hash_equals($expected, $given);

        if (!$identifierOk || !password_verify($password, $hash)) {
            self::recordFailure();
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Config::set('app.password_hash', password_hash($password, PASSWORD_DEFAULT));
        }

        self::openSession();
        self::clearFailures();
        Events::log(null, 'login', []);
        return true;
    }

    /** Création du compte au premier lancement. */
    public static function install(string $email, string $password): bool
    {
        if (Config::isInstalled() || !Util::isEmail($email) || strlen($password) < 8) {
            return false;
        }
        Config::merge(['app' => [
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at' => time(),
        ]]);
        self::start();
        self::openSession();
        return true;
    }

    /** Changement de mot de passe depuis les Réglages. */
    public static function changePassword(string $current, string $new): bool
    {
        if (strlen($new) < 8) {
            return false;
        }
        $hash = (string) Config::get('app.password_hash', '');
        if ($hash !== '' && !password_verify($current, $hash)) {
            return false;
        }
        Config::merge(['app' => [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
            'password_changed_at' => time(),
        ]]);
        // La session courante reste valide : c'est elle qui vient d'agir.
        self::start();
        $_SESSION['login_at'] = time();
        return true;
    }

    // ------------------------------------------------- Tentatives échouées

    /** Clé de comptage : l'adresse IP, hachée pour ne pas la stocker en clair. */
    private static function clientKey(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'inconnu');
        return substr(hash('sha256', $ip), 0, 16);
    }

    public static function isLocked(): bool
    {
        return self::lockedFor() > 0;
    }

    public static function lockedFor(): int
    {
        $store = Store::read(self::storePath());
        $entry = $store['attempts'][self::clientKey()] ?? null;
        if (!is_array($entry)) {
            return 0;
        }
        return max(0, (int) ($entry['until'] ?? 0) - time());
    }

    private static function recordFailure(): void
    {
        $key = self::clientKey();
        Store::mutate(self::storePath(), static function (array $store) use ($key): array {
            $entry = $store['attempts'][$key] ?? ['count' => 0, 'until' => 0];
            $entry['count'] = (int) $entry['count'] + 1;
            $entry['last'] = time();
            if ($entry['count'] >= self::MAX_ATTEMPTS) {
                $entry['until'] = time() + self::LOCK_SECONDS;
                $entry['count'] = 0;
            }
            $store['attempts'][$key] = $entry;

            // Purge des entrées inactives depuis plus d'une journée.
            foreach ($store['attempts'] as $otherKey => $other) {
                if ((int) ($other['last'] ?? 0) < time() - 86400 && (int) ($other['until'] ?? 0) < time()) {
                    unset($store['attempts'][$otherKey]);
                }
            }
            return $store;
        });
    }

    private static function clearFailures(): void
    {
        $key = self::clientKey();
        Store::mutate(self::storePath(), static function (array $store) use ($key): array {
            unset($store['attempts'][$key]);
            return $store;
        });
    }

    // ------------------------------------------------- Mot de passe perdu

    /** La récupération par email est-elle possible ? */
    public static function canSendReset(): bool
    {
        return trim((string) Config::get('smtp.host', '')) !== ''
            && trim((string) Config::get('smtp.from_email', '')) !== ''
            && self::identifier() !== '';
    }

    /**
     * Demande de réinitialisation.
     *
     * La réponse est volontairement identique que l'identifiant existe ou non,
     * et qu'un email soit parti ou non : rien ne doit permettre de confirmer
     * une adresse depuis l'extérieur. Les échecs réels sont journalisés.
     */
    public static function requestReset(string $identifier): array
    {
        $neutral = [
            'ok' => true,
            'message' => "Si cette adresse est bien celle du compte, un lien de réinitialisation vient d'être envoyé. Vérifiez votre boîte, y compris les indésirables.",
        ];

        $given = strtolower(trim($identifier));
        if ($given === '' || $given !== self::identifier()) {
            return $neutral;
        }

        $store = Store::read(self::storePath());
        $reset = $store['reset'] ?? [];
        $now = time();

        if ($now - (int) ($reset['requested_at'] ?? 0) < self::RESET_MIN_INTERVAL) {
            return $neutral;
        }
        $hourStart = (int) ($reset['hour_start'] ?? 0);
        $countHour = $now - $hourStart < 3600 ? (int) ($reset['count_hour'] ?? 0) : 0;
        if ($countHour >= self::RESET_MAX_PER_HOUR) {
            return $neutral;
        }

        $token = Util::token(32);
        Store::mutate(self::storePath(), static function (array $store) use ($token, $now, $countHour, $hourStart): array {
            $store['reset'] = [
                'hash' => hash('sha256', $token),
                'expires' => $now + self::RESET_TTL,
                'requested_at' => $now,
                'count_hour' => $countHour + 1,
                'hour_start' => $now - $hourStart < 3600 ? $hourStart : $now,
            ];
            return $store;
        });

        $sent = self::sendResetEmail($token);
        if (!$sent['ok']) {
            Store::append(DATA_DIR . '/logs/auth.jsonl', [
                'ts' => $now,
                'event' => 'reset_email_failed',
                'error' => $sent['error'],
            ]);
        }
        return $neutral;
    }

    private static function sendResetEmail(string $token): array
    {
        if (!self::canSendReset()) {
            return ['ok' => false, 'error' => 'SMTP non configuré ou identifiant absent.'];
        }

        $link = Config::baseUrl() . '/' . Router::url('reset', ['t' => $token]);
        $appName = (string) Config::get('app.name', 'Prospect Studio');
        $minutes = (int) (self::RESET_TTL / 60);

        $html = '<p>Vous avez demandé à réinitialiser le mot de passe de <strong>' . e($appName) . '</strong>.</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;background:#2563eb;color:#ffffff;'
            . 'text-decoration:none;padding:13px 24px;border-radius:8px;font-weight:600">Choisir un nouveau mot de passe</a></p>'
            . '<p>Ce lien est valable ' . $minutes . ' minutes et ne fonctionne qu\'une seule fois.</p>'
            . '<p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.</p>'
            . '<p style="color:#64748b;font-size:13px">Lien complet : ' . e($link) . '</p>';

        $text = "Vous avez demandé à réinitialiser le mot de passe de {$appName}.\n\n"
            . "Ouvrez ce lien pour choisir un nouveau mot de passe :\n{$link}\n\n"
            . "Ce lien est valable {$minutes} minutes et ne fonctionne qu'une seule fois.\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.";

        return Mailer::deliver(self::identifier(), '', 'Réinitialisation de votre mot de passe', $html, $text);
    }

    /** Le jeton fourni est-il valide et non expiré ? */
    public static function resetTokenIsValid(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $reset = Store::read(self::storePath())['reset'] ?? [];
        $hash = (string) ($reset['hash'] ?? '');
        if ($hash === '' || (int) ($reset['expires'] ?? 0) < time()) {
            return false;
        }
        return hash_equals($hash, hash('sha256', $token));
    }

    /**
     * Applique le nouveau mot de passe et consomme le jeton.
     * Les sessions ouvertes ailleurs sont invalidées.
     */
    public static function completeReset(string $token, string $password): bool
    {
        if (!self::resetTokenIsValid($token) || strlen($password) < 8) {
            return false;
        }

        Config::merge(['app' => [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at' => time(),
        ]]);

        Store::mutate(self::storePath(), static function (array $store): array {
            unset($store['reset']);
            $store['attempts'] = [];
            return $store;
        });

        Store::append(DATA_DIR . '/logs/auth.jsonl', ['ts' => time(), 'event' => 'password_reset']);

        self::start();
        self::openSession();
        return true;
    }
}
