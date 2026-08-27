<?php
declare(strict_types=1);

namespace App;

/**
 * Liste de suppression : désinscriptions, plaintes et adresses en échec.
 * Aucune adresse présente ici ne peut plus recevoir d'email, quel que soit
 * le prospect ou la séquence.
 */
final class Suppression
{
    public static function path(): string
    {
        return DATA_DIR . '/suppression.json';
    }

    private static function key(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function all(): array
    {
        return Store::read(self::path());
    }

    public static function has(string $email): bool
    {
        $email = self::key($email);
        return $email !== '' && isset(self::all()[$email]);
    }

    public static function add(string $email, string $reason = 'unsubscribe', ?string $prospectId = null): void
    {
        $email = self::key($email);
        if ($email === '') {
            return;
        }
        Store::mutate(self::path(), static function (array $list) use ($email, $reason, $prospectId): array {
            $list[$email] = [
                'email' => $email,
                'reason' => $reason,
                'prospect_id' => $prospectId,
                'at' => time(),
            ];
            return $list;
        });
    }

    public static function remove(string $email): void
    {
        $email = self::key($email);
        Store::mutate(self::path(), static function (array $list) use ($email): array {
            unset($list[$email]);
            return $list;
        });
    }
}
