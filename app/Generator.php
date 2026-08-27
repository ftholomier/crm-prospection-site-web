<?php
declare(strict_types=1);

namespace App;

/**
 * Génération des maquettes en deux temps.
 *
 * 1. Un brief structuré (JSON) fixe l'identité : palette, typographie, ton,
 *    prestations réelles, plan de chaque page. Appel court et peu coûteux.
 * 2. Chaque page est ensuite produite séparément, en streaming, à partir de ce
 *    brief commun. Découper ainsi garantit une charte homogène entre les trois
 *    pages tout en gardant chaque requête sous les limites de temps d'un
 *    hébergement mutualisé.
 */
final class Generator
{
    /** Étapes exposées à l'interface, dans l'ordre d'exécution. */
    public static function steps(): array
    {
        return array_merge(
            ['brief' => 'Direction artistique'],
            array_map(static fn (string $label): string => 'Page ' . $label, Mockup::PAGES)
        );
    }

    /** Prompt système commun : discipline du socle + consignes de l'utilisateur. */
    private static function systemPrompt(array $prospect): string
    {
        $rules = trim((string) Config::get('design.global_prompt', Config::defaultDesignPrompt()));

        $constraints = [];
        if (!Config::get('design.use_site_images', true)) {
            $constraints[] = "Aucune photo : remplace les blocs illustrés par des sections de texte, ou supprime-les.";
        }

        $custom = trim((string) ($prospect['design_prompt'] ?? ''));
        if ($custom !== '') {
            $constraints[] = "Consignes spécifiques à ce prospect, prioritaires sur le reste :\n" . $custom;
        }

        return "Tu es directeur artistique et intégrateur web. Tu remplis un socle de maquettage existant.\n\n"
            . $rules
            . ($constraints !== [] ? "\n\nCONTRAINTES SUPPLÉMENTAIRES\n- " . implode("\n- ", $constraints) : '');
    }

    /**
     * Gabarit de référence d'une page : c'est lui qui fixe la structure.
     * Seul l'intérieur du <body> est transmis, le <head> étant assemblé ici.
     */
    public static function gabarit(string $page): string
    {
        $path = dirname(__DIR__) . '/app/Design/gabarits/' . Mockup::safePage($page) . '.html';
        $html = is_file($path) ? (string) file_get_contents($path) : '';
        return Mockup::bodyOf($html);
    }

    /** Profil condensé du site analysé, transmis au modèle en JSON compact. */
    public static function siteProfile(array $prospect): array
    {
        $analysis = $prospect['analysis'] ?? [];
        $audit = $prospect['audit'] ?? [];

        $images = [];
        foreach (array_slice($analysis['images'] ?? [], 0, 12) as $image) {
            if (($image['alt'] ?? '') !== '' || !preg_match('/(logo|icon|pixel|spacer)/i', $image['url'] ?? '')) {
                $images[] = ['url' => $image['url'], 'alt' => $image['alt']];
            }
        }

        return array_filter([
            'entreprise' => $prospect['company'] ?: ($analysis['company'] ?? ''),
            'domaine' => $prospect['domain'] ?? '',
            'ville' => $prospect['city'] ?: ($analysis['contact']['city'] ?? ''),
            'secteur' => $prospect['sector'] ?? '',
            'titre_actuel' => $analysis['title'] ?? '',
            'description_actuelle' => $analysis['description'] ?? '',
            'menu_actuel' => $analysis['navigation'] ?? [],
            'titres' => $analysis['headings'] ?? [],
            'prestations_reperees' => $analysis['services'] ?? [],
            'contenu_pages' => $analysis['texts'] ?? [],
            'couleurs_actuelles' => $analysis['colors']['palette'] ?? [],
            'polices_actuelles' => $analysis['fonts'] ?? [],
            'logo' => $analysis['logo'] ?? '',
            'photos_disponibles' => $images,
            'telephone' => $prospect['phone'] ?: ($analysis['contact']['phone'] ?? ''),
            'email' => $prospect['email'] ?: ($analysis['contact']['email'] ?? ''),
            'reseaux_sociaux' => $analysis['social'] ?? [],
            'defauts_du_site_actuel' => array_column($audit['findings'] ?? [], 'label'),
        ], static fn ($value): bool => $value !== '' && $value !== [] && $value !== null);
    }

    /**
     * Schéma du brief.
     *
     * Ni palette ni polices : elles sont calculées en PHP à partir des
     * couleurs relevées sur le site, avec les contrastes mesurés. Les
     * demander au modèle reviendrait à lui faire estimer à l'œil ce qui se
     * calcule, et à laisser passer du texte illisible.
     */
    private static function briefSchema(): array
    {
        $sections = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string'],
                    'titre' => ['type' => 'string'],
                    'contenu' => ['type' => 'string'],
                    'photo' => ['type' => 'string'],
                ],
                'required' => ['type', 'titre', 'contenu', 'photo'],
                'additionalProperties' => false,
            ],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'entreprise' => ['type' => 'string'],
                'baseline' => ['type' => 'string'],
                'accroche' => ['type' => 'string'],
                'secteur' => ['type' => 'string'],
                'ton' => ['type' => 'string'],
                'meta_titre' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'prestations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'titre' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'photo' => ['type' => 'string'],
                        ],
                        'required' => ['titre', 'description', 'photo'],
                        'additionalProperties' => false,
                    ],
                ],
                'chiffres' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'valeur' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'source' => ['type' => 'string'],
                        ],
                        'required' => ['valeur', 'label', 'source'],
                        'additionalProperties' => false,
                    ],
                ],
                'appels_action' => [
                    'type' => 'object',
                    'properties' => [
                        'principal' => ['type' => 'string'],
                        'secondaire' => ['type' => 'string'],
                    ],
                    'required' => ['principal', 'secondaire'],
                    'additionalProperties' => false,
                ],
                'sections_a_supprimer' => ['type' => 'array', 'items' => ['type' => 'string']],
                'plan_accueil' => $sections,
                'plan_a_propos' => $sections,
                'plan_prestations' => $sections,
                'pied_de_page' => ['type' => 'string'],
            ],
            'required' => [
                'entreprise', 'baseline', 'accroche', 'secteur', 'ton',
                'meta_titre', 'meta_description', 'prestations', 'chiffres',
                'appels_action', 'sections_a_supprimer',
                'plan_accueil', 'plan_a_propos', 'plan_prestations', 'pied_de_page',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Étape 1 : produit le brief éditorial.
     * @return array{ok:bool,error:string,brief:array,usage:array}
     */
    public static function brief(array $prospect, string $instruction = ''): array
    {
        $profile = self::siteProfile($prospect);
        $actifs = Assets::forPrompt((string) $prospect['id']);

        $content = [];
        $shot = Config::get('screenshot.send_to_model', true)
            ? Screenshot::toImageBlock((string) $prospect['id'])
            : null;
        if ($shot !== null) {
            $content[] = $shot;
            $content[] = [
                'type' => 'text',
                'text' => "L'image ci-dessus est la capture du site actuel de l'entreprise. Elle sert à comprendre son activité et son univers, pas à reproduire sa mise en page.",
            ];
        }

        $task = "Voici l'analyse du site actuel d'une entreprise, au format JSON :\n\n"
            . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nPhotos réellement disponibles pour la maquette :\n"
            . json_encode($actifs['photos'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nÉtablis le brief éditorial de sa refonte en trois pages : accueil, à propos, prestations."
            . "\n\nLa charte graphique n'est pas de ton ressort : la couleur, la police et la mise en page"
            . " sont déjà fixées par le socle du projet. Ne propose ni couleur ni police."
            . "\n\nAttendus :"
            . "\n- Les prestations réellement proposées par l'entreprise, reformulées, jamais inventées."
            . "\n- Dans « chiffres », uniquement des nombres que l'on peut justifier par une phrase du site :"
            . " le champ « source » cite l'extrait exact qui les atteste. Aucun chiffre sans source. Renvoie un"
            . " tableau vide plutôt qu'un chiffre inventé."
            . "\n- Le plan de chaque page, section par section : son type, son titre, le texte définitif,"
            . " et le champ « photo » renseigné avec l'une des adresses de la liste ci-dessus (ou vide)."
            . "\n- Dans « sections_a_supprimer », nomme les sections du gabarit qui n'ont pas de matière :"
            . " mieux vaut une page plus courte qu'une section remplie de vide.";

        if (trim($instruction) !== '') {
            $task .= "\n\nConsignes complémentaires de l'utilisateur, prioritaires :\n" . trim($instruction);
        }

        $content[] = ['type' => 'text', 'text' => $task];

        $send = static function (array $blocks) use ($prospect): array {
            return Claude::message([
                'system' => self::systemPrompt($prospect),
                'messages' => [['role' => 'user', 'content' => $blocks]],
                'schema' => self::briefSchema(),
                'max_tokens' => 16000,
            ]);
        };

        $result = $send($content);
        $notice = '';

        // La capture n'est qu'un appui : si elle fait rejeter la requête, on
        // repart sans elle plutôt que d'abandonner la génération.
        if (!$result['ok'] && $shot !== null && self::isImageError($result['error'])) {
            $notice = 'La capture du site a été refusée par l\'API : le brief est établi sans elle.';
            $result = $send(array_values(array_filter(
                $content,
                static fn (array $block): bool => ($block['type'] ?? '') !== 'image'
            )));
        }

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'brief' => [], 'usage' => [], 'notice' => $notice];
        }
        $brief = $result['json'];
        if (!is_array($brief) || empty($brief['entreprise'])) {
            return ['ok' => false, 'error' => 'Le brief renvoyé est inexploitable.', 'brief' => [], 'usage' => $result['usage'], 'notice' => $notice];
        }
        return ['ok' => true, 'error' => '', 'brief' => $brief, 'usage' => $result['usage'], 'notice' => $notice];
    }

    /** L'échec vient-il de l'image jointe plutôt que du prompt lui-même ? */
    private static function isImageError(string $error): bool
    {
        $error = mb_strtolower($error);
        foreach (['image', 'media_type', 'could not process'] as $needle) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Étape 2 : produit une page, en streaming.
     *
     * Le modèle n'écrit que l'intérieur du <body>, avec les classes du socle.
     * Le document, lui, est assemblé ici : c'est ce qui garantit que les trois
     * pages partagent exactement la même charte, et que la palette servie est
     * bien celle qui a été calculée, pas celle que le modèle aurait redessinée.
     *
     * @return array{ok:bool,error:string,html:string,usage:array}
     */
    public static function page(
        array $prospect,
        array $brief,
        string $page,
        ?callable $onDelta = null,
        ?string $currentHtml = null,
        string $instruction = ''
    ): array {
        $page = Mockup::safePage($page);
        $planKey = 'plan_' . str_replace('-', '_', $page);
        $label = Mockup::PAGES[$page];
        $actifs = Assets::forPrompt((string) $prospect['id']);

        $context = [
            'entreprise' => $brief['entreprise'] ?? '',
            'baseline' => $brief['baseline'] ?? '',
            'accroche' => $brief['accroche'] ?? '',
            'ton' => $brief['ton'] ?? '',
            'prestations' => $brief['prestations'] ?? [],
            'chiffres' => $brief['chiffres'] ?? [],
            'appels_action' => $brief['appels_action'] ?? [],
            'sections_a_supprimer' => $brief['sections_a_supprimer'] ?? [],
            'pied_de_page' => $brief['pied_de_page'] ?? '',
            'plan_de_cette_page' => $brief[$planKey] ?? [],
            'photos_disponibles' => $actifs['photos'] ?? [],
            'logo' => $actifs['logo']['src'] ?? '',
        ];

        $contact = array_filter([
            'telephone' => $prospect['phone'] ?? '',
            'email' => $prospect['email'] ?? '',
            'ville' => $prospect['city'] ?? '',
        ]);
        if ($contact !== []) {
            $context['coordonnees'] = $contact;
        }

        if ($currentHtml !== null && trim($currentHtml) !== '') {
            $task = "Voici le contenu actuel du <body> de la page « " . $label . " » :\n\n"
                . Mockup::bodyOf($currentHtml)
                . "\n\n---\n\nBrief en vigueur :\n\n"
                . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                . "\n\nApplique la demande de modification suivante :\n" . trim($instruction)
                . "\n\nConserve tout ce qui n'est pas concerné par la demande, et n'utilise que les classes déjà présentes."
                . "\n\n" . self::sortieAttendue();
        } else {
            $task = "GABARIT DE RÉFÉRENCE — page « " . $label . " ».\n"
                . "Cette structure est la norme du projet : ses classes, ses composants et l'ordre de ses\n"
                . "sections sont ceux du socle CSS. Elle est remplie d'un contenu de démonstration.\n\n"
                . "```html\n" . self::gabarit($page) . "\n```\n\n"
                . "BRIEF DE L'ENTREPRISE À MAQUETTER :\n\n"
                . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                . "\n\nProduis la page « " . $label . " » de cette entreprise en repartant du gabarit."
                . "\n\nRègles :"
                . "\n- Garde les mêmes classes, les mêmes composants et le même ordre de sections."
                . "\n- Remplace uniquement les textes, les photos (src et alt), les liens et les coordonnées."
                . "\n- Si une section n'a pas de matière réelle, SUPPRIME-LA entièrement. N'invente jamais un"
                . " chiffre, un témoignage, un label ou une récompense pour remplir un trou."
                . "\n- N'utilise comme src que les adresses listées dans photos_disponibles, telles quelles."
                . " Respecte l'orientation indiquée : une photo portrait ne va pas dans un bandeau panoramique."
                . " S'il n'y a pas assez de photos, supprime les blocs illustrés plutôt que d'en réutiliser une"
                . " trois fois."
                . "\n- N'ajoute aucune classe nouvelle, aucun style en ligne, aucune balise <style> ni <script>."
                . "\n- Navigation entre les pages : accueil.html, a-propos.html, prestations.html, avec"
                . " aria-current=\"page\" sur la page courante."
                . "\n\n" . self::sortieAttendue();

            if (trim($instruction) !== '') {
                $task .= "\n\nConsignes complémentaires de l'utilisateur, prioritaires :\n" . trim($instruction);
            }
        }

        $result = Claude::stream([
            'system' => self::systemPrompt($prospect),
            'messages' => [['role' => 'user', 'content' => $task]],
            'max_tokens' => (int) Config::get('claude.max_tokens', 24000),
        ], $onDelta);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'html' => '', 'usage' => []];
        }
        if (($result['stop_reason'] ?? '') === 'max_tokens') {
            return [
                'ok' => false,
                'error' => 'La page a été tronquée : augmentez « Tokens maximum » dans les Réglages.',
                'html' => '',
                'usage' => $result['usage'],
            ];
        }

        $corps = Mockup::bodyOf(Mockup::stripFence($result['text']));
        if (trim($corps) === '') {
            return ['ok' => false, 'error' => 'La page renvoyée est vide.', 'html' => '', 'usage' => $result['usage']];
        }

        return [
            'ok' => true,
            'error' => '',
            'html' => self::assemble($prospect, $brief, $page, $corps),
            'usage' => $result['usage'],
        ];
    }

    private static function sortieAttendue(): string
    {
        return "SORTIE ATTENDUE : uniquement le contenu intérieur de <body>, du premier élément au dernier."
            . " Pas de <!doctype>, pas de <html>, pas de <head>, pas de <body>, pas de bloc Markdown,"
            . " aucune phrase d'introduction.";
    }

    /**
     * Assemble le document final autour du corps produit par le modèle.
     *
     * Le <head> n'est jamais confié au modèle : c'est lui qui porte le socle
     * CSS et le bloc de palette calculé. Le construire ici rend impossible la
     * dérive d'une page à l'autre.
     */
    public static function assemble(array $prospect, array $brief, string $page, string $corps): string
    {
        $palette = self::palette($prospect);
        $actifs = Assets::catalogue((string) $prospect['id']);

        $entreprise = (string) ($brief['entreprise'] ?? $prospect['company'] ?? '');
        $titre = trim((string) ($brief['meta_titre'] ?? ''));
        if ($titre === '') {
            $titre = trim($entreprise . ' — ' . Mockup::PAGES[Mockup::safePage($page)]);
        }
        $description = (string) ($brief['meta_description'] ?? $brief['accroche'] ?? '');

        $head = '';
        $import = (string) ($palette['police_import'] ?? '');
        if ($import !== '') {
            $head .= '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
                . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
                . '<link rel="stylesheet" href="' . htmlspecialchars($import, ENT_QUOTES) . '">' . "\n";
        }
        $favicon = Assets::src($actifs['favicon'] ?? null);
        if ($favicon !== null) {
            $head .= '<link rel="icon" href="' . htmlspecialchars($favicon, ENT_QUOTES) . '">' . "\n";
        }

        // La palette est posée après le socle : elle en écrase les jetons de
        // marque sans toucher au reste de la feuille.
        $root = Palette::rootBlock($palette, (string) ($palette['police'] ?? ''), '');

        return "<!doctype html>\n<html lang=\"fr\">\n<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . htmlspecialchars($titre, ENT_QUOTES) . "</title>\n"
            . '<meta name="description" content="' . htmlspecialchars(mb_substr($description, 0, 180), ENT_QUOTES) . "\">\n"
            . "<meta name=\"robots\" content=\"noindex, nofollow\">\n"
            . $head
            . "<link rel=\"stylesheet\" href=\"socle.css\">\n"
            . "<style>\n" . $root . "\n</style>\n"
            . "</head>\n<body>\n"
            . trim($corps) . "\n"
            . "<script src=\"socle.js\"></script>\n"
            . "</body>\n</html>\n";
    }

    /**
     * Vocabulaire du socle : toutes les classes que sa feuille de style
     * déclare. Lue à la source plutôt que recopiée, pour qu'une évolution du
     * socle ne rende pas la vérification fausse en silence.
     */
    public static function vocabulaire(): array
    {
        static $classes = null;
        if ($classes !== null) {
            return $classes;
        }
        $css = @file_get_contents(dirname(__DIR__) . '/public/assets/maquette/socle.css');
        $classes = [];
        if ($css !== false && preg_match_all('/\.([a-zA-Z][\w-]*)/', $css, $m)) {
            $classes = array_fill_keys($m[1], true);
        }
        return $classes;
    }

    /**
     * Contrôle d'une page avant qu'elle ne parte au prospect.
     *
     * Le contraste n'est pas vérifié ici : il ne peut plus dévier, puisque les
     * couleurs viennent du bloc calculé en PHP et que le corps n'a pas le droit
     * d'en écrire. Ce qui se vérifie, c'est justement cette interdiction — plus
     * les inventions qui la contournent : une classe qui n'existe pas ne
     * s'affichera pas, une photo qui n'est pas dans le catalogue ne s'affichera
     * pas davantage.
     *
     * @return array{ok:bool,ecarts:string[]}
     */
    public static function verifier(string $html, array $actifs): array
    {
        $corps = Mockup::bodyOf($html);
        $ecarts = [];

        if (preg_match('/<style\b/i', $corps)) {
            $ecarts[] = 'Une balise <style> a été écrite dans la page : tout le style vient du socle.';
        }
        if (preg_match('/\sstyle\s*=/i', $corps)) {
            $ecarts[] = 'Un attribut style= a été posé sur un élément : aucun style en ligne n\'est admis.';
        }
        if (preg_match('/<script\b/i', $corps)) {
            $ecarts[] = 'Un script a été écrit dans la page : le socle gère seul les animations.';
        }
        if (preg_match('/#[0-9a-f]{3,6}\b|rgba?\s*\(/i', $corps)) {
            $ecarts[] = 'Une couleur est écrite en dur : les teintes viennent uniquement des jetons du socle.';
        }

        $connues = self::vocabulaire();
        $inconnues = [];
        if (preg_match_all('/class\s*=\s*"([^"]*)"/i', $corps, $m)) {
            foreach ($m[1] as $liste) {
                foreach (preg_split('/\s+/', trim($liste)) ?: [] as $classe) {
                    if ($classe !== '' && !isset($connues[$classe])) {
                        $inconnues[$classe] = true;
                    }
                }
            }
        }
        if ($inconnues !== []) {
            $ecarts[] = 'Classes inexistantes dans le socle, donc sans aucun style : '
                . implode(', ', array_slice(array_keys($inconnues), 0, 12)) . '.';
        }

        $autorisees = [];
        foreach ($actifs['photos'] ?? [] as $photo) {
            $autorisees[(string) $photo['src']] = true;
        }
        foreach (['logo', 'favicon'] as $cle) {
            if (isset($actifs[$cle]['src'])) {
                $autorisees[(string) $actifs[$cle]['src']] = true;
            }
        }
        $inventees = [];
        if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*"([^"]*)"/i', $corps, $m)) {
            foreach ($m[1] as $src) {
                if ($src === '' || str_starts_with($src, 'data:')) {
                    continue;
                }
                if (!isset($autorisees[$src])) {
                    $inventees[$src] = true;
                }
            }
        }
        if ($inventees !== []) {
            $ecarts[] = 'Photos qui n\'existent pas et resteront cassées : '
                . implode(', ', array_slice(array_keys($inventees), 0, 8))
                . '. Supprime les blocs illustrés faute de photo, ne devine pas une adresse.';
        }

        $manquants = array_values(array_filter(
            array_keys(Mockup::PAGES),
            static fn (string $page): bool => !str_contains($corps, $page . '.html')
        ));
        if (count($manquants) > 1) {
            $ecarts[] = 'La navigation ne mène pas aux trois pages : '
                . implode(', ', array_map(static fn (string $p): string => $p . '.html', $manquants)) . ' manquent.';
        }

        return ['ok' => $ecarts === [], 'ecarts' => $ecarts];
    }

    /**
     * Palette du prospect : celle calculée à l'analyse, recalculée à la volée
     * pour les fiches analysées avant la mise en place du socle.
     */
    public static function palette(array $prospect): array
    {
        $palette = $prospect['palette'] ?? [];
        if (is_array($palette) && isset($palette['marque'], $palette['mesures'])) {
            return $palette;
        }
        return Palette::forAnalysis($prospect['analysis'] ?? []);
    }
}
