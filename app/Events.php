<?php
declare(strict_types=1);

namespace App;

/**
 * Journal d'activité append-only (data/events.jsonl). Sert au tableau de bord,
 * au fil d'activité et au calcul des statistiques.
 */
final class Events
{
    public const OPEN = 'email_open';
    public const CLICK = 'email_click';
    public const SENT = 'email_sent';
    public const VIEW = 'mockup_view';
    public const INTEREST = 'interest';
    public const UNSUB = 'unsubscribe';

    private static array $labels = [
        'prospect_created' => 'Prospect ajouté',
        'analyzed' => 'Site analysé',
        'mockup_generated' => 'Maquette générée',
        'mockup_revised' => 'Maquette retouchée',
        'mockup_validated' => 'Maquette validée',
        'sequence_started' => 'Séquence lancée',
        'sequence_stopped' => 'Séquence arrêtée',
        self::SENT => 'Email envoyé',
        self::OPEN => 'Email ouvert',
        self::CLICK => 'Lien cliqué',
        self::VIEW => 'Maquette consultée',
        self::INTEREST => 'Prospect intéressé',
        self::UNSUB => 'Désinscription',
        'status_changed' => 'Statut modifié',
        'login' => 'Connexion',
        'error' => 'Erreur',
    ];

    public static function path(): string
    {
        return DATA_DIR . '/events.jsonl';
    }

    public static function log(?string $prospectId, string $type, array $meta = []): void
    {
        Store::append(self::path(), [
            'ts' => time(),
            'prospect_id' => $prospectId,
            'type' => $type,
            'meta' => $meta,
        ]);
    }

    public static function label(string $type): string
    {
        return self::$labels[$type] ?? $type;
    }

    /** Derniers événements, éventuellement filtrés sur un prospect. */
    public static function recent(int $limit = 50, ?string $prospectId = null): array
    {
        return Store::tail(self::path(), $limit, $prospectId === null
            ? null
            : static fn (array $row): bool => ($row['prospect_id'] ?? null) === $prospectId);
    }

    /** Compte les événements par type depuis un horodatage donné. */
    public static function countsSince(int $since = 0): array
    {
        $counts = [];
        if (!is_file(self::path())) {
            return $counts;
        }
        $handle = @fopen(self::path(), 'r');
        if ($handle === false) {
            return $counts;
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row) || ($row['ts'] ?? 0) < $since) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        fclose($handle);
        return $counts;
    }

    /** Série journalière d'un type d'événement sur N jours (pour le graphique). */
    public static function dailySeries(array $types, int $days = 30): array
    {
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[date('Y-m-d', strtotime("-{$i} days"))] = array_fill_keys($types, 0);
        }
        $since = strtotime('-' . ($days - 1) . ' days midnight');
        if (!is_file(self::path())) {
            return $series;
        }
        $handle = @fopen(self::path(), 'r');
        if ($handle === false) {
            return $series;
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row) || ($row['ts'] ?? 0) < $since) {
                continue;
            }
            $day = date('Y-m-d', (int) $row['ts']);
            $type = (string) ($row['type'] ?? '');
            if (isset($series[$day][$type])) {
                $series[$day][$type]++;
            }
        }
        fclose($handle);
        return $series;
    }
}
