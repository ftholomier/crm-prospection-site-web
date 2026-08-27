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
                'subject' => "{{societe}} : j'ai refait votre site, regardez",
                'enabled' => true,
                'body' => <<<HTML
<p>Bonjour{{#si prenom}} {{prenom}}{{/si}},</p>

<p>Je suis tombé sur <strong>{{domaine}}</strong>{{#si ville}} en cherchant des entreprises à {{ville}}{{/si}}. Votre site fait le travail, mais il porte visiblement son âge :</p>

{{constats_liste}}

<p>Plutôt que de vous l'écrire, j'ai préféré vous le montrer. J'ai refait trois pages de votre site — l'accueil, la page à propos et vos prestations — à partir de votre activité réelle. Rien d'inventé, rien de générique : vos métiers, vos textes, vos coordonnées.</p>

<p><a class="bouton" href="{{lien_maquette}}">Voir les 3 pages de {{societe}}</a></p>

<p><strong>Ces trois pages ne sont qu'un échantillon.</strong> Si la direction vous plaît, c'est l'intégralité de votre site que je refais ainsi : toutes vos pages, tous vos contenus repris, la même exigence sur ordinateur comme sur téléphone.</p>

<p>Le tout pour <strong>{{tarif}} par mois</strong>, sans facture de création à régler d'avance, et tout compris :</p>

{{inclus_liste}}

<p>Et <strong>aucune durée minimum</strong> : vous arrêtez quand vous voulez, sans justification et sans frais. C'est à moi de vous donner envie de rester, pas à un contrat de vous retenir.</p>

<p>Dites-moi ce que vous en pensez — même si la réponse est non, ça m'intéresse de savoir pourquoi.</p>

{{signature}}
HTML,
            ],
            2 => [
                'subject' => "Vous avez pu regarder les 3 pages ?",
                'enabled' => true,
                'body' => <<<HTML
<p>Bonjour{{#si prenom}} {{prenom}}{{/si}},</p>

<p>Je remonte mon message : je vous avais envoyé trois pages du site de {{societe}}, refaites de zéro à partir de votre activité.</p>

<p>Elles sont toujours en ligne. Rien à installer, rien à signer pour les regarder :</p>

<p><a class="bouton" href="{{lien_maquette}}">Revoir les 3 pages</a></p>

<p>Pour situer la suite : si ces pages vous conviennent, je reprends <strong>tout votre site</strong> sur ce modèle — chaque page existante remise en forme, sans que vous ayez quoi que ce soit à ressaisir. <strong>{{tarif}} par mois</strong>, hébergement, sauvegardes, mises à jour techniques et modifications de contenu comprises. Sans engagement de durée.</p>

<p>Un mot suffit, même pour me dire que ce n'est pas le moment.</p>

{{signature}}
HTML,
            ],
            3 => [
                'subject' => "Je referme le dossier {{societe}}",
                'enabled' => true,
                'body' => <<<HTML
<p>Bonjour{{#si prenom}} {{prenom}}{{/si}},</p>

<p>Sans retour de votre part, je considère que le sujet n'est pas d'actualité — c'est parfaitement entendu, et c'est mon dernier message.</p>

<p>Je laisse les trois pages accessibles encore quelques jours, au cas où vous voudriez les montrer autour de vous :</p>

<p><a class="bouton" href="{{lien_maquette}}">Voir les pages une dernière fois</a></p>

<p>Pour mémoire, si le sujet revient : le site complet refait et entretenu pour {{tarif}} par mois, sans engagement, résiliable quand vous voulez. Répondez simplement à cet email, dans six mois ou dans deux ans — je remettrai le travail à jour.</p>

<p>Bonne continuation à {{societe}}.</p>

{{signature}}
HTML,
            ],
        ];
    }
}