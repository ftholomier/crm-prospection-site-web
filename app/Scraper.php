<?php
declare(strict_types=1);

namespace App;

/**
 * Analyse du site prospect : récupère la page d'accueil et quelques pages clés
 * (contact, mentions légales, à propos, prestations), puis en extrait tout ce
 * qui sert à générer la maquette et à contacter l'entreprise.
 */
final class Scraper
{
    private const MAX_PAGES = 5;
    private const MAX_CSS = 3;

    /** Mots-clés servant à repérer les pages internes utiles. */
    private const PAGE_HINTS = [
        'contact' => ['contact', 'nous-contacter', 'coordonnees', 'devis'],
        'legal' => ['mentions', 'legal', 'cgv', 'cgu', 'politique'],
        'about' => ['a-propos', 'apropos', 'qui-sommes', 'entreprise', 'histoire', 'presentation', 'about'],
        'services' => ['service', 'prestation', 'realisation', 'savoir-faire', 'produit', 'activite', 'nos-'],
    ];

    /**
     * Analyse complète d'un site.
     * @return array{ok:bool,error:string,data:array}
     */
    public static function analyze(string $url, ?callable $progress = null): array
    {
        $notify = static function (string $message) use ($progress): void {
            if ($progress !== null) {
                $progress($message);
            }
        };

        $home = self::fetchHome($url, $notify);
        if (!$home['ok'] || trim($home['body']) === '') {
            return [
                'ok' => false,
                'error' => self::blockedMessage($home),
                'status' => $home['status'],
                'data' => [],
            ];
        }

        $finalUrl = $home['url'];
        $notify('Page d\'accueil récupérée (' . self::humanSize($home['size']) . ', ' . $home['elapsed'] . ' s)');

        $doc = self::parse($home['body']);
        $pages = ['accueil' => ['url' => $finalUrl, 'html' => $home['body'], 'title' => self::title($doc)]];

        $links = self::internalLinks($doc, $finalUrl);
        $targets = self::pickPages($links);

        foreach ($targets as $kind => $target) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
            $notify('Lecture de la page ' . $kind);
            $page = Http::get($target, 15);
            if ($page['ok'] && trim($page['body']) !== '') {
                $pages[$kind] = ['url' => $page['url'], 'html' => $page['body'], 'title' => self::title(self::parse($page['body']))];
            }
        }

        $notify('Extraction du contenu et de l\'identité visuelle');
        $css = self::fetchStylesheets($doc, $finalUrl);
        $allHtml = implode("\n", array_column($pages, 'html'));

        $data = [
            'url' => $finalUrl,
            'domain' => Util::domain($finalUrl),
            'fetched_at' => time(),
            'http' => [
                'status' => $home['status'],
                'elapsed' => $home['elapsed'],
                'size' => $home['size'],
                'https' => str_starts_with($finalUrl, 'https://'),
                'server' => $home['headers']['server'] ?? '',
            ],
            'title' => self::title($doc),
            'description' => self::meta($doc, 'description'),
            'lang' => self::attr($doc, 'html', 'lang'),
            'generator' => self::meta($doc, 'generator'),
            'company' => self::guessCompany($doc, $pages, $finalUrl),
            'headings' => self::headings($doc),
            'navigation' => self::navigation($doc),
            'texts' => self::texts($pages),
            'images' => self::images($doc, $finalUrl),
            'colors' => self::colors($home['body'], $css),
            'fonts' => self::fonts($home['body'], $css),
            'logo' => self::logo($doc, $finalUrl),
            'contact' => self::contact($allHtml, $pages),
            'social' => self::social($doc),
            'pages_found' => array_map(static fn (array $p): array => ['url' => $p['url'], 'title' => $p['title']], $pages),
            'services' => self::services($pages),
            'css_size' => array_sum(array_map('strlen', $css)),
            'raw' => ['home_html' => $home['body'], 'css' => $css],
        ];

        return ['ok' => true, 'error' => '', 'data' => $data];
    }

    /**
     * Récupère la page d'accueil, en réessayant autrement lorsque le site
     * refuse la première tentative.
     *
     * Les refus tiennent le plus souvent à l'identité du client, au préfixe www
     * ou au schéma : trois variantes suffisent à passer dans la grande majorité
     * des cas, sans insister davantage.
     */
    private static function fetchHome(string $url, callable $notify): array
    {
        $notify('Connexion à ' . Util::domain($url));
        $response = Http::get($url, 25);

        if ($response['ok'] && trim($response['body']) !== '') {
            return $response;
        }
        if (!Config::get('scraper.retry_blocked', true) || !self::looksBlocked($response)) {
            return $response;
        }

        foreach (self::variants($url) as $label => $attempt) {
            $motif = $response['status'] > 0
                ? 'Refus du site (' . $response['status'] . ')'
                : 'Site injoignable';
            $notify($motif . ' — nouvelle tentative : ' . $label);
            $retry = Http::get($attempt['url'], 25, $attempt['agent']);
            if ($retry['ok'] && trim($retry['body']) !== '') {
                $notify('Accès obtenu (' . $label . ')', 'done');
                return $retry;
            }
            $response = $retry['status'] > 0 ? $retry : $response;
        }
        return $response;
    }

    /** Le refus vient-il du site plutôt que d'une page réellement absente ? */
    private static function looksBlocked(array $response): bool
    {
        return in_array($response['status'], [0, 401, 403, 405, 406, 409, 429, 500, 503], true);
    }

    /** Variantes tentées après un refus, dans l'ordre. */
    private static function variants(string $url): array
    {
        $parts = parse_url($url) ?: [];
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '/');
        $scheme = (string) ($parts['scheme'] ?? 'https');

        $swapped = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;

        return [
            'autre identité de navigateur' => ['url' => $url, 'agent' => 1],
            'domaine ' . $swapped => ['url' => $scheme . '://' . $swapped . $path, 'agent' => 0],
            'connexion non sécurisée' => ['url' => 'http://' . $host . $path, 'agent' => 1],
        ];
    }

    /**
     * Identifie la protection qui a renvoyé le refus, à partir des en-têtes.
     * Savoir à quoi on a affaire évite de chercher un contournement qui
     * n'existe pas : ces services filtrent l'adresse IP du serveur, pas
     * seulement l'identité annoncée.
     */
    private static function protection(array $headers): string
    {
        $keys = array_map('strtolower', array_keys($headers));
        $server = strtolower((string) ($headers['server'] ?? ''));

        if (in_array('cf-ray', $keys, true) || str_contains($server, 'cloudflare')) {
            return 'Cloudflare';
        }
        if (in_array('x-sucuri-id', $keys, true) || in_array('x-sucuri-cache', $keys, true)) {
            return 'Sucuri';
        }
        if (in_array('x-iinfo', $keys, true) || str_contains($server, 'imperva')) {
            return 'Imperva Incapsula';
        }
        if (in_array('x-akamai-transformed', $keys, true) || str_contains($server, 'akamai')) {
            return 'Akamai';
        }
        if (str_contains($server, 'awselb') || in_array('x-amzn-waf-action', $keys, true)) {
            return 'AWS WAF';
        }
        if (in_array('x-sitelock-id', $keys, true)) {
            return 'SiteLock';
        }
        // Sans signature reconnue, on ne nomme rien : un simple en-tête « Server »
        // désigne le serveur web, pas le dispositif qui a refusé la requête.
        return 'un pare-feu applicatif';
    }

    /** Message d'erreur qui explique la suite plutôt que de constater l'échec. */
    private static function blockedMessage(array $response): string
    {
        $status = (int) $response['status'];
        if ($status === 403 || $status === 401) {
            return 'Le site est protégé par ' . self::protection($response['headers'] ?? [])
                . ', qui refuse les lectures automatiques (' . $status . '). '
                . 'Ces protections filtrent l\'adresse IP du serveur : insister ne changerait rien. '
                . 'Utilisez la saisie manuelle plus bas — ouvrez le site, affichez le code source '
                . '(Ctrl+U), copiez tout et collez-le dans le champ prévu.';
        }
        if ($status === 429) {
            return 'Le site limite les requêtes (429). Patientez quelques minutes, ou utilisez la saisie manuelle.';
        }
        if ($status >= 500) {
            return 'Le site est en erreur (' . $status . '). Réessayez plus tard, ou utilisez la saisie manuelle.';
        }
        if ($status === 404) {
            return 'Page introuvable (404). Vérifiez l\'adresse saisie.';
        }
        if ($status === 0) {
            return 'Site injoignable' . ($response['error'] !== '' ? ' : ' . $response['error'] : '')
                . '. Vérifiez l\'adresse, ou utilisez la saisie manuelle.';
        }
        return 'Le site a répondu ' . $status . ' — page inexploitable. Utilisez la saisie manuelle plus bas.';
    }

    /**
     * Analyse à partir de codes sources fournis à la main, quand le site refuse
     * toute lecture automatique. Plusieurs pages peuvent être collées : les
     * feuilles de style externes, elles, restent hors de portée.
     */
    public static function analyzeHtml(string|array $sources, string $url): array
    {
        // Une seule page ou plusieurs : coller la page contact et les mentions
        // légales en plus de l'accueil suffit à récupérer email, téléphone et
        // SIREN, que la seule page d'accueil ne porte presque jamais.
        $sources = is_string($sources) ? ['accueil' => $sources] : $sources;
        $sources = array_filter(array_map('trim', $sources), static fn (string $v): bool => $v !== '');

        $home = trim((string) ($sources['accueil'] ?? ''));
        if ($home === '' || !preg_match('/<\s*(html|body|div|p|h1|table)\b/i', $home)) {
            return ['ok' => false, 'error' => 'Le contenu collé pour la page d\'accueil ne ressemble pas à du code HTML.', 'data' => []];
        }

        $html = $home;
        $doc = self::parse($html);
        $pages = [];
        foreach ($sources as $role => $source) {
            $pageDoc = $role === 'accueil' ? $doc : self::parse($source);
            $pages[$role] = ['url' => $url, 'html' => $source, 'title' => self::title($pageDoc)];
        }
        $allHtml = implode("\n", array_column($pages, 'html'));

        $data = [
            'url' => $url,
            'domain' => Util::domain($url),
            'fetched_at' => time(),
            'source' => 'manuelle',
            'http' => [
                'status' => 200,
                'elapsed' => 0.0,
                'size' => strlen($allHtml),
                'https' => str_starts_with($url, 'https://'),
                'server' => '',
            ],
            'title' => self::title($doc),
            'description' => self::meta($doc, 'description'),
            'lang' => self::attr($doc, 'html', 'lang'),
            'generator' => self::meta($doc, 'generator'),
            'company' => self::guessCompany($doc, $pages, $url),
            'headings' => self::headings($doc),
            'navigation' => self::navigation($doc),
            'texts' => self::texts($pages),
            'images' => self::images($doc, $url),
            'colors' => self::colors($html, []),
            'fonts' => self::fonts($html, []),
            'logo' => self::logo($doc, $url),
            'contact' => self::contact($allHtml, $pages),
            'social' => self::social($doc),
            'pages_found' => array_map(
                static fn (array $p): array => ['url' => $p['url'], 'title' => $p['title']],
                $pages
            ),
            'services' => self::services($pages),
            'css_size' => 0,
            'raw' => ['home_html' => $html, 'css' => []],
        ];

        return ['ok' => true, 'error' => '', 'data' => $data];
    }

    public static function parse(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    private static function title(\DOMDocument $doc): string
    {
        $nodes = $doc->getElementsByTagName('title');
        return $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
    }

    private static function meta(\DOMDocument $doc, string $name): string
    {
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $key = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));
            if ($key === strtolower($name) || $key === 'og:' . strtolower($name)) {
                return trim($meta->getAttribute('content'));
            }
        }
        return '';
    }

    private static function attr(\DOMDocument $doc, string $tag, string $attribute): string
    {
        $nodes = $doc->getElementsByTagName($tag);
        return $nodes->length > 0 ? trim($nodes->item(0)->getAttribute($attribute)) : '';
    }

    /** Liens internes uniques, limités au même domaine. */
    private static function internalLinks(\DOMDocument $doc, string $base): array
    {
        $domain = Util::domain($base);
        $links = [];
        foreach ($doc->getElementsByTagName('a') as $anchor) {
            $href = Util::absoluteUrl($anchor->getAttribute('href'), $base);
            if ($href === null || Util::domain($href) !== $domain) {
                continue;
            }
            $href = strtok($href, '#');
            if ($href === false || preg_match('/\.(pdf|zip|jpg|jpeg|png|gif|webp|docx?|xlsx?)$/i', $href)) {
                continue;
            }
            $links[$href] = trim($anchor->textContent);
        }
        return $links;
    }

    /** Choisit une page par catégorie utile, sur la base de l'URL et du libellé. */
    private static function pickPages(array $links): array
    {
        $picked = [];
        foreach (self::PAGE_HINTS as $kind => $hints) {
            foreach ($links as $href => $label) {
                $haystack = strtolower(Util::slug($href . ' ' . $label));
                foreach ($hints as $hint) {
                    if (str_contains($haystack, $hint)) {
                        $picked[$kind] = $href;
                        continue 3;
                    }
                }
            }
        }
        return $picked;
    }

    /** Télécharge les premières feuilles de style pour en extraire couleurs et polices. */
    private static function fetchStylesheets(\DOMDocument $doc, string $base): array
    {
        $sheets = [];
        foreach ($doc->getElementsByTagName('link') as $link) {
            if (count($sheets) >= self::MAX_CSS) {
                break;
            }
            if (!str_contains(strtolower($link->getAttribute('rel')), 'stylesheet')) {
                continue;
            }
            $href = Util::absoluteUrl($link->getAttribute('href'), $base);
            if ($href === null) {
                continue;
            }
            $response = Http::get($href, 10);
            if ($response['ok']) {
                $sheets[] = $response['body'];
            }
        }
        return $sheets;
    }

    private static function headings(\DOMDocument $doc): array
    {
        $result = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $text = Util::truncate($node->textContent, 160);
                if ($text !== '') {
                    $result[$tag][] = $text;
                }
            }
            if (isset($result[$tag])) {
                $result[$tag] = array_values(array_slice(array_unique($result[$tag]), 0, 12));
            }
        }
        return $result;
    }

    private static function navigation(\DOMDocument $doc): array
    {
        $items = [];
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//nav//a | //*[contains(@class,"menu")]//a | //header//a');
        foreach ($nodes ?: [] as $node) {
            $label = Util::truncate($node->textContent, 40);
            if ($label !== '' && !in_array($label, $items, true)) {
                $items[] = $label;
            }
            if (count($items) >= 15) {
                break;
            }
        }
        return $items;
    }

    /** Texte lisible de chaque page, nettoyé des scripts et menus. */
    private static function texts(array $pages): array
    {
        $texts = [];
        foreach ($pages as $kind => $page) {
            $html = preg_replace('~<(script|style|noscript|svg)[^>]*>.*?</\1>~is', ' ', $page['html']) ?? $page['html'];
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            $texts[$kind] = Util::truncate($text, 4000);
        }
        return $texts;
    }

    private static function images(\DOMDocument $doc, string $base): array
    {
        $images = [];
        foreach ($doc->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            $abs = Util::absoluteUrl($src, $base);
            if ($abs === null || str_contains($abs, 'data:image')) {
                continue;
            }
            $images[] = [
                'url' => $abs,
                'alt' => Util::truncate($img->getAttribute('alt'), 120),
                'width' => $img->getAttribute('width'),
                'height' => $img->getAttribute('height'),
                'lazy' => $img->getAttribute('loading') === 'lazy',
                'modern' => (bool) preg_match('/\.(webp|avif)(\?|$)/i', $abs),
            ];
            if (count($images) >= 25) {
                break;
            }
        }
        return $images;
    }

    /** Couleurs dominantes, par fréquence d'apparition dans le HTML et le CSS. */
    private static function colors(string $html, array $css): array
    {
        $source = $html . ' ' . implode(' ', $css);
        $counts = [];

        if (preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $source, $matches)) {
            foreach ($matches[1] as $hex) {
                $hex = strtolower($hex);
                if (strlen($hex) === 3) {
                    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                }
                $counts['#' . $hex] = ($counts['#' . $hex] ?? 0) + 1;
            }
        }
        if (preg_match_all('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $hex = sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            }
        }

        // On écarte les neutres purs, qui n'apprennent rien sur l'identité de marque.
        foreach (['#ffffff', '#000000', '#fff', '#000'] as $neutral) {
            unset($counts[$neutral]);
        }
        arsort($counts);
        $top = array_slice($counts, 0, 8, true);

        return [
            'palette' => array_keys($top),
            'weights' => $top,
            'dominant' => array_key_first($top) ?: '',
        ];
    }

    private static function fonts(string $html, array $css): array
    {
        $source = $html . ' ' . implode(' ', $css);
        $fonts = [];
        if (preg_match_all('/font-family\s*:\s*([^;}"\']+)/i', $source, $matches)) {
            foreach ($matches[1] as $stack) {
                foreach (explode(',', $stack) as $font) {
                    $font = trim($font, " \t\n\r\0\x0B\"'");
                    if ($font !== '' && !preg_match('/^(inherit|initial|unset|var\()/i', $font)) {
                        $fonts[$font] = ($fonts[$font] ?? 0) + 1;
                    }
                }
            }
        }
        if (preg_match_all('~fonts\.googleapis\.com/css2?\?family=([^&"\']+)~i', $source, $matches)) {
            foreach ($matches[1] as $family) {
                $name = str_replace('+', ' ', explode(':', $family)[0]);
                $fonts[$name] = ($fonts[$name] ?? 0) + 50;
            }
        }
        arsort($fonts);
        return array_slice(array_keys($fonts), 0, 6);
    }

    private static function logo(\DOMDocument $doc, string $base): string
    {
        $xpath = new \DOMXPath($doc);
        $queries = [
            '//img[contains(translate(@class,"LOGO","logo"),"logo")]',
            '//img[contains(translate(@src,"LOGO","logo"),"logo")]',
            '//img[contains(translate(@alt,"LOGO","logo"),"logo")]',
            '//header//img',
        ];
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                $src = $nodes->item(0)->getAttribute('src') ?: $nodes->item(0)->getAttribute('data-src');
                $abs = Util::absoluteUrl($src, $base);
                if ($abs !== null) {
                    return $abs;
                }
            }
        }
        return '';
    }

    /** Emails, téléphones, SIREN/SIRET et adresse trouvés dans les pages lues. */
    private static function contact(string $html, array $pages): array
    {
        $emails = [];
        if (preg_match_all('/mailto:([^"\'?>\s]+)/i', $html, $matches)) {
            foreach ($matches[1] as $email) {
                $email = strtolower(rawurldecode(trim($email)));
                if (!Util::isJunkEmail($email)) {
                    $emails[$email] = ($emails[$email] ?? 0) + 10;
                }
            }
        }
        $plain = strip_tags($html);
        if (preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $plain, $matches)) {
            foreach ($matches[0] as $email) {
                $email = strtolower($email);
                if (!Util::isJunkEmail($email)) {
                    $emails[$email] = ($emails[$email] ?? 0) + 1;
                }
            }
        }
        arsort($emails);

        $phones = [];
        if (preg_match_all('/(?:\+33|0)\s*[1-9](?:[\s.\-]*\d{2}){4}/', $plain, $matches)) {
            foreach ($matches[0] as $phone) {
                $formatted = Util::formatPhone($phone);
                $phones[$formatted] = true;
            }
        }

        $siren = '';
        if (preg_match('/\b(?:siret|siren|rcs)\b[^0-9]{0,20}((?:\d[\s.]?){9,14})/i', $plain, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]) ?? '';
            if (strlen($digits) >= 9) {
                $siren = substr($digits, 0, 9);
            }
        }

        $city = '';
        if (preg_match('/\b(\d{5})\s+([A-ZÉÈÀÂÎÔÛÇ][A-Za-zÀ-ÿ\'\- ]{2,40})/u', $plain, $m)) {
            $city = trim($m[2]);
        }

        return [
            'emails' => array_slice(array_keys($emails), 0, 6),
            'email' => array_key_first($emails) ?: '',
            'phones' => array_slice(array_keys($phones), 0, 4),
            'phone' => array_key_first($phones) ?: '',
            'siren' => $siren,
            'city' => $city,
            'legal_page' => $pages['legal']['url'] ?? '',
        ];
    }

    private static function social(\DOMDocument $doc): array
    {
        $networks = ['facebook', 'instagram', 'linkedin', 'youtube', 'twitter', 'x.com', 'tiktok'];
        $found = [];
        foreach ($doc->getElementsByTagName('a') as $anchor) {
            $href = strtolower($anchor->getAttribute('href'));
            foreach ($networks as $network) {
                if (str_contains($href, $network . '.com') || str_contains($href, $network)) {
                    $key = $network === 'x.com' ? 'twitter' : $network;
                    $found[$key] = $anchor->getAttribute('href');
                }
            }
        }
        return $found;
    }

    /** Devine le nom commercial à partir du titre, de l'og:site_name et du domaine. */
    private static function guessCompany(\DOMDocument $doc, array $pages, string $url): string
    {
        $siteName = self::meta($doc, 'site_name');
        if ($siteName !== '') {
            return Util::truncate($siteName, 80);
        }
        $title = self::title($doc);
        if ($title !== '') {
            // Les titres suivent souvent « Nom | Accroche » : on garde le segment le plus court et informatif.
            $segments = preg_split('/\s*[|\-–—:•]\s*/u', $title) ?: [$title];
            $segments = array_values(array_filter(array_map('trim', $segments), static fn ($s) => $s !== ''));
            if ($segments !== []) {
                usort($segments, static fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));
                $candidate = $segments[0];
                if (mb_strlen($candidate) >= 3 && mb_strlen($candidate) <= 60) {
                    return $candidate;
                }
            }
        }
        $domain = Util::domain($url);
        $name = explode('.', $domain)[0];
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    /** Liste de prestations devinée depuis les titres de niveau 2/3 et les menus. */
    private static function services(array $pages): array
    {
        $source = ($pages['services']['html'] ?? '') . ($pages['accueil']['html'] ?? '');
        if ($source === '') {
            return [];
        }
        $doc = self::parse($source);
        $services = [];
        foreach (['h2', 'h3', 'h4'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $text = Util::truncate($node->textContent, 80);
                if ($text !== '' && mb_strlen($text) > 3 && !in_array($text, $services, true)) {
                    $services[] = $text;
                }
            }
        }
        return array_slice($services, 0, 15);
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' Mo';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' Ko';
        }
        return $bytes . ' o';
    }
}
