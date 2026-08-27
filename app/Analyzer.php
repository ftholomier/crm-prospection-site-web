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

        $notify('Calcul du score de vétusté');
        $audit = Audit::run($analysis['data']);
        $notify('Score : ' . $audit['score'] . '/100 — ' . $audit['level'], 'done');

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
        }
        return $prospect;
    }
}
