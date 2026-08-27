<?php
declare(strict_types=1);

namespace App;

/**
 * Chaîne d'analyse d'un prospect : lecture du site, score de vétusté,
 * enrichissement des coordonnées et capture d'écran. Chaque étape signale sa
 * progression pour être affichée en direct dans l'interface.
 */
final class Analyzer
{
    /**
     * @param callable(string $message, string $state):void|null $progress
     * @return array{ok:bool,error:string,prospect:?array}
     */
    public static function run(string $prospectId, ?callable $progress = null): array
    {
        $notify = static function (string $message, string $state = 'running') use ($progress): void {
            if ($progress !== null) {
                $progress($message, $state);
            }
        };

        $prospect = Prospect::find($prospectId);
        if ($prospect === null) {
            return ['ok' => false, 'error' => 'Prospect introuvable.', 'prospect' => null];
        }
        // Le nom de fichier est normalisé : on repart de l'identifiant stocké
        // pour que les événements soient toujours rattachés à la bonne fiche.
        $prospectId = (string) $prospect['id'];

        $analysis = Scraper::analyze((string) $prospect['url'], static fn (string $m) => $notify($m));
        if (!$analysis['ok']) {
            Events::log($prospectId, 'error', ['step' => 'analyse', 'message' => $analysis['error']]);
            return ['ok' => false, 'error' => $analysis['error'], 'prospect' => $prospect];
        }

        return self::finish($prospectId, $prospect, $analysis['data'], $notify);
    }

    /**
     * Analyse à partir d'un code source collé à la main, pour les sites qui
     * refusent toute lecture automatique.
     */
    public static function runFromHtml(string $prospectId, string|array $sources): array
    {
        $prospect = Prospect::find($prospectId);
        if ($prospect === null) {
            return ['ok' => false, 'error' => 'Prospect introuvable.', 'prospect' => null];
        }

        $html = is_string($sources) ? $sources : (string) ($sources['accueil'] ?? '');
        $analysis = Scraper::analyzeHtml($sources, (string) $prospect['url']);
        if (!$analysis['ok']) {
            return ['ok' => false, 'error' => $analysis['error'], 'prospect' => $prospect];
        }

        // Le code source est conservé sur disque : sans lui, la génération
        // redemanderait un collage à chaque fois puisque le site reste fermé.
        self::storeSource((string) $prospect['id'], $html);

        return self::finish($prospectId, $prospect, $analysis['data'], static function (): void {
        });
    }

    /**
     * Lecture du site par l'IA, quand notre serveur est bloqué.
     *
     * Le contenu récupéré complète l'analyse existante sans l'écraser : le
     * modèle lit le texte des pages, mais pas les couleurs, les polices ni le
     * HTML technique, qui ne peuvent venir que d'une lecture directe ou d'un
     * collage. L'audit déjà calculé est donc conservé tel quel.
     */
    public static function runFromAi(string $prospectId, ?callable $progress = null): array
    {
        $prospect = Prospect::find($prospectId);
        if ($prospect === null) {
            return ['ok' => false, 'error' => 'Prospect introuvable.', 'prospect' => null];
        }

        $read = SiteReader::read($prospect, $progress);
        if (!$read['ok']) {
            Events::log((string) $prospect['id'], 'error', ['step' => 'lecture_ia', 'message' => $read['error']]);
            return ['ok' => false, 'error' => $read['error'], 'prospect' => $prospect];
        }

        $merged = self::mergeAnalysis($prospect['analysis'] ?? [], $read['data']);
        return self::finish((string) $prospect['id'], $prospect, $merged, $progress ?? static function (): void {
        }, keepAudit: ($prospect['audit'] ?? []) !== []);
    }

    /**
     * Fusionne une lecture IA avec l'analyse existante.
     * Les champs que le modèle ne peut pas percevoir sont préservés.
     */
    private static function mergeAnalysis(array $existing, array $fresh): array
    {
        if ($existing === []) {
            return $fresh;
        }

        $merged = array_merge($existing, array_filter(
            $fresh,
            static fn ($value): bool => $value !== '' && $value !== [] && $value !== null
        ));

        foreach (['colors', 'fonts', 'logo', 'images', 'raw', 'generator', 'lang'] as $visual) {
            if (!empty($existing[$visual])) {
                $merged[$visual] = $existing[$visual];
            }
        }

        // Les coordonnées se complètent champ par champ : le collage porte
        // souvent le téléphone, la lecture IA l'email de la page contact.
        $merged['contact'] = array_merge($existing['contact'] ?? [], array_filter(
            $fresh['contact'] ?? [],
            static fn ($value): bool => $value !== '' && $value !== []
        ));

        $merged['http'] = $existing['http'] ?? $fresh['http'];
        $merged['source'] = trim((string) ($existing['source'] ?? '') . ' + ia', ' +');
        return $merged;
    }

    public static function sourcePath(string $prospectId): string
    {
        return Mockup::dir($prospectId) . '/source.html';
    }

    public static function hasStoredSource(string $prospectId): bool
    {
        return is_file(self::sourcePath($prospectId));
    }

    private static function storeSource(string $prospectId, string $html): void
    {
        $dir = Mockup::dir($prospectId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::sourcePath($prospectId), $html);
    }

    /** Étapes communes aux deux modes : score, enrichissement, capture, sauvegarde. */
    private static function finish(
        string $prospectId,
        array $prospect,
        array $data,
        callable $notify,
        bool $keepAudit = false
    ): array {
        $prospectId = (string) $prospect['id'];
        $analysis = ['data' => $data];

        if ($keepAudit) {
            // Une lecture IA ne fournit pas le HTML : recalculer l'audit sur
            // rien effacerait un score déjà établi sur du code réel.
            $audit = $prospect['audit'];
            $notify('Score de vétusté conservé : ' . $audit['score'] . '/100', 'done');
        } else {
            $notify('Calcul du score de vétusté');
            $audit = Audit::run($analysis['data']);
            $notify('Score : ' . $audit['score'] . '/100 — ' . $audit['level'], 'done');
        }

        $notify('Recherche des coordonnées');
        $prospect['analysis'] = $analysis['data'];
        $prospect['audit'] = $audit;
        $prospect = Enrich::apply($prospect, $analysis['data']);

        foreach ($prospect['enrichment']['sources'] ?? [] as $source) {
            $notify($source, 'done');
        }

        if (Config::get('screenshot.auto', true)) {
            $notify('Capture du site actuel');
            $shot = Screenshot::capture($prospectId, (string) $analysis['data']['url']);
            $notify(
                $shot['ok'] ? 'Capture enregistrée' : 'Capture indisponible : ' . $shot['error'],
                $shot['ok'] ? 'done' : 'warn'
            );
        }

        if (($prospect['status'] ?? '') === Prospect::NEW) {
            $prospect['status'] = Prospect::ANALYZED;
        }
        // L'analyse ne conserve pas le HTML brut : inutile ensuite, et coûteux en disque.
        $stored = $prospect;
        unset($stored['analysis']['raw']);
        $stored = Prospect::save($stored);

        Events::log($prospectId, 'analyzed', [
            'score' => $audit['score'],
            'email' => $stored['email'] ?? '',
            'findings' => count($audit['findings']),
        ]);

        return ['ok' => true, 'error' => '', 'prospect' => $stored, 'analysis' => $analysis['data']];
    }

    /**
     * Recharge le HTML nécessaire à la génération : l'analyse stockée est
     * volontairement allégée, on relit donc la page au moment de générer.
     */
    public static function refreshRaw(array $prospect): array
    {
        $fresh = Scraper::analyze((string) $prospect['url']);
        if ($fresh['ok']) {
            $prospect['analysis'] = $fresh['data'];
            return $prospect;
        }

        // Le site reste fermé : l'analyse enregistrée fait foi. Elle contient
        // déjà tout ce dont la génération a besoin, et la réécrire depuis le
        // seul code collé effacerait un éventuel enrichissement par l'IA.
        return $prospect;
    }
}
