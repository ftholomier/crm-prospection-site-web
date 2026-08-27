<?php
declare(strict_types=1);

namespace App;

/**
 * Persistance JSON sur disque. Écritures atomiques (fichier temporaire puis
 * rename) et verrou exclusif pour les cycles lecture-modification-écriture,
 * afin que le cron et l'interface ne se marchent pas dessus.
 */
final class Store
{
    /** Crée l'arborescence de données si elle n'existe pas encore. */
    public static function ensureLayout(): void
    {
        foreach ([DATA_DIR, DATA_DIR . '/prospects', DATA_DIR . '/mockups', DATA_DIR . '/logs', DATA_DIR . '/locks'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $guard = DATA_DIR . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        }
        $index = DATA_DIR . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    /** Lit un fichier JSON et retourne un tableau (ou le défaut). */
    public static function read(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    /** Écrit un tableau en JSON de manière atomique. */
    public static function write(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Lecture-modification-écriture sous verrou exclusif.
     * Le callback reçoit les données courantes et retourne les données à écrire.
     */
    public static function mutate(string $path, callable $mutator, array $default = []): array
    {
        $lock = self::lock(basename($path));
        try {
            $data = self::read($path, $default);
            $updated = $mutator($data);
            if (!is_array($updated)) {
                $updated = $data;
            }
            self::write($path, $updated);
            return $updated;
        } finally {
            self::unlock($lock);
        }
    }

    /** Ajoute une ligne à un fichier JSONL (journal append-only). */
    public static function append(string $path, array $row): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Parcourt un fichier JSONL des lignes les plus récentes aux plus anciennes. */
    public static function tail(string $path, int $limit = 200, ?callable $filter = null): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $row = json_decode($lines[$i], true);
            if (!is_array($row)) {
                continue;
            }
            if ($filter !== null && !$filter($row)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Ouvre un verrou nommé, en le créant au besoin. */
    public static function lock(string $name)
    {
        $file = DATA_DIR . '/locks/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) . '.lock';
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return null;
        }
        @flock($handle, LOCK_EX);
        return $handle;
    }

    /** Tente un verrou sans attendre ; retourne null si déjà pris. */
    public static function tryLock(string $name)
    {
        $file = DATA_DIR . '/locks/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) . '.lock';
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return null;
        }
        return $handle;
    }

    /** Relâche un verrou obtenu par lock()/tryLock(). */
    public static function unlock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** Supprime récursivement un dossier de données. */
    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
