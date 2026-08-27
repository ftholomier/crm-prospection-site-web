<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Analyzer;
use App\Auth;
use App\Claude;
use App\Config;
use App\Events;
use App\Generator;
use App\Mockup;
use App\Prospect;
use App\Store;

/**
 * Points d'entrée en Server-Sent Events pour l'analyse et la génération.
 *
 * Ces deux traitements durent bien plus longtemps qu'une requête classique.
 * Le streaming permet d'afficher la progression en direct et, surtout, de
 * maintenir un flux de sortie continu : sans lui, un hébergement mutualisé
 * coupe la connexion avant la fin.
 *
 * La génération est découpée : une requête par étape (brief, puis une par
 * page). Chaque appel reste ainsi largement sous les limites d'exécution.
 */
final class Stream
{
    /** Journal du traitement en cours, conservé pour la fiche prospect. */
    private static array $journal = [];
    private static ?string $journalProspect = null;
    private static string $journalType = '';

    /** Ouvre un journal rattaché à un prospect, écrit à chaque étape. */
    private static function startJournal(string $prospectId, string $type): void
    {
        self::$journal = [];
        self::$journalProspect = $prospectId;
        self::$journalType = $type;
    }

    /**
     * Enregistre le déroulé sur la fiche.
     *
     * Sans cela, le détail défile puis disparaît au rechargement de la page :
     * impossible de relire ce que l'analyse a trouvé.
     */
    private static function saveJournal(bool $ok, string $conclusion = ''): void
    {
        if (self::$journalProspect === null) {
            return;
        }
        // Une étape suivie d'une autre est forcément achevée : on la marque
        // comme telle, sans quoi tout le déroulé reste « en cours » à la relecture.
        $steps = self::$journal;
        $dernier = count($steps) - 1;
        foreach ($steps as $index => $step) {
            if ($index < $dernier && ($step['state'] ?? '') === 'running') {
                $steps[$index]['state'] = 'done';
            }
        }
        if ($ok && $dernier >= 0 && ($steps[$dernier]['state'] ?? '') === 'running') {
            $steps[$dernier]['state'] = 'done';
        }

        $entry = [
            'type' => self::$journalType,
            'at' => time(),
            'ok' => $ok,
            'conclusion' => $conclusion,
            'steps' => $steps,
        ];
        Prospect::update(self::$journalProspect, static function (array $p) use ($entry): array {
            $p['last_run'] = $entry;
            return $p;
        });
    }

    /** Prépare la réponse SSE et désactive toute mise en tampon intermédiaire. */
    private static function open(): void
    {
        @set_time_limit(0);
        ignore_user_abort(false);

        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // désactive le tampon de Nginx

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);
        // Certains proxys attendent un premier octet avant d'ouvrir le flux.
        echo ':' . str_repeat(' ', 2048) . "\n\n";
        @flush();
    }

    private static function emit(string $event, array $data): void
    {
        if ($event === 'step') {
            self::$journal[] = [
                'message' => (string) ($data['message'] ?? ''),
                'state' => (string) ($data['state'] ?? 'running'),
                'at' => time(),
            ];
        }
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }

    private static function fail(string $message): never
    {
        self::$journal[] = ['message' => $message, 'state' => 'error', 'at' => time()];
        self::saveJournal(false, $message);
        self::emit('error', ['message' => $message]);
        exit;
    }

    // ------------------------------------------------------------- Analyse

    public static function analyze(): void
    {
        Auth::requireLogin();
        self::open();

        $id = (string) ($_GET['id'] ?? '');
        if (Prospect::find($id) === null) {
            self::fail('Prospect introuvable.');
        }

        self::startJournal($id, 'Analyse du site');
        self::emit('step', ['message' => 'Analyse démarrée']);
        $result = Analyzer::run($id, static function (string $message, string $state): void {
            self::emit('step', ['message' => $message, 'state' => $state]);
        });

        if (!$result['ok']) {
            self::fail($result['error']);
        }

        $prospect = $result['prospect'];
        $conclusion = 'Score ' . ($prospect['audit']['score'] ?? 0) . '/100 — '
            . ($prospect['audit']['level'] ?? '')
            . ' · ' . count($prospect['audit']['findings'] ?? []) . ' constat(s)';
        self::saveJournal(true, $conclusion);

        self::emit('done', [
            'score' => $prospect['audit']['score'] ?? 0,
            'level' => $prospect['audit']['level'] ?? '',
            'company' => $prospect['company'] ?? '',
            'email' => $prospect['email'] ?? '',
            'findings' => count($prospect['audit']['findings'] ?? []),
        ]);
    }

    /**
     * Lecture du site par l'IA, quand notre serveur est refusé.
     * Le traitement enchaîne plusieurs récupérations de pages : il est long,
     * d'où le flux plutôt qu'une requête classique.
     */
    public static function readSite(): void
    {
        Auth::requireLogin();
        self::open();

        $id = (string) ($_GET['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            self::fail('Prospect introuvable.');
        }
        self::startJournal($id, 'Lecture du site par l\'IA');
        if (!\App\SiteReader::isAvailable()) {
            self::fail('Renseignez la clé API Claude dans les Réglages : la lecture par l\'IA en dépend.');
        }

        $result = Analyzer::runFromAi($id, static function (string $message, string $state = 'running'): void {
            self::emit('step', ['message' => $message, 'state' => $state]);
        });

        if (!$result['ok']) {
            self::fail($result['error']);
        }

        $prospect = $result['prospect'];
        $analysis = $prospect['analysis'] ?? [];
        self::saveJournal(true, count($analysis['pages_found'] ?? []) . ' page(s) lue(s), '
            . count($analysis['services'] ?? []) . ' prestation(s) relevée(s)');

        self::emit('done', [
            'company' => $prospect['company'] ?? '',
            'email' => $prospect['email'] ?? '',
            'services' => count($analysis['services'] ?? []),
            'pages' => count($analysis['pages_found'] ?? []),
        ]);
    }

    // ---------------------------------------------------------- Génération

    /**
     * Une étape de génération par requête.
     * step = brief | accueil | a-propos | prestations
     * mode = new | revise
     */
    public static function generate(): void
    {
        Auth::requireLogin();
        self::open();

        $id = (string) ($_GET['id'] ?? '');
        $prospect = Prospect::find($id);
        if ($prospect === null) {
            self::fail('Prospect introuvable.');
        }
        if (!Claude::isConfigured()) {
            self::fail('Renseignez la clé API Claude dans les Réglages avant de générer.');
        }

        $step = (string) ($_GET['step'] ?? 'brief');
        $mode = ($_GET['mode'] ?? 'new') === 'revise' ? 'revise' : 'new';
        $instruction = trim((string) ($_GET['instruction'] ?? ''));

        if ($step === 'brief') {
            self::generateBrief($prospect, $mode, $instruction);
            return;
        }

        $version = (string) ($_GET['version'] ?? '');
        if ($version === '' || !is_dir(Mockup::dir($id, $version))) {
            self::fail('Version de maquette introuvable. Relancez la génération depuis le début.');
        }
        self::generatePage($prospect, $version, $step, $mode, $instruction);
    }

    /** Étape 1 : brief de direction artistique (ou reprise du brief existant). */
    private static function generateBrief(array $prospect, string $mode, string $instruction): void
    {
        $id = (string) $prospect['id'];
        $version = Mockup::nextVersion($id);
        $source = (string) ($prospect['mockup']['current'] ?? '');

        if ($mode === 'revise') {
            if ($source === '' || !Mockup::isComplete($id, $source)) {
                self::fail('Aucune maquette existante à retoucher.');
            }
            self::emit('step', ['message' => 'Duplication de la version ' . $source]);

            $brief = Store::read(Mockup::dir($id, $source) . '/brief.json');
            foreach (array_keys(Mockup::PAGES) as $page) {
                $html = Mockup::readPage($id, $source, $page);
                if ($html !== null) {
                    Mockup::writePage($id, $version, $page, $html);
                }
            }
            Store::write(Mockup::dir($id, $version) . '/brief.json', $brief);
            self::emit('step', ['message' => 'Version ' . $version . ' prête à être retouchée', 'state' => 'done']);
            self::emit('done', ['version' => $version, 'brief' => true]);
            return;
        }

        if (empty($prospect['analysis'])) {
            self::fail('Analysez d\'abord le site du prospect.');
        }

        self::emit('step', ['message' => 'Lecture du site et cadrage de la direction artistique']);

        // L'analyse stockée est allégée : on relit la page pour disposer du
        // HTML et du CSS au moment de générer.
        $prospect = Analyzer::refreshRaw($prospect);

        $result = Generator::brief($prospect, $instruction);
        if (!$result['ok']) {
            self::fail($result['error']);
        }

        if (($result['notice'] ?? '') !== '') {
            self::emit('step', ['message' => $result['notice'], 'state' => 'warn']);
        }

        Store::write(Mockup::dir($id, $version) . '/brief.json', $result['brief']);
        $palette = $result['brief']['palette'] ?? [];

        self::emit('step', [
            'message' => 'Direction artistique définie : ' . ($result['brief']['ton'] ?? 'style retenu'),
            'state' => 'done',
        ]);
        self::emit('brief', [
            'version' => $version,
            'entreprise' => $result['brief']['entreprise'] ?? '',
            'accroche' => $result['brief']['accroche'] ?? '',
            'palette' => array_values(array_filter((array) $palette)),
            'polices' => $result['brief']['polices'] ?? [],
        ]);
        self::emit('done', ['version' => $version, 'brief' => true]);
    }

    /** Étape 2 : génération (ou retouche) d'une page. */
    private static function generatePage(array $prospect, string $version, string $page, string $mode, string $instruction): void
    {
        $id = (string) $prospect['id'];
        $page = Mockup::safePage($page);
        $label = Mockup::PAGES[$page];

        $brief = Store::read(Mockup::dir($id, $version) . '/brief.json');
        if ($brief === []) {
            self::fail('Brief introuvable pour la version ' . $version . '.');
        }

        $currentHtml = null;
        if ($mode === 'revise') {
            $currentHtml = Mockup::readPage($id, $version, $page);
            if ($currentHtml === null) {
                self::fail('Page « ' . $label . ' » introuvable dans la version à retoucher.');
            }
        }

        self::emit('step', ['message' => ($mode === 'revise' ? 'Retouche' : 'Génération') . ' de la page ' . $label]);

        $lastPing = microtime(true);
        $result = Generator::page(
            $prospect,
            $brief,
            $page,
            static function (string $chunk, array $meta) use (&$lastPing, $page): void {
                // Un signal régulier suffit : envoyer chaque fragment saturerait
                // la connexion pour un intérêt nul côté interface.
                $now = microtime(true);
                if ($now - $lastPing >= 0.7) {
                    $lastPing = $now;
                    self::emit('progress', ['page' => $page, 'chars' => (int) $meta['length']]);
                }
            },
            $currentHtml,
            $instruction
        );

        if (!$result['ok']) {
            self::fail($result['error']);
        }
        if (!Mockup::writePage($id, $version, $page, $result['html'])) {
            self::fail('Écriture impossible dans data/mockups. Vérifiez les droits du dossier.');
        }

        $checks = Mockup::inspect($result['html']);
        self::emit('step', [
            'message' => 'Page ' . $label . ' générée (' . number_format($checks['size'] / 1024, 1, ',', ' ') . ' Ko)',
            'state' => 'done',
        ]);

        // La dernière page bascule la version en version active.
        if (Mockup::isComplete($id, $version)) {
            self::finalize($prospect, $version, $mode, $instruction);
        }

        self::emit('done', [
            'version' => $version,
            'page' => $page,
            'checks' => $checks,
            'complete' => Mockup::isComplete($id, $version),
        ]);
    }

    /** Active la version terminée et met à jour le statut du prospect. */
    private static function finalize(array $prospect, string $version, string $mode, string $instruction): void
    {
        $id = (string) $prospect['id'];

        Mockup::writeMeta($id, $version, [
            'version' => $version,
            'created_at' => time(),
            'mode' => $mode,
            'instruction' => $instruction,
            'model' => (string) Config::get('claude.model', 'claude-opus-5'),
        ]);

        Prospect::update($id, static function (array $p) use ($version, $mode, $instruction): array {
            $p['mockup']['current'] = $version;
            $p['mockup']['versions'][$version] = [
                'created_at' => time(),
                'mode' => $mode,
                'instruction' => $instruction,
            ];
            // Toute nouvelle version repart non validée : on ne diffuse jamais
            // une maquette qui n'a pas été revue.
            $p['mockup']['validated'] = false;
            if (in_array($p['status'] ?? '', [Prospect::NEW, Prospect::ANALYZED, Prospect::VALIDATED], true)) {
                $p['status'] = Prospect::MOCKUP;
            }
            return $p;
        });

        \App\Models::countMockup();

        Events::log($id, $mode === 'revise' ? 'mockup_revised' : 'mockup_generated', [
            'version' => $version,
            'instruction' => $instruction,
        ]);
    }
}
