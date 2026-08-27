<?php
declare(strict_types=1);

namespace App;

/**
 * Suivi des envois. Chaque email envoyé reçoit un jeton unique qui sert à la
 * fois au pixel d'ouverture et au lien cliquable.
 *
 * Le lien de suivi ne prend aucune URL en paramètre : il redirige toujours vers
 * la maquette du prospect concerné. Aucune redirection ouverte n'est donc
 * exploitable depuis l'extérieur.
 */
final class Tracking
{
    public static function path(): string
    {
        return DATA_DIR . '/sends.json';
    }

    /** Enregistre un envoi et retourne son jeton. */
    public static function register(string $prospectId, int $step, string $subject): string
    {
        $token = Util::token(12);
        Store::mutate(self::path(), static function (array $sends) use ($token, $prospectId, $step, $subject): array {
            $sends[$token] = [
                'prospect_id' => $prospectId,
                'step' => $step,
                'subject' => $subject,
                'sent_at' => time(),
                'opened_at' => null,
                'clicked_at' => null,
                'opens' => 0,
                'clicks' => 0,
            ];
            return $sends;
        });
        return $token;
    }

    public static function find(string $token): ?array
    {
        $sends = Store::read(self::path());
        return $sends[$token] ?? null;
    }

    /** Marque une ouverture (une seule comptée par requête). */
    public static function recordOpen(string $token): ?array
    {
        $send = self::find($token);
        if ($send === null) {
            return null;
        }
        $first = $send['opened_at'] === null;
        Store::mutate(self::path(), static function (array $sends) use ($token): array {
            if (isset($sends[$token])) {
                $sends[$token]['opens'] = (int) ($sends[$token]['opens'] ?? 0) + 1;
                $sends[$token]['opened_at'] ??= time();
            }
            return $sends;
        });

        if ($first) {
            Prospect::update((string) $send['prospect_id'], static function (array $p): array {
                $p['stats']['opens'] = (int) ($p['stats']['opens'] ?? 0) + 1;
                return $p;
            });
            Events::log((string) $send['prospect_id'], Events::OPEN, ['step' => $send['step']]);
        }
        return $send;
    }

    /** Marque un clic, arrête la séquence si l'option est active. */
    public static function recordClick(string $token): ?array
    {
        $send = self::find($token);
        if ($send === null) {
            return null;
        }
        $first = $send['clicked_at'] === null;
        Store::mutate(self::path(), static function (array $sends) use ($token): array {
            if (isset($sends[$token])) {
                $sends[$token]['clicks'] = (int) ($sends[$token]['clicks'] ?? 0) + 1;
                $sends[$token]['clicked_at'] ??= time();
            }
            return $sends;
        });

        if ($first) {
            $prospectId = (string) $send['prospect_id'];
            Prospect::update($prospectId, static function (array $p): array {
                $p['stats']['clicks'] = (int) ($p['stats']['clicks'] ?? 0) + 1;
                return $p;
            });
            Events::log($prospectId, Events::CLICK, ['step' => $send['step']]);
            if (Config::get('sequence.stop_on_click', true)) {
                Sequence::stop($prospectId, 'Le prospect a cliqué sur le lien de la maquette.');
            }
        }
        return $send;
    }

    /** Statistiques agrégées sur l'ensemble des envois. */
    public static function stats(): array
    {
        $sends = Store::read(self::path());
        $totals = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'by_step' => []];
        foreach ($sends as $send) {
            $step = (int) ($send['step'] ?? 0);
            $totals['sent']++;
            $totals['by_step'][$step]['sent'] = ($totals['by_step'][$step]['sent'] ?? 0) + 1;
            if (!empty($send['opened_at'])) {
                $totals['opened']++;
                $totals['by_step'][$step]['opened'] = ($totals['by_step'][$step]['opened'] ?? 0) + 1;
            }
            if (!empty($send['clicked_at'])) {
                $totals['clicked']++;
                $totals['by_step'][$step]['clicked'] = ($totals['by_step'][$step]['clicked'] ?? 0) + 1;
            }
        }
        ksort($totals['by_step']);
        return $totals;
    }

    /** Nombre d'emails envoyés depuis minuit, pour le plafond quotidien. */
    public static function sentToday(): int
    {
        $midnight = strtotime('today midnight');
        $count = 0;
        foreach (Store::read(self::path()) as $send) {
            if ((int) ($send['sent_at'] ?? 0) >= $midnight) {
                $count++;
            }
        }
        return $count;
    }

    /** Horodatage du dernier envoi, pour respecter l'espacement minimal. */
    public static function lastSentAt(): int
    {
        $last = 0;
        foreach (Store::read(self::path()) as $send) {
            $last = max($last, (int) ($send['sent_at'] ?? 0));
        }
        return $last;
    }

    /** Image GIF transparente de 1×1 pixel servie comme témoin d'ouverture. */
    public static function pixel(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
}
