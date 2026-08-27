<?php
declare(strict_types=1);

namespace App;

/**
 * Moteur de la séquence de relance, entièrement automatique.
 *
 * Le cron appelle runDue() : la séquence avance seule, dans la fenêtre horaire
 * et les jours autorisés, sous plafond quotidien, et s'arrête d'elle-même dès
 * qu'un signal d'engagement ou de refus est détecté.
 */
final class Sequence
{
    public const LAST_STEP = 3;

    /** Démarre la séquence pour un prospect. */
    public static function start(string $prospectId): array
    {
        $prospect = Prospect::find($prospectId);
        if ($prospect === null) {
            return ['ok' => false, 'error' => 'Prospect introuvable.'];
        }
        if (empty($prospect['mockup']['validated'])) {
            return ['ok' => false, 'error' => 'Validez d\'abord la maquette.'];
        }
        if (!Prospect::isMailable($prospect)) {
            return ['ok' => false, 'error' => 'Adresse email manquante, invalide ou désinscrite.'];
        }

        Prospect::update($prospectId, static function (array $p): array {
            $p['sequence'] = [
                'active' => true,
                'step' => 0,
                'next_at' => self::nextSlot(time()),
                'sent' => $p['sequence']['sent'] ?? [],
                'stopped_reason' => '',
                'started_at' => time(),
            ];
            $p['status'] = Prospect::SEQUENCE;
            return $p;
        });
        Events::log($prospectId, 'sequence_started', []);
        return ['ok' => true, 'error' => ''];
    }

    /** Arrête la séquence en conservant la raison. */
    public static function stop(string $prospectId, string $reason): void
    {
        $updated = Prospect::update($prospectId, static function (array $p) use ($reason): array {
            if (empty($p['sequence']['active'])) {
                return $p;
            }
            $p['sequence']['active'] = false;
            $p['sequence']['next_at'] = null;
            $p['sequence']['stopped_reason'] = $reason;
            return $p;
        });
        if ($updated !== null) {
            Events::log($prospectId, 'sequence_stopped', ['reason' => $reason]);
        }
    }

    /** Prospects dont la prochaine étape est échue. */
    public static function due(int $now = 0): array
    {
        $now = $now ?: time();
        $due = [];
        foreach (Prospect::index() as $row) {
            $sequence = $row['sequence'] ?? [];
            if (empty($sequence['active']) || empty($sequence['next_at'])) {
                continue;
            }
            if ((int) $sequence['next_at'] <= $now) {
                $due[] = $row;
            }
        }
        usort($due, static fn (array $a, array $b): int => ((int) $a['sequence']['next_at']) <=> ((int) $b['sequence']['next_at']));
        return $due;
    }

    /**
     * Traite les envois dus. Appelé par le cron.
     * @return array{sent:int,skipped:int,errors:int,messages:string[]}
     */
    public static function runDue(int $maxSends = 0): array
    {
        $report = ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];

        if (!Config::get('sequence.enabled', true)) {
            $report['messages'][] = 'Séquence désactivée dans les réglages.';
            return $report;
        }

        $now = time();
        if (!self::isWithinWindow($now)) {
            $report['messages'][] = 'Hors de la fenêtre d\'envoi : rien à faire.';
            return $report;
        }

        $dailyLimit = (int) Config::get('sequence.daily_limit', 40);
        $remaining = max(0, $dailyLimit - Tracking::sentToday());
        if ($remaining === 0) {
            $report['messages'][] = 'Plafond quotidien atteint (' . $dailyLimit . ' emails).';
            return $report;
        }
        if ($maxSends > 0) {
            $remaining = min($remaining, $maxSends);
        }

        $gap = (int) Config::get('sequence.min_gap_seconds', 120);

        foreach (self::due($now) as $row) {
            if ($report['sent'] >= $remaining) {
                $report['messages'][] = 'Plafond atteint pour ce passage, la suite partira au prochain.';
                break;
            }

            $prospectId = (string) $row['id'];
            $prospect = Prospect::find($prospectId);
            if ($prospect === null) {
                continue;
            }

            $blocker = self::blocker($prospect);
            if ($blocker !== null) {
                self::stop($prospectId, $blocker);
                $report['skipped']++;
                $report['messages'][] = Prospect::displayName($prospect) . ' : ' . $blocker;
                continue;
            }

            // Espacement minimal entre deux envois, pour ne pas déclencher les
            // filtres anti-spam sur un pic de volume.
            $sinceLast = time() - Tracking::lastSentAt();
            if ($gap > 0 && $sinceLast < $gap && $report['sent'] > 0) {
                $report['messages'][] = 'Espacement minimal non atteint, envois suivants reportés.';
                break;
            }

            $step = (int) ($prospect['sequence']['step'] ?? 0) + 1;
            $result = Mailer::sendStep($prospect, $step);

            if ($result['ok']) {
                self::afterSend($prospectId, $step, (string) $result['token']);
                $report['sent']++;
                $report['messages'][] = 'Email ' . $step . ' envoyé à ' . $prospect['email'] . ' (' . Prospect::displayName($prospect) . ')';
            } else {
                $report['errors']++;
                $report['messages'][] = 'Échec pour ' . Prospect::displayName($prospect) . ' : ' . $result['error'];
                self::afterFailure($prospectId, $result['error']);
            }
        }

        return $report;
    }

    /** Met à jour l'état après un envoi réussi. */
    private static function afterSend(string $prospectId, int $step, string $token): void
    {
        Prospect::update($prospectId, static function (array $p) use ($step, $token): array {
            $p['sequence']['step'] = $step;
            $p['sequence']['sent'][] = ['step' => $step, 'token' => $token, 'at' => time()];

            if ($step >= self::LAST_STEP) {
                $p['sequence']['active'] = false;
                $p['sequence']['next_at'] = null;
                $p['sequence']['stopped_reason'] = 'Séquence terminée : les trois emails sont partis.';
                $engaged = (int) ($p['stats']['clicks'] ?? 0) > 0 || (int) ($p['stats']['views'] ?? 0) > 0;
                if (!$engaged && in_array($p['status'] ?? '', [Prospect::SEQUENCE, Prospect::VALIDATED], true)) {
                    $p['status'] = Prospect::LOST;
                }
            } else {
                $delays = (array) Config::get('sequence.delays_days', [0, 4, 8]);
                $delayDays = (int) ($delays[$step] ?? 4);
                $p['sequence']['next_at'] = self::nextSlot(time() + $delayDays * 86400);
            }
            return $p;
        });
    }

    /** Après trois échecs consécutifs, on cesse d'insister sur ce prospect. */
    private static function afterFailure(string $prospectId, string $error): void
    {
        Prospect::update($prospectId, static function (array $p) use ($error): array {
            $failures = (int) ($p['sequence']['failures'] ?? 0) + 1;
            $p['sequence']['failures'] = $failures;
            if ($failures >= 3) {
                $p['sequence']['active'] = false;
                $p['sequence']['next_at'] = null;
                $p['sequence']['stopped_reason'] = 'Arrêt après 3 échecs d\'envoi : ' . $error;
            } else {
                $p['sequence']['next_at'] = self::nextSlot(time() + 3600);
            }
            return $p;
        });
    }

    /** Raison éventuelle de ne pas envoyer maintenant, sinon null. */
    private static function blocker(array $prospect): ?string
    {
        if (!Prospect::isMailable($prospect)) {
            return 'Adresse email manquante, invalide ou désinscrite.';
        }
        if (empty($prospect['mockup']['validated'])) {
            return 'La maquette n\'est plus validée.';
        }
        if (in_array($prospect['status'] ?? '', [Prospect::INTERESTED, Prospect::CUSTOMER, Prospect::UNSUBSCRIBED], true)) {
            return 'Statut du prospect : ' . Prospect::label((string) $prospect['status']) . '.';
        }
        if ((int) ($prospect['sequence']['step'] ?? 0) >= self::LAST_STEP) {
            return 'Les trois emails ont déjà été envoyés.';
        }
        return null;
    }

    /** L'instant donné tombe-t-il dans un créneau d'envoi autorisé ? */
    public static function isWithinWindow(int $timestamp): bool
    {
        $days = array_map('intval', (array) Config::get('sequence.send_days', [1, 2, 3, 4, 5]));
        if ($days !== [] && !in_array((int) date('N', $timestamp), $days, true)) {
            return false;
        }
        $minutes = (int) date('G', $timestamp) * 60 + (int) date('i', $timestamp);
        return $minutes >= self::minutes((string) Config::get('sequence.send_from', '09:00'))
            && $minutes < self::minutes((string) Config::get('sequence.send_to', '18:00'));
    }

    /**
     * Prochain créneau autorisé à partir d'un horodatage : décale au besoin
     * jusqu'au prochain jour ouvré et à l'ouverture de la fenêtre.
     */
    public static function nextSlot(int $from): int
    {
        $days = array_map('intval', (array) Config::get('sequence.send_days', [1, 2, 3, 4, 5]));
        if ($days === []) {
            $days = [1, 2, 3, 4, 5];
        }
        $start = self::minutes((string) Config::get('sequence.send_from', '09:00'));
        $end = self::minutes((string) Config::get('sequence.send_to', '18:00'));
        if ($end <= $start) {
            $end = $start + 60;
        }

        $cursor = $from;
        for ($i = 0; $i < 21; $i++) {
            $dayStart = strtotime(date('Y-m-d', $cursor) . ' 00:00:00');
            $isAllowedDay = in_array((int) date('N', $cursor), $days, true);
            if ($isAllowedDay) {
                $windowStart = $dayStart + $start * 60;
                $windowEnd = $dayStart + $end * 60;
                if ($cursor < $windowStart) {
                    return $windowStart;
                }
                if ($cursor < $windowEnd) {
                    return $cursor;
                }
            }
            $cursor = strtotime('+1 day', $dayStart);
        }
        return $from;
    }

    private static function minutes(string $time): int
    {
        [$hours, $mins] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
        return Util::clamp($hours, 0, 23) * 60 + Util::clamp($mins, 0, 59);
    }

    /** Aperçu du calendrier des trois emails à partir de maintenant. */
    public static function preview(int $from = 0): array
    {
        $from = $from ?: time();
        $delays = (array) Config::get('sequence.delays_days', [0, 4, 8]);
        $schedule = [];
        $cursor = $from;
        for ($step = 1; $step <= self::LAST_STEP; $step++) {
            $cursor = self::nextSlot($cursor + ((int) ($delays[$step - 1] ?? 0)) * 86400);
            $schedule[$step] = $cursor;
            $cursor += 60;
        }
        return $schedule;
    }
}
