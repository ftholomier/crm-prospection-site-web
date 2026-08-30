<?php
declare(strict_types=1);

namespace App;

/**
 * Traitement périodique : analyse des prospects importés, génération des
 * maquettes si l'option est activée, puis avancement de la séquence d'emails.
 *
 * Un verrou global empêche deux passages simultanés d'envoyer deux fois le
 * même email.
 */
final class Cron
{
    /**
     * @return array{ok:bool,lines:string[],report:array}
     */
    public static function run(int $maxSends = 0): array
    {
        $lines = [];
        $lock = Store::tryLock('cron');
        if ($lock === null) {
            return ['ok' => false, 'lines' => ['Un autre traitement est déjà en cours.'], 'report' => []];
        }

        @set_time_limit(0);
        $started = microtime(true);
        $lines[] = 'Démarrage : ' . date('d/m/Y H:i:s');

        try {
            $perRun = (int) Config::get('batch.per_run', 3);

            if (Config::get('batch.auto_analyze', true)) {
                $analyzed = self::analyzePending($perRun, $lines);
                $lines[] = $analyzed . ' analyse(s) réalisée(s).';
            }

            if (Config::get('batch.auto_generate', false)) {
                $generated = self::generatePending(max(1, (int) ($perRun / 2)), $lines);
                $lines[] = $generated . ' maquette(s) générée(s).';
            }

            $report = Sequence::runDue($maxSends);
            foreach ($report['messages'] as $message) {
                $lines[] = $message;
            }
            $lines[] = $report['sent'] . ' email(s) envoyé(s), '
                . $report['skipped'] . ' ignoré(s), '
                . $report['errors'] . ' erreur(s).';
        } finally {
            Store::unlock($lock);
        }

        $lines[] = 'Terminé en ' . round(microtime(true) - $started, 1) . ' s.';
        self::writeLog($lines);

        return ['ok' => true, 'lines' => $lines, 'report' => $report ?? []];
    }

    /** Analyse les prospects encore au statut « à analyser ». */
    private static function analyzePending(int $limit, array &$lines): int
    {
        $done = 0;
        foreach (Prospect::index() as $row) {
            if ($done >= $limit) {
                break;
            }
            if (($row['status'] ?? '') !== Prospect::NEW) {
                continue;
            }
            $result = Analyzer::run((string) $row['id']);
            if ($result['ok']) {
                $prospect = $result['prospect'];
                $lines[] = 'Analysé : ' . Prospect::displayName($prospect)
                    . ' — score ' . ($prospect['audit']['score'] ?? '?') . '/100';
            } else {
                $lines[] = 'Analyse impossible pour ' . ($row['domain'] ?? '?') . ' : ' . $result['error'];
            }
            $done++;
        }
        return $done;
    }

    /** Génère les maquettes manquantes pour les prospects déjà analysés. */
    private static function generatePending(int $limit, array &$lines): int
    {
        if (!Ai::isConfigured()) {
            $lines[] = 'Génération automatique ignorée : ' . Ai::missingKeyMessage();
            return 0;
        }

        $minScore = (int) Config::get('audit.min_score_to_prospect', 40);
        $done = 0;

        foreach (Prospect::index() as $row) {
            if ($done >= $limit) {
                break;
            }
            if (($row['status'] ?? '') !== Prospect::ANALYZED || !empty($row['has_mockup'])) {
                continue;
            }
            if ((int) ($row['score'] ?? 0) < $minScore) {
                continue;
            }

            $result = self::generateFor((string) $row['id']);
            $lines[] = $result['ok']
                ? 'Maquette générée pour ' . ($row['domain'] ?? '?') . ' (' . $result['version'] . ')'
                : 'Génération impossible pour ' . ($row['domain'] ?? '?') . ' : ' . $result['error'];
            $done++;
        }
        return $done;
    }

    /**
     * Génère une maquette complète hors interface : brief puis les trois pages.
     * @return array{ok:bool,error:string,version:string}
     */
    public static function generateFor(string $prospectId): array
    {
        $prospect = Prospect::find($prospectId);
        if ($prospect === null) {
            return ['ok' => false, 'error' => 'Prospect introuvable.', 'version' => ''];
        }
        if (empty($prospect['analysis'])) {
            return ['ok' => false, 'error' => 'Site non analysé.', 'version' => ''];
        }

        $version = Mockup::nextVersion($prospectId);
        $prospect = Analyzer::refreshRaw($prospect);

        $brief = Generator::brief($prospect);
        if (!$brief['ok']) {
            return ['ok' => false, 'error' => $brief['error'], 'version' => ''];
        }
        Store::write(Mockup::dir($prospectId, $version) . '/brief.json', $brief['brief']);

        foreach (array_keys(Mockup::PAGES) as $page) {
            $result = Generator::page($prospect, $brief['brief'], $page);
            if (!$result['ok']) {
                return ['ok' => false, 'error' => 'Page ' . $page . ' : ' . $result['error'], 'version' => ''];
            }
            Mockup::writePage($prospectId, $version, $page, $result['html']);
        }

        Mockup::writeMeta($prospectId, $version, [
            'version' => $version,
            'created_at' => time(),
            'mode' => 'auto',
            'model' => (string) Config::get('claude.model', 'claude-opus-5'),
        ]);

        Prospect::update($prospectId, static function (array $p) use ($version): array {
            $p['mockup']['current'] = $version;
            $p['mockup']['versions'][$version] = ['created_at' => time(), 'mode' => 'auto', 'instruction' => ''];
            $p['mockup']['validated'] = false;
            $p['status'] = Prospect::MOCKUP;
            return $p;
        });
        Models::countMockup();
        Events::log($prospectId, 'mockup_generated', ['version' => $version, 'mode' => 'auto']);

        return ['ok' => true, 'error' => '', 'version' => $version];
    }

    /** Déclenchement HTTP protégé par une clé, pour les crons de type « URL ». */
    public static function web(): void
    {
        header('Content-Type: text/plain; charset=UTF-8');

        $expected = (string) Config::get('app.cron_key', '');
        $provided = (string) ($_GET['key'] ?? '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            echo "Clé de cron invalide.\n";
            return;
        }

        $result = self::run();
        echo implode("\n", $result['lines']) . "\n";
    }

    /** Conserve les 200 derniers passages pour le diagnostic. */
    private static function writeLog(array $lines): void
    {
        Store::append(DATA_DIR . '/logs/cron.jsonl', ['ts' => time(), 'lines' => $lines]);

        $path = DATA_DIR . '/logs/cron.jsonl';
        $all = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($all) > 200) {
            @file_put_contents($path, implode("\n", array_slice($all, -200)) . "\n", LOCK_EX);
        }
    }

    public static function lastRuns(int $limit = 10): array
    {
        return Store::tail(DATA_DIR . '/logs/cron.jsonl', $limit);
    }
}
