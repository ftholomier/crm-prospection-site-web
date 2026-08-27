<?php
declare(strict_types=1);

namespace App;

/** Agrégats du tableau de bord, calculés à la volée depuis l'index et le journal. */
final class Stats
{
    public static function dashboard(): array
    {
        $index = Prospect::index();
        $byStatus = array_fill_keys(array_keys(Prospect::PIPELINE), 0);
        $totals = [
            'prospects' => count($index),
            'analyzed' => 0,
            'with_mockup' => 0,
            'validated' => 0,
            'mailable' => 0,
            'in_sequence' => 0,
            'interested' => 0,
            'customers' => 0,
            'score_sum' => 0,
            'score_count' => 0,
            'revenue' => 0.0,
        ];

        foreach ($index as $row) {
            $status = (string) ($row['status'] ?? Prospect::NEW);
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            if ($row['score'] !== null) {
                $totals['analyzed']++;
                $totals['score_sum'] += (int) $row['score'];
                $totals['score_count']++;
            }
            if (!empty($row['has_mockup'])) {
                $totals['with_mockup']++;
            }
            if (!empty($row['validated'])) {
                $totals['validated']++;
            }
            if (Util::isEmail((string) ($row['email'] ?? '')) && !Suppression::has((string) $row['email'])) {
                $totals['mailable']++;
            }
            if (!empty($row['sequence']['active'])) {
                $totals['in_sequence']++;
            }
            if ($status === Prospect::INTERESTED) {
                $totals['interested']++;
            }
            if ($status === Prospect::CUSTOMER) {
                $totals['customers']++;
                $totals['revenue'] += (float) ($row['monthly_price'] ?? 0);
            }
        }

        $sends = Tracking::stats();
        $totals['avg_score'] = $totals['score_count'] > 0
            ? (int) round($totals['score_sum'] / $totals['score_count'])
            : 0;

        return [
            'totals' => $totals,
            'by_status' => $byStatus,
            'sends' => $sends,
            'rates' => [
                'open' => self::rate($sends['opened'], $sends['sent']),
                'click' => self::rate($sends['clicked'], $sends['sent']),
                'interest' => self::rate($totals['interested'], max(1, $totals['validated'])),
            ],
            'today' => Tracking::sentToday(),
            'daily_limit' => (int) Config::get('sequence.daily_limit', 40),
            'series' => Events::dailySeries([Events::SENT, Events::OPEN, Events::CLICK, Events::VIEW], 30),
            'upcoming' => self::upcoming(),
        ];
    }

    /** Prochains envois programmés, triés par échéance. */
    public static function upcoming(int $limit = 8): array
    {
        $rows = [];
        foreach (Prospect::index() as $row) {
            $sequence = $row['sequence'] ?? [];
            if (empty($sequence['active']) || empty($sequence['next_at'])) {
                continue;
            }
            $rows[] = [
                'id' => $row['id'],
                'name' => Prospect::displayName($row),
                'email' => $row['email'] ?? '',
                'step' => (int) ($sequence['step'] ?? 0) + 1,
                'next_at' => (int) $sequence['next_at'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['next_at'] <=> $b['next_at']);
        return array_slice($rows, 0, $limit);
    }

    public static function rate(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    /** Entonnoir de conversion, du prospect détecté au client. */
    public static function funnel(array $dashboard): array
    {
        $totals = $dashboard['totals'];
        $sends = $dashboard['sends'];
        return [
            ['label' => 'Prospects détectés', 'value' => $totals['prospects']],
            ['label' => 'Maquettes générées', 'value' => $totals['with_mockup']],
            ['label' => 'Emails envoyés', 'value' => $sends['sent']],
            ['label' => 'Emails ouverts', 'value' => $sends['opened']],
            ['label' => 'Maquettes consultées', 'value' => $sends['clicked']],
            ['label' => 'Prospects intéressés', 'value' => $totals['interested']],
            ['label' => 'Clients signés', 'value' => $totals['customers']],
        ];
    }
}
