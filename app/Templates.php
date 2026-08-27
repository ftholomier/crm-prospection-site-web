<?php
declare(strict_types=1);

namespace App;

/**
 * Modèles de la séquence de trois emails. Les textes livrés par défaut sont
 * fonctionnels immédiatement et entièrement réécrivables depuis l'interface.
 */
final class Templates
{
    public const STEPS = [1 => 'Email 1 — Découverte', 2 => 'Email 2 — Relance', 3 => 'Email 3 — Clôture'];

    public static function path(): string
    {
        return DATA_DIR . '/templates.json';
    }

    public static function all(): array
    {
        $stored = Store::read(self::path());
        $defaults = self::defaults();
        foreach ($defaults as $step => $template) {
            if (isset($stored[$step]) && is_array($stored[$step])) {
                $defaults[$step] = array_merge($template, $stored[$step]);
            }
        }
        return $defaults;
    }

    public static function get(int $step): array
    {
        return self::all()[$step] ?? self::defaults()[1];
    }

    public static function save(int $step, array $template): void
    {
        Store::mutate(self::path(), static function (array $all) use ($step, $template): array {
            $all[$step] = [
                'subject' => (string) ($template['subject'] ?? ''),
                'body' => (string) ($template['body'] ?? ''),
                'enabled' => (bool) ($template['enabled'] ?? true),
            ];
            return $all;
        });
    }

    public static function reset(int $step): void
    {
        Store::mutate(self::path(), static function (array $all) use ($step): array {
            unset($all[$step]);
            return $all;
        });
    }

    /** Variables disponibles, affichées dans l'éditeur de modèles. */
    public static function variables(): array
    {
        return [
            '{{prenom}}' => 'Prénom du contact',
            '{{nom}}' => 'Nom du contact',
            '{{nom_complet}}' => 'Prénom et nom',
            '{{societe}}' => 'Raison sociale (ou domaine si inconnue)',
            '{{email}}' => 'Adresse email du contact',
            '{{domaine}}' => 'Domaine du site actuel (ex. monentreprise.fr)',
            '{{url_site}}' => 'URL complète du site actuel',
            '{{ville}}' => 'Ville de l\'entreprise',
            '{{secteur}}' => 'Secteur d\'activité',
            '{{tarif}}' => 'Tarif mensuel du prospect (ex. 79 €)',
            '{{lien_maquette}}' => 'Lien sécurisé vers la maquette (suivi des clics)',
            '{{score}}' => 'Score de vétusté du site sur 100',
            '{{constat_1}}' => 'Constat d\'audit le plus fort',
            '{{constat_2}}' => 'Deuxième constat d\'audit',
            '{{constat_3}}' => 'Troisième constat d\'audit',
            '{{constats_liste}}' => 'Liste à puces des trois constats',
            '{{inclus_liste}}' => 'Liste à puces de ce qui est inclus dans l\'offre',
            '{{signature}}' => 'Votre signature (Réglages)',
            '{{expediteur}}' => 'Votre nom d\'expéditeur',
            '{{lien_desinscription}}' => 'Lien de désinscription (ajouté automatiquement si absent)',
        ];
    }

    /**
     * Syntaxes supplémentaires :
     * {{variable|valeur de repli}} et {{#si variable}}…{{/si}} pour n'afficher
     * un fragment que si la variable est renseignée.
     */
    public static function syntaxHelp(): array
    {
        return [
            '{{prenom|}}' => 'Affiche le prénom, ou rien si inconnu.',
            '{{societe|votre entreprise}}' => 'Affiche la société, ou « votre entreprise » à défaut.',
            '{{#si prenom}} {{prenom}}{{/si}}' => 'N\'affiche le bloc que si le prénom est renseigné.',
        ];
    }

    public static function defaults(): array
    {
        return [
            1 => [
                'subject' => "{{societe}} : j'ai maquetté votre nouveau site",
                'enabled' => true,
                'body' => <<<HTML
<p>Bonjour{{#si prenom}} {{prenom}}{{/si}},</p>

<p>Je suis tombé sur <strong>{{domaine}}</strong>{{#si ville}} en cherchant des entreprises à {{ville}}{{/si}}. Votre site fait le travail, mais il porte visiblement son âge :</p>

{{constats_liste}}

<p>Plutôt que de vous l'écrire, j'ai préféré vous le montrer. J'ai réalisé une maquette complète de ce que pourrait être votre site aujourd'hui : accueil, à propos et prestations. Elle reprend votre univers, vos prestations réelles et vos coordonnées.</p>

<p><a class="bouton" href="{{lien_maquette}}">Voir la maquette de {{societe}}</a></p>

<p>Si elle vous plaît, je la mets en ligne pour <strong>{{tarif}} par mois</strong>, tout compris :</p>

{{inclus_liste}}

<p>Et surtout : <strong>aucune durée minimum</strong>. Vous arrêtez quand vous voulez, sans justification et sans frais.</p>

<p>Dites-moi simplement ce que vous en pensez — même si la réponse est non, ça m'intéresse de savoir pourquoi.</p>

{{signature}}
HTML,
            ],
            2 => [
                'subject' => "Vous avez pu jeter un œil à la maquette ?",
                'enabled' => true,
                'body' => <<<HTML
<p>Bonjour{{#si prenom}} {{prenom}}{{/si}},</p>

<p>Je remonte mon message de la semaine dernière : je vous avais envoyé une maquette du site de {{societe}}, refait de zéro.</p>

<p>Elle est toujours en ligne, rien à installer, rien à signer pour la regarder :</p>

<p><a class="bouton" href="{{lien_maquette}}">Revoir la maquette</a></p>

<p>Pour situer : <strong>{{tarif}} par mois</strong>, hébergement, sauvegardes, mises à jour techniques et modifications de contenu comprises. Sans engagement.</p>

<p>Si ce n'est pas le moment, dites-le moi d'un mot, je n'insisterai pas.</p>

{{signature}}
HTML,
            ],
            3 => [
                'subject' => "Je referme le dossier {{societe}}",
                'enabled' => true,
                'body' => <<<HTML
<p>Bonjour{{#si prenom}} {{prenom}}{{/si}},</p>

<p>Sans retour de votre part, je considère que le sujet n'est pas d'actualité — c'est parfaitement entendu, et c'est mon dernier message.</p>

<p>Je laisse la maquette accessible encore quelques jours, au cas où vous voudriez la montrer autour de vous :</p>

<p><a class="bouton" href="{{lien_maquette}}">Voir la maquette une dernière fois</a></p>

<p>Si le sujet revient sur la table dans six mois ou dans deux ans, répondez simplement à cet email : je la remettrai à jour.</p>

<p>Bonne continuation à {{societe}}.</p>

{{signature}}
HTML,
            ],
        ];
    }
}
