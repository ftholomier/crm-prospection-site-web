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

    /** Prompt système commun : règles du projet + offre commerciale. */
    private static function systemPrompt(array $prospect): string
    {
        $rules = trim((string) Config::get('design.global_prompt', Config::defaultDesignPrompt()));

        $constraints = [];
        if (!Config::get('design.allow_google_fonts', true)) {
            $constraints[] = "Les polices Google Fonts sont interdites : utilise uniquement des piles de polices système.";
        }
        if (!Config::get('design.use_site_images', true)) {
            $constraints[] = "N'utilise aucune photo du site d'origine : remplace-les par des compositions CSS ou du SVG inline.";
        }

        $custom = trim((string) ($prospect['design_prompt'] ?? ''));
        if ($custom !== '') {
            $constraints[] = "Consignes spécifiques à ce prospect, prioritaires sur le reste :\n" . $custom;
        }

        return "Tu es directeur artistique et intégrateur web. Tu produis des maquettes de site en HTML et CSS purs.\n\n"
            . $rules
            . ($constraints !== [] ? "\n\nCONTRAINTES SUPPLÉMENTAIRES\n- " . implode("\n- ", $constraints) : '');
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

    /** Schéma du brief, imposé au modèle via les sorties structurées. */
    private static function briefSchema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];
        $sections = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string'],
                    'titre' => ['type' => 'string'],
                    'contenu' => ['type' => 'string'],
                ],
                'required' => ['type', 'titre', 'contenu'],
                'additionalProperties' => false,
            ],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'entreprise' => ['type' => 'string'],
                'accroche' => ['type' => 'string'],
                'secteur' => ['type' => 'string'],
                'ton' => ['type' => 'string'],
                'palette' => [
                    'type' => 'object',
                    'properties' => [
                        'primaire' => ['type' => 'string'],
                        'secondaire' => ['type' => 'string'],
                        'accent' => ['type' => 'string'],
                        'fond' => ['type' => 'string'],
                        'surface' => ['type' => 'string'],
                        'texte' => ['type' => 'string'],
                        'texte_secondaire' => ['type' => 'string'],
                    ],
                    'required' => ['primaire', 'secondaire', 'accent', 'fond', 'surface', 'texte', 'texte_secondaire'],
                    'additionalProperties' => false,
                ],
                'polices' => [
                    'type' => 'object',
                    'properties' => [
                        'titres' => ['type' => 'string'],
                        'texte' => ['type' => 'string'],
                        'import_google_fonts' => ['type' => 'string'],
                    ],
                    'required' => ['titres', 'texte', 'import_google_fonts'],
                    'additionalProperties' => false,
                ],
                'prestations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'titre' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['titre', 'description'],
                        'additionalProperties' => false,
                    ],
                ],
                'photos_retenues' => $stringArray,
                'appels_action' => [
                    'type' => 'object',
                    'properties' => [
                        'principal' => ['type' => 'string'],
                        'secondaire' => ['type' => 'string'],
                    ],
                    'required' => ['principal', 'secondaire'],
                    'additionalProperties' => false,
                ],
                'plan_accueil' => $sections,
                'plan_a_propos' => $sections,
                'plan_prestations' => $sections,
                'pied_de_page' => ['type' => 'string'],
            ],
            'required' => [
                'entreprise', 'accroche', 'secteur', 'ton', 'palette', 'polices',
                'prestations', 'photos_retenues', 'appels_action',
                'plan_accueil', 'plan_a_propos', 'plan_prestations', 'pied_de_page',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Étape 1 : produit le brief de direction artistique.
     * @return array{ok:bool,error:string,brief:array,usage:array}
     */
    public static function brief(array $prospect, string $instruction = ''): array
    {
        $profile = self::siteProfile($prospect);

        $content = [];
        $shot = Config::get('screenshot.send_to_model', true)
            ? Screenshot::toImageBlock((string) $prospect['id'])
            : null;
        if ($shot !== null) {
            $content[] = $shot;
            $content[] = [
                'type' => 'text',
                'text' => "L'image ci-dessus est la capture du site actuel de l'entreprise. Analyse sa direction artistique réelle pour t'en inspirer sur l'univers et t'en démarquer sur la qualité d'exécution.",
            ];
        }

        $task = "Voici l'analyse du site actuel d'une entreprise, au format JSON :\n\n"
            . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nÉtablis le brief de direction artistique de sa refonte en trois pages : accueil, à propos, prestations."
            . "\n\nAttendus :"
            . "\n- Une palette cohérente en codes hexadécimaux, dérivée des couleurs de marque quand elles sont identifiables."
            . "\n- Un couple de polices Google Fonts et la balise <link> d'import correspondante (champ import_google_fonts). Laisse ce champ vide si tu choisis des polices système."
            . "\n- Les prestations réellement proposées par l'entreprise, reformulées, jamais inventées."
            . "\n- Le plan détaillé de chaque page : pour chaque section, son type, son titre et le texte définitif à afficher."
            . "\n- Parmi photos_disponibles, ne retiens dans photos_retenues que les URL réellement exploitables (photos de fond ou d'illustration, jamais les logos ni les icônes).";

        if (trim($instruction) !== '') {
            $task .= "\n\nConsignes complémentaires de l'utilisateur, prioritaires :\n" . trim($instruction);
        }

        $content[] = ['type' => 'text', 'text' => $task];

        $result = Claude::message([
            'system' => self::systemPrompt($prospect),
            'messages' => [['role' => 'user', 'content' => $content]],
            'schema' => self::briefSchema(),
            'max_tokens' => 16000,
        ]);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'brief' => [], 'usage' => []];
        }
        $brief = $result['json'];
        if (!is_array($brief) || empty($brief['entreprise'])) {
            return ['ok' => false, 'error' => 'Le brief renvoyé est inexploitable.', 'brief' => [], 'usage' => $result['usage']];
        }
        return ['ok' => true, 'error' => '', 'brief' => $brief, 'usage' => $result['usage']];
    }

    /**
     * Étape 2 : produit une page complète, en streaming.
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

        $context = [
            'entreprise' => $brief['entreprise'] ?? '',
            'accroche' => $brief['accroche'] ?? '',
            'ton' => $brief['ton'] ?? '',
            'palette' => $brief['palette'] ?? [],
            'polices' => $brief['polices'] ?? [],
            'prestations' => $brief['prestations'] ?? [],
            'photos_retenues' => $brief['photos_retenues'] ?? [],
            'appels_action' => $brief['appels_action'] ?? [],
            'pied_de_page' => $brief['pied_de_page'] ?? '',
            'plan_de_cette_page' => $brief[$planKey] ?? [],
        ];

        $contact = array_filter([
            'telephone' => $prospect['phone'] ?? '',
            'email' => $prospect['email'] ?? '',
            'ville' => $prospect['city'] ?? '',
        ]);
        if ($contact !== []) {
            $context['coordonnees'] = $contact;
        }

        $task = "Brief de direction artistique commun aux trois pages :\n\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nProduis maintenant la page « " . $label . " » du site."
            . "\n\nExigences :"
            . "\n- Un document HTML complet et autonome, du <!DOCTYPE html> à </html>."
            . "\n- Applique rigoureusement la palette et les polices du brief : les trois pages doivent partager la même charte."
            . "\n- En-tête et pied de page identiques d'une page à l'autre, navigation vers accueil.html, a-propos.html et prestations.html."
            . "\n- Marque le lien de la page courante avec aria-current=\"page\" et un style distinctif."
            . "\n- Réponds uniquement avec le code HTML, sans commentaire introductif ni bloc Markdown.";

        if ($currentHtml !== null && trim($currentHtml) !== '') {
            $task = "Voici la version actuelle de la page « " . $label . " » :\n\n"
                . $currentHtml
                . "\n\n---\n\n"
                . "Brief de direction artistique en vigueur :\n\n"
                . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                . "\n\nApplique la demande de modification suivante :\n" . trim($instruction)
                . "\n\nConserve tout ce qui n'est pas concerné par la demande : structure, charte, textes et navigation restent identiques."
                . "\n\nRéponds uniquement avec le code HTML complet de la page modifiée, sans commentaire ni bloc Markdown.";
        } elseif (trim($instruction) !== '') {
            $task .= "\n\nConsignes complémentaires de l'utilisateur, prioritaires :\n" . trim($instruction);
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

        $html = Mockup::sanitizeOutput($result['text']);
        return ['ok' => true, 'error' => '', 'html' => $html, 'usage' => $result['usage']];
    }
}
