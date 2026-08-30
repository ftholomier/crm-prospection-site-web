<?php
declare(strict_types=1);

namespace App;

/**
 * Lecture d'un site par l'outil serveur « web_fetch ».
 *
 * La requête part de l'infrastructure d'Anthropic, pas de notre serveur : un
 * pare-feu qui filtre l'adresse IP de l'hébergement ne la bloque donc pas.
 * C'est le recours quand le scraping direct est refusé — et il lit tout le
 * site, pas seulement la page d'accueil.
 *
 * Le modèle ne juge rien : il rapporte ce qu'il lit. Le score de vétusté reste
 * calculé par nos propres règles, sur du HTML réel.
 */
final class SiteReader
{
    private const MAX_PAGES = 6;
    private const MAX_CONTENT_TOKENS = 60000;

    /** Variantes de l'outil, de la plus récente à la plus ancienne. */
    private const TOOL_VERSIONS = ['web_fetch_20260209', 'web_fetch_20250910'];

    /**
     * Cette lecture passe toujours par Claude, quel que soit le fournisseur
     * choisi pour la génération : elle repose sur un outil serveur propre à
     * Anthropic. Une clé Claude renseignée suffit donc, même si les maquettes
     * sont produites par DeepSeek.
     */
    public static function isAvailable(): bool
    {
        return Claude::isConfigured();
    }

    /** Modèle employé, pour l'annoncer avant de dépenser. */
    public static function model(): string
    {
        return Ai::modelFor('lecture');
    }

    /**
     * Lit le site et en extrait le contenu exploitable.
     * @return array{ok:bool,error:string,data:array,pages:array,usage:array}
     */
    public static function read(array $prospect, ?callable $notify = null): array
    {
        $say = static function (string $message, string $state = 'running') use ($notify): void {
            if ($notify !== null) {
                $notify($message, $state);
            }
        };

        $url = (string) $prospect['url'];
        $domain = Util::domain($url);

        $say('Lecture du site par l\'IA depuis l\'infrastructure Anthropic');

        $result = null;
        foreach (self::TOOL_VERSIONS as $index => $version) {
            // Par la façade : le modèle vient du réglage de l'étape « lecture »,
            // et les jetons entrent dans le relevé de consommation. En passant
            // par Claude en direct, cette étape — la plus lourde en entrée de
            // toute l'application — n'y figurait pas.
            $result = Ai::withServerTools([
                'etape' => 'lecture',
                'system' => self::systemPrompt(),
                'messages' => [['role' => 'user', 'content' => self::task($url, $domain)]],
                'tools' => [self::tool($version, $domain)],
                'max_tokens' => 16000,
            ]);
            if ($result['ok'] || !self::isToolVersionError($result['error'])) {
                break;
            }
            $say('Outil de lecture non disponible dans cette version, nouvelle tentative', 'warn');
        }

        if ($result === null || !$result['ok']) {
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'Lecture impossible.',
                'data' => [],
                'pages' => [],
                'usage' => $result['usage'] ?? [],
            ];
        }

        $fetched = self::fetchedUrls($result['blocks']);
        if ($fetched !== []) {
            $say(count($fetched) . ' page(s) lue(s) : ' . implode(', ', array_map(
                static fn (string $u): string => self::shortPath($u),
                array_slice($fetched, 0, self::MAX_PAGES)
            )), 'done');
        }

        $profile = $result['json'];
        if (!is_array($profile) || trim((string) ($profile['entreprise'] ?? '')) === '') {
            $blocked = self::blockedByTarget($result['blocks']);
            return [
                'ok' => false,
                'error' => $blocked
                    ? 'Le site a également refusé la lecture par l\'IA. Utilisez la saisie manuelle.'
                    : 'La lecture n\'a rien renvoyé d\'exploitable.',
                'data' => [],
                'pages' => $fetched,
                'usage' => $result['usage'],
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'data' => self::toAnalysis($profile, $url, $fetched),
            'pages' => $fetched,
            'usage' => $result['usage'],
        ];
    }

    private static function tool(string $version, string $domain): array
    {
        return [
            'type' => $version,
            'name' => 'web_fetch',
            'max_uses' => self::MAX_PAGES,
            // Le modèle ne sort pas du domaine du prospect.
            'allowed_domains' => [$domain, 'www.' . $domain],
            'max_content_tokens' => self::MAX_CONTENT_TOKENS,
        ];
    }

    private static function systemPrompt(): string
    {
        return "Tu es un assistant d'analyse de sites web d'entreprise. Tu lis les pages qu'on te "
            . "demande et tu rapportes fidèlement ce qu'elles contiennent.\n\n"
            . "RÈGLE ABSOLUE : tu n'inventes rien. Une information absente du site reste vide dans ta "
            . "réponse. Tu ne déduis ni chiffre d'affaires, ni effectif, ni tarif, ni avis client, ni "
            . "récompense. Tu ne reformules pas les prestations en les enjolivant : tu les rapportes.";
    }

    private static function task(string $url, string $domain): string
    {
        $pages = self::MAX_PAGES;
        return <<<TXT
Lis le site {$url} et rapporte son contenu.

Marche à suivre :
1. Récupère la page d'accueil {$url}.
2. Depuis ses liens, récupère jusqu'à {$pages} pages internes utiles, en priorité : contact,
   mentions légales, à propos / qui sommes-nous, prestations / services / réalisations.
3. Reste sur le domaine {$domain}.

Réponds ensuite uniquement par un objet JSON valide, sans texte autour ni bloc Markdown,
suivant exactement cette structure :

{
  "entreprise": "raison sociale ou nom commercial exact",
  "accroche": "la phrase d'accroche du site, telle qu'elle est écrite",
  "secteur": "activité en quelques mots",
  "ville": "",
  "adresse": "",
  "telephone": "",
  "email": "",
  "siren": "",
  "horaires": "",
  "navigation": ["intitulés du menu"],
  "prestations": [{"titre": "", "description": "texte du site"}],
  "pages": [{"role": "accueil|contact|mentions|apropos|prestations|autre", "url": "", "titre": "", "contenu": "texte principal de la page, 1500 caractères maximum"}],
  "logo": "adresse absolue du fichier logo, telle qu'elle figure dans le code de la page",
  "images": [{"url": "adresse absolue d'une photo du site", "alt": "texte alternatif ou description brève", "role": "banniere|realisation|equipe|illustration|autre"}],
  "couleurs": ["codes hexadécimaux des couleurs de la charte, la principale en premier"],
  "polices": ["noms des familles de police utilisées"],
  "points_forts": ["arguments réellement mis en avant par l'entreprise"],
  "reseaux_sociaux": {"facebook": "", "instagram": "", "linkedin": ""},
  "annee_copyright": "",
  "remarques": "ce que tu n'as pas pu lire, ou toute limite rencontrée"
}

Pour « logo », « images », « couleurs » et « polices », lis le code source de la page :
les adresses figurent dans les attributs src, srcset, data-src et dans les propriétés CSS
background-image ; les couleurs dans les déclarations color, background et border du style
du site. Donne les adresses en absolu (https://…), jamais en relatif. Rapporte jusqu'à
quinze images, en écartant les pictogrammes, les icônes et les pixels de suivi. Pour les
couleurs, donne celles de la charte — celles des boutons, des liens et des aplats de marque —
et non les gris de texte ni les blancs de fond.

Laisse vide tout champ dont l'information ne figure pas sur le site.
TXT;
    }

    /** Convertit la réponse du modèle vers la structure d'analyse de l'application. */
    private static function toAnalysis(array $profile, string $url, array $fetched): array
    {
        $pages = [];
        $texts = [];
        foreach ($profile['pages'] ?? [] as $page) {
            $role = (string) ($page['role'] ?? 'autre');
            $pages[] = ['url' => (string) ($page['url'] ?? ''), 'title' => (string) ($page['titre'] ?? '')];
            $contenu = trim((string) ($page['contenu'] ?? ''));
            if ($contenu !== '') {
                $texts[$role === 'autre' ? 'page_' . count($texts) : $role] = $contenu;
            }
        }

        $services = [];
        foreach ($profile['prestations'] ?? [] as $prestation) {
            $titre = trim((string) ($prestation['titre'] ?? ''));
            if ($titre !== '') {
                $services[] = $titre;
            }
        }

        $email = trim((string) ($profile['email'] ?? ''));
        $phone = trim((string) ($profile['telephone'] ?? ''));

        return [
            'url' => $url,
            'domain' => Util::domain($url),
            'fetched_at' => time(),
            'source' => 'ia',
            'read_pages' => $fetched,
            'http' => [
                'status' => 200,
                'elapsed' => 0.0,
                'size' => 0,
                'https' => str_starts_with($url, 'https://'),
                'server' => '',
            ],
            'title' => (string) ($profile['accroche'] ?? ''),
            'description' => (string) ($profile['accroche'] ?? ''),
            'lang' => 'fr',
            'generator' => '',
            'company' => (string) ($profile['entreprise'] ?? ''),
            'headings' => ['h2' => $services],
            'navigation' => array_values(array_filter((array) ($profile['navigation'] ?? []))),
            'texts' => $texts,
            'images' => self::images($profile, $url),
            'colors' => self::colors($profile),
            'fonts' => array_values(array_filter(array_map(
                static fn ($f): string => trim((string) $f),
                (array) ($profile['polices'] ?? [])
            ))),
            'logo' => (string) (Util::absoluteUrl(trim((string) ($profile['logo'] ?? '')), $url) ?? ''),
            'contact' => [
                'emails' => $email !== '' ? [$email] : [],
                'email' => $email,
                'phones' => $phone !== '' ? [Util::formatPhone($phone)] : [],
                'phone' => $phone !== '' ? Util::formatPhone($phone) : '',
                'siren' => preg_replace('/\D/', '', (string) ($profile['siren'] ?? '')) ?? '',
                'city' => (string) ($profile['ville'] ?? ''),
                'legal_page' => '',
            ],
            'social' => array_filter((array) ($profile['reseaux_sociaux'] ?? [])),
            'pages_found' => $pages,
            'services' => $services,
            'prestations_detaillees' => $profile['prestations'] ?? [],
            'points_forts' => array_values(array_filter((array) ($profile['points_forts'] ?? []))),
            'secteur' => (string) ($profile['secteur'] ?? ''),
            'adresse' => (string) ($profile['adresse'] ?? ''),
            'horaires' => (string) ($profile['horaires'] ?? ''),
            'annee_copyright' => (string) ($profile['annee_copyright'] ?? ''),
            'remarques' => (string) ($profile['remarques'] ?? ''),
            'css_size' => 0,
            'raw' => ['home_html' => '', 'css' => []],
        ];
    }

    /**
     * Images rapportées par le modèle, ramenées à des adresses absolues.
     *
     * C'est ce qui manquait : sans média, la génération retirait tous les blocs
     * illustrés et rendait une maquette de texte, sur un site que nous n'avions
     * pourtant pas fini de lire.
     */
    private static function images(array $profile, string $base): array
    {
        $images = [];
        $vues = [];
        foreach ((array) ($profile['images'] ?? []) as $image) {
            $url = Util::absoluteUrl(trim((string) ($image['url'] ?? '')), $base);
            if ($url === null || isset($vues[$url]) || str_contains($url, 'data:image')) {
                continue;
            }
            $vues[$url] = true;
            $images[] = [
                'url' => $url,
                'alt' => Util::truncate((string) ($image['alt'] ?? ''), 120),
                'role' => (string) ($image['role'] ?? 'autre'),
                'modern' => (bool) preg_match('/\.(webp|avif)(\?|$)/i', $url),
            ];
        }
        return array_slice($images, 0, 25);
    }

    /** Couleurs rapportées, normalisées et débarrassées des neutres. */
    private static function colors(array $profile): array
    {
        $palette = [];
        foreach ((array) ($profile['couleurs'] ?? []) as $couleur) {
            $hex = Palette::normalize((string) $couleur);
            if ($hex !== null && !in_array($hex, ['#ffffff', '#000000'], true) && !in_array($hex, $palette, true)) {
                $palette[] = $hex;
            }
        }
        return [
            'palette' => $palette,
            'weights' => [],
            // Le modèle donne la principale en premier : c'est une désignation,
            // pas un comptage, et elle vaut mieux qu'une fréquence dans le CSS.
            'dominant' => $palette[0] ?? '',
        ];
    }

    /** URL réellement récupérées, d'après les blocs de résultat de l'outil. */
    private static function fetchedUrls(array $blocks): array
    {
        $urls = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'web_fetch_tool_result') {
                $content = $block['content'] ?? [];
                $url = $content['url'] ?? ($content['document']['source']['url'] ?? '');
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    /** Le site a-t-il refusé la lecture, y compris depuis l'infrastructure d'Anthropic ? */
    private static function blockedByTarget(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'web_fetch_tool_result') {
                continue;
            }
            $content = $block['content'] ?? [];
            // Un échec d'outil renvoie un objet d'erreur en lieu et place du document.
            if (isset($content['error_code']) || isset($content['type']) && $content['type'] === 'web_fetch_tool_error') {
                return true;
            }
        }
        return false;
    }

    private static function isToolVersionError(string $error): bool
    {
        $error = mb_strtolower($error);
        return str_contains($error, 'web_fetch') || str_contains($error, 'tool')
            || str_contains($error, 'not supported') || str_contains($error, 'invalid');
    }

    private static function shortPath(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return $path === '' || $path === '/' ? 'accueil' : trim($path, '/');
    }
}
