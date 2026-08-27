<?php
declare(strict_types=1);

namespace App;

/** Messages éphémères affichés après une redirection. */
final class Flash
{
    public static function add(string $type, string $message): void
    {
        Auth::start();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    /** Récupère et vide la pile. */
    public static function pull(): array
    {
        Auth::start();
        $items = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $items;
    }
}
