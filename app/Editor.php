<?php
declare(strict_types=1);

namespace App;

/**
 * Édition directe d'une maquette, sans repasser par le modèle.
 *
 * Une maquette générée est un document HTML, pas un formulaire : pour la
 * rendre modifiable, on la parcourt et on expose ce qui se modifie sans risque
 * — les textes qui n'ont pas d'enfant, les images, les liens. Tout le reste,
 * structure et classes, reste hors de portée : c'est ce qui garantit qu'une
 * retouche ne peut pas casser la mise en page du socle.
 *
 * Chaque champ porte un chemin d'index d'éléments depuis <body> : « 1/0/2 »
 * désigne le troisième élément-enfant du premier élément-enfant du deuxième
 * élément du corps. Les nœuds de texte ne comptent pas, ce qui rend le chemin
 * insensible à l'indentation du document.
 */
final class Editor
{
    /** Balises dont le contenu textuel est modifiable. */
    private const TEXTE = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'p', 'span', 'a', 'li', 'summary',
        'address', 'figcaption', 'blockquote', 'strong', 'em', 'td', 'th', 'button',
    ];

    /** Longueur au-delà de laquelle le champ passe en zone de texte. */
    private const SEUIL_LONG = 90;

    /**
     * Champs modifiables d'une page, groupés par section.
     *
     * @return array<int, array{cle:string,titre:string,champs:array}>
     */
    public static function groupes(string $html): array
    {
        $doc = Scraper::parse('<!doctype html><html><body>' . Mockup::bodyOf($html) . '</body></html>');
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return [];
        }

        $groupes = [];
        foreach (self::enfantsElements($body) as $rang => $bloc) {
            // Les sections vivent dans <main> : on descend d'un cran pour que
            // chaque section forme son propre groupe plutôt qu'un bloc unique.
            $racines = strtolower($bloc->nodeName) === 'main'
                ? self::enfantsElements($bloc)
                : [$bloc];
            $prefixe = strtolower($bloc->nodeName) === 'main' ? $rang . '/' : '';

            foreach ($racines as $sousRang => $section) {
                // La bascule de menu et le voile sont des pièces de maquette,
                // pas du contenu : les proposer à l'édition ferait corriger le
                // libellé d'un dispositif qui ne sera pas livré.
                if (self::estDispositif($section)) {
                    continue;
                }
                $chemin = $prefixe === '' ? (string) $rang : $prefixe . $sousRang;
                $champs = [];
                self::collecter($section, $chemin, $champs);
                if ($champs === []) {
                    continue;
                }
                $groupes[] = [
                    'cle' => $chemin,
                    'titre' => self::titreDe($section),
                    'champs' => $champs,
                ];
            }
        }
        return $groupes;
    }

    /** Tous les champs à plat, pour la validation à l'enregistrement. */
    public static function champs(string $html): array
    {
        $plat = [];
        foreach (self::groupes($html) as $groupe) {
            foreach ($groupe['champs'] as $champ) {
                $plat[$champ['chemin'] . '#' . $champ['type']] = $champ;
            }
        }
        return $plat;
    }

    /**
     * Applique les modifications au document et renvoie le HTML complet.
     *
     * Seuls les chemins réellement présents sont touchés, et seuls les types
     * connus : une valeur qui ne correspond à rien est ignorée sans bruit
     * plutôt que d'insérer du contenu à un endroit imprévu.
     *
     * @param array<string, string> $patch chemin#type => valeur
     * @return array{html:string,appliques:int}
     */
    public static function apply(string $html, array $patch): array
    {
        $corps = Mockup::bodyOf($html);
        $doc = Scraper::parse('<!doctype html><html><body>' . $corps . '</body></html>');
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return ['html' => $html, 'appliques' => 0];
        }

        $appliques = 0;
        foreach ($patch as $cle => $valeur) {
            [$chemin, $type] = array_pad(explode('#', (string) $cle, 2), 2, 'texte');
            $noeud = self::resoudre($body, (string) $chemin);
            if ($noeud === null) {
                continue;
            }
            if (self::poser($noeud, $type, (string) $valeur)) {
                $appliques++;
            }
        }

        return ['html' => self::remplacerCorps($html, self::corpsDe($doc, $body)), 'appliques' => $appliques];
    }

    // ------------------------------------------------------------ Collecte

    /** @param array<int, array> $champs */
    private static function collecter(\DOMElement $element, string $chemin, array &$champs): void
    {
        foreach (self::enfantsElements($element) as $rang => $enfant) {
            $sousChemin = $chemin . '/' . $rang;
            $balise = strtolower($enfant->nodeName);

            if ($balise === 'img') {
                $champs[] = [
                    'chemin' => $sousChemin,
                    'type' => 'image',
                    'label' => 'Image',
                    'valeur' => $enfant->getAttribute('src'),
                    'a_pourvoir' => Assets::isPlaceholder($enfant->getAttribute('src')),
                    'long' => false,
                ];
                // Le texte alternatif n'est pas un détail : c'est lui que lit
                // un moteur de recherche, et lui qui s'affiche si l'image
                // manque — ce qui arrive quand elle est pointée à distance.
                $champs[] = [
                    'chemin' => $sousChemin,
                    'type' => 'alt',
                    'label' => 'Description de l\'image',
                    'valeur' => $enfant->getAttribute('alt'),
                    'long' => false,
                ];
                continue;
            }

            $enfantsElements = self::enfantsElements($enfant);

            // Un lien ou un bouton qui ne contient que du texte est modifiable
            // des deux côtés : ce qu'il dit, et où il mène.
            if ($balise === 'a' && $enfantsElements === []) {
                $texte = trim($enfant->textContent);
                if ($texte !== '') {
                    $champs[] = self::champTexte($enfant, $sousChemin, $texte);
                    $champs[] = [
                        'chemin' => $sousChemin,
                        'type' => 'lien',
                        'label' => 'Destination du lien',
                        'valeur' => $enfant->getAttribute('href'),
                        'long' => false,
                    ];
                }
                continue;
            }

            if ($enfantsElements === [] && in_array($balise, self::TEXTE, true)) {
                $texte = trim($enfant->textContent);
                if ($texte !== '') {
                    $champs[] = self::champTexte($enfant, $sousChemin, $texte);
                }
                continue;
            }

            self::collecter($enfant, $sousChemin, $champs);
        }
    }

    private static function champTexte(\DOMElement $element, string $chemin, string $texte): array
    {
        return [
            'chemin' => $chemin,
            'type' => 'texte',
            'label' => self::labelDe($element),
            'valeur' => $texte,
            'long' => mb_strlen($texte) > self::SEUIL_LONG,
        ];
    }

    /** Nom lisible d'un champ, tiré de sa classe de socle ou de sa balise. */
    private static function labelDe(\DOMElement $element): string
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        foreach ($classes as $classe) {
            if (isset(self::LABELS[$classe])) {
                return self::LABELS[$classe];
            }
        }
        return self::LABELS_BALISE[strtolower($element->nodeName)] ?? 'Texte';
    }

    private const LABELS = [
        'heros__titre' => 'Titre du bandeau',
        'heros__texte' => 'Texte du bandeau',
        'titre-section' => 'Titre de section',
        'section__chapo' => 'Chapô',
        'surtitre' => 'Sur-titre',
        'carte__titre' => 'Titre de la carte',
        'carte__texte' => 'Texte de la carte',
        'etape__numero' => 'Numéro',
        'etape__titre' => 'Titre de l\'étape',
        'etape__texte' => 'Texte de l\'étape',
        'indicateur__chiffre' => 'Chiffre',
        'indicateur__label' => 'Légende du chiffre',
        'citation__texte' => 'Citation',
        'citation__auteur' => 'Auteur de la citation',
        'point__titre' => 'Titre du point',
        'point__texte' => 'Texte du point',
        'galerie__legende' => 'Légende de la photo',
        'entete__logo' => 'Nom dans l\'en-tête',
        'entete__tel' => 'Téléphone de l\'en-tête',
        'pied__tel' => 'Téléphone du pied de page',
        'pied__marque' => 'Nom dans le pied de page',
        'pied__adresse' => 'Adresse',
        'btn' => 'Bouton',
        'lien-fleche' => 'Lien',
        'citation-libre' => 'Citation',
        'faq__question' => 'Question',
        'faq__reponse' => 'Réponse',
    ];

    private const LABELS_BALISE = [
        'h1' => 'Titre principal',
        'h2' => 'Titre',
        'h3' => 'Sous-titre',
        'h4' => 'Sous-titre',
        'p' => 'Paragraphe',
        'li' => 'Élément de liste',
        'address' => 'Adresse',
        'summary' => 'Question',
        'blockquote' => 'Citation',
        'a' => 'Lien',
        'span' => 'Mention',
    ];

    /**
     * Intitulé d'une section : son rôle dans la page, et son titre réel quand
     * elle en a un. Le rôle seul ne distingue pas trois sections identiques ;
     * le titre seul fait passer le pied de page pour une section de contenu.
     */
    private static function titreDe(\DOMElement $section): string
    {
        $role = '';
        $classes = preg_split('/\s+/', trim($section->getAttribute('class'))) ?: [];
        foreach ($classes as $classe) {
            if (isset(self::TITRES_SECTION[$classe])) {
                $role = self::TITRES_SECTION[$classe];
                break;
            }
        }

        $titre = '';
        foreach (['h1', 'h2', 'h3'] as $balise) {
            $titres = $section->getElementsByTagName($balise);
            if ($titres->length > 0 && trim($titres->item(0)->textContent) !== '') {
                $titre = Util::truncate(trim($titres->item(0)->textContent), 52);
                break;
            }
        }

        return match (true) {
            $role !== '' && $titre !== '' => $role . ' — ' . $titre,
            $role !== '' => $role,
            $titre !== '' => $titre,
            default => ucfirst(strtolower($section->nodeName)),
        };
    }

    /** Éléments qui servent la démonstration et non le site livré. */
    private static function estDispositif(\DOMElement $element): bool
    {
        $classe = ' ' . strtolower($element->getAttribute('class')) . ' ';
        foreach ([' bascule-menu ', ' voile '] as $marqueur) {
            if (str_contains($classe, $marqueur)) {
                return true;
            }
        }
        return false;
    }

    private const TITRES_SECTION = [
        'section' => 'Section',
        'entete' => 'En-tête',
        'heros' => 'Bandeau',
        'indicateurs' => 'Chiffres clés',
        'citation' => 'Citation',
        'bande-cta' => 'Appel à l\'action',
        'pied' => 'Pied de page',
        'evitement' => 'Lien d\'évitement',
        'galerie' => 'Galerie',
        'panneau' => 'Menu latéral',
    ];

    // ------------------------------------------------------------ Écriture

    /**
     * Pose une valeur, et dit si elle a réellement changé quelque chose.
     *
     * Le formulaire renvoie tous les champs à chaque enregistrement : compter
     * les écritures ferait annoncer « 118 modifications » pour un mot corrigé.
     */
    private static function poser(\DOMElement $noeud, string $type, string $valeur): bool
    {
        switch ($type) {
            case 'texte':
                if (self::enfantsElements($noeud) !== [] || trim($noeud->textContent) === $valeur) {
                    return false;
                }
                // Le texte est posé comme texte, jamais comme HTML : un collage
                // depuis un traitement de texte n'injecte donc aucune balise.
                while ($noeud->firstChild !== null) {
                    $noeud->removeChild($noeud->firstChild);
                }
                $noeud->appendChild($noeud->ownerDocument->createTextNode($valeur));
                return true;

            case 'lien':
                if (strtolower($noeud->nodeName) !== 'a' || !self::hrefAcceptable($valeur)
                    || $noeud->getAttribute('href') === $valeur) {
                    return false;
                }
                $noeud->setAttribute('href', $valeur);
                return true;

            case 'image':
                if (strtolower($noeud->nodeName) !== 'img' || !self::srcAcceptable($valeur)
                    || $noeud->getAttribute('src') === $valeur) {
                    return false;
                }
                $noeud->setAttribute('src', $valeur);
                return true;

            case 'alt':
                if (strtolower($noeud->nodeName) !== 'img' || $noeud->getAttribute('alt') === $valeur) {
                    return false;
                }
                $noeud->setAttribute('alt', $valeur);
                return true;
        }
        return false;
    }

    /**
     * Une destination de lien doit rester inoffensive : la page est servie à un
     * tiers depuis notre domaine, un « javascript: » y serait exécutable.
     */
    private static function hrefAcceptable(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return true;
        }
        return (bool) preg_match('~^(https?://|mailto:|tel:)~i', $href)
            || (bool) preg_match('~^[a-z0-9._/-]+\.html$~i', $href);
    }

    /** Une image vient de notre dossier d'actifs, ou d'une adresse http(s). */
    private static function srcAcceptable(string $src): bool
    {
        $src = trim($src);
        return (bool) preg_match('~^assets/[a-z0-9._-]+$~i', $src)
            || (bool) preg_match('~^https?://~i', $src);
    }

    // ------------------------------------------------------------ Parcours

    /** @return array<int, \DOMElement> */
    private static function enfantsElements(\DOMElement $element): array
    {
        $enfants = [];
        foreach ($element->childNodes as $noeud) {
            if ($noeud instanceof \DOMElement) {
                $enfants[] = $noeud;
            }
        }
        return $enfants;
    }

    private static function resoudre(\DOMElement $racine, string $chemin): ?\DOMElement
    {
        $noeud = $racine;
        foreach (explode('/', $chemin) as $index) {
            if (!is_numeric($index)) {
                return null;
            }
            $enfants = self::enfantsElements($noeud);
            if (!isset($enfants[(int) $index])) {
                return null;
            }
            $noeud = $enfants[(int) $index];
        }
        return $noeud === $racine ? null : $noeud;
    }

    /** Sérialise le contenu du corps sans l'enveloppe ajoutée pour l'analyse. */
    private static function corpsDe(\DOMDocument $doc, \DOMElement $body): string
    {
        $html = '';
        foreach ($body->childNodes as $noeud) {
            $html .= $doc->saveHTML($noeud);
        }
        return $html;
    }

    /** Réinjecte le corps modifié dans le document complet. */
    private static function remplacerCorps(string $html, string $corps): string
    {
        $remplace = preg_replace(
            '~(<body\b[^>]*>).*(</body>)~is',
            '$1' . "\n" . str_replace('$', '\\$', $corps) . "\n" . '$2',
            $html,
            1,
            $compte
        );
        return $remplace !== null && $compte > 0 ? $remplace : $html;
    }
}
