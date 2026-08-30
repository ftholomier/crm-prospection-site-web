<?php
declare(strict_types=1);

namespace App;

/**
 * Comparaison de modèles sur une même page.
 *
 * Choisir un modèle sur une réputation ne mène nulle part : ce qui compte ici
 * n'est pas la puissance en code mais trois choses mesurables — tenir la
 * discipline du socle, ne pas inventer, et ce que ça coûte. Cette comparaison
 * les mesure sur le vrai prospect, avec le même brief, seule la variable
 * « modèle » changeant d'un candidat à l'autre.
 *
 * Une seule page est produite par candidat : elle suffit à départager, et
 * comparer sur les trois pages coûterait trois fois plus pour la même réponse.
 */
final class Compare
{
    /** Nombre maximal de candidats par comparaison. */
    public const MAX_CANDIDATS = 4;

    public static function dir(string $prospectId): string
    {
        $dir = Mockup::dir($prospectId) . '/comparaison';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function reportPath(string $prospectId): string
    {
        return self::dir($prospectId) . '/rapport.json';
    }

    public static function report(string $prospectId): array
    {
        return Store::read(self::reportPath($prospectId));
    }

    /** Identifiant de fichier d'un candidat, stable et sans surprise. */
    public static function slug(string $provider, string $model): string
    {
        return substr(preg_replace('/[^a-z0-9]+/i', '-', $provider . '-' . $model) ?? 'x', 0, 60);
    }

    public static function pagePath(string $prospectId, string $slug): ?string
    {
        if (!preg_match('/^[a-z0-9-]+$/i', $slug)) {
            return null;
        }
        $path = self::dir($prospectId) . '/' . $slug . '.html';
        return is_file($path) ? $path : null;
    }

    /**
     * Candidats proposés par défaut.
     *
     * Le réglage en vigueur sert de référence — sans lui, on compare deux
     * inconnues sans savoir si l'une vaut mieux que ce qu'on a déjà. Les autres
     * ne sont proposés que si leur clé est renseignée.
     */
    public static function defaults(): array
    {
        $actuel = Ai::for('pages');
        $candidats = [[
            'provider' => $actuel['provider'],
            'model' => Ai::modelFor('pages'),
            'note' => 'réglage actuel',
        ]];

        if (Claude::isConfigured()) {
            foreach (['claude-sonnet-5', 'claude-haiku-4-5'] as $modele) {
                $candidats[] = ['provider' => Ai::CLAUDE, 'model' => $modele, 'note' => ''];
            }
        }
        if (DeepSeek::isConfigured()) {
            $candidats[] = ['provider' => Ai::DEEPSEEK, 'model' => DeepSeek::DEFAUT, 'note' => ''];
        }

        // Le réglage actuel figure déjà en tête : on ne le propose pas deux fois.
        $vus = [];
        $uniques = [];
        foreach ($candidats as $candidat) {
            $cle = $candidat['provider'] . '|' . $candidat['model'];
            if (isset($vus[$cle])) {
                continue;
            }
            $vus[$cle] = true;
            $uniques[] = $candidat;
        }
        return array_slice($uniques, 0, self::MAX_CANDIDATS);
    }

    /**
     * Produit la page d'un candidat et la mesure.
     *
     * @return array{ok:bool,error:string,mesure:array}
     */
    public static function run(array $prospect, array $brief, string $page, array $candidat): array
    {
        $id = (string) $prospect['id'];
        $debut = microtime(true);

        $result = Generator::page(
            $prospect,
            $brief,
            $page,
            null,
            null,
            '',
            ['provider' => $candidat['provider'], 'model' => $candidat['model']]
        );

        $duree = round(microtime(true) - $debut, 1);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'mesure' => []];
        }

        $actifs = Assets::forPrompt($id);
        $controle = Generator::verifier($result['html'], $actifs, $page);
        $inventes = self::chiffresInventes($result['html'], $brief, $prospect);

        $slug = self::slug($candidat['provider'], $candidat['model']);
        @file_put_contents(self::dir($id) . '/' . $slug . '.html', $result['html']);

        $usage = $result['usage'] ?? [];
        $cache = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $entree = (int) ($usage['input_tokens'] ?? 0) + $cache;
        $sortie = (int) ($usage['output_tokens'] ?? 0);

        return ['ok' => true, 'error' => '', 'mesure' => [
            'slug' => $slug,
            'provider' => $candidat['provider'],
            'model' => $candidat['model'],
            'note' => (string) ($candidat['note'] ?? ''),
            'duree' => $duree,
            'entree' => $entree,
            'sortie' => $sortie,
            // Le coût d'une page, pas d'une maquette : c'est ce qui a été produit.
            'cout' => Models::cost($candidat['model'], $entree - $cache, $cache, $sortie),
            'ecarts' => $controle['ecarts'],
            'conforme' => $controle['ok'],
            'chiffres_inventes' => $inventes,
            'poids' => strlen($result['html']),
        ]];
    }

    /**
     * Nombres présents dans la page mais introuvables dans ce qui l'a nourrie.
     *
     * C'est la faute qui coûte le plus cher commercialement : une maquette qui
     * annonce « 25 ans d'expérience » à un prospect qui en a huit. Le contrôle
     * de conformité ne peut pas la voir — il ne juge que la forme — mais elle
     * se détecte mécaniquement, en confrontant les nombres affichés à ceux du
     * brief et du contenu relevé sur le site.
     *
     * @return string[] les nombres sans source, au plus douze
     */
    public static function chiffresInventes(string $html, array $brief, array $prospect): array
    {
        $corps = Mockup::bodyOf($html);

        // La numérotation des étapes et des points appartient au gabarit :
        // 01, 02, 03 ne prétendent rien sur l'entreprise et seraient signalés
        // à chaque page.
        $corps = preg_replace('~<[^>]*class="[^"]*(?:etape__numero|point__numero)[^"]*"[^>]*>.*?</[a-z]+>~is', ' ', $corps) ?? $corps;

        // Les balises deviennent des espaces : sans cela deux textes voisins se
        // collent et « 01 » suivi de « 02 » se lit comme le nombre 0102.
        $texte = trim(html_entity_decode(
            preg_replace('~<[^>]+>~', ' ', $corps) ?? $corps,
            ENT_QUOTES,
            'UTF-8'
        ));

        // Tout ce qui a pu légitimement nourrir la page.
        $sources = json_encode($brief, JSON_UNESCAPED_UNICODE) . ' '
            . json_encode($prospect['analysis'] ?? [], JSON_UNESCAPED_UNICODE) . ' '
            . (string) ($prospect['phone'] ?? '') . ' ' . (string) ($prospect['company'] ?? '')
            . ' ' . date('Y');
        $sourcesChiffres = self::normaliserChiffres($sources);

        $inventes = [];
        foreach (self::normaliserChiffres($texte) as $chiffre => $extrait) {
            if (isset($sourcesChiffres[$chiffre])) {
                continue;
            }
            // Le nombre est rappelé devant son extrait : deux valeurs d'une même
            // phrase donneraient sinon deux lignes rigoureusement identiques.
            $inventes[$chiffre] = $chiffre . ' — ' . $extrait;
            if (count($inventes) >= 12) {
                break;
            }
        }
        return array_values($inventes);
    }

    /**
     * Nombres significatifs d'un texte, indexés par leur valeur.
     *
     * Les nombres d'un ou deux chiffres sont écartés : ce sont les numéros
     * d'étape du gabarit, les tailles de police et les années tronquées, et ils
     * n'affirment rien sur l'entreprise.
     *
     * @return array<string,string> valeur => extrait où elle apparaît
     */
    private static function normaliserChiffres(string $texte): array
    {
        $trouves = [];
        if (!preg_match_all('/\d[\d  .,]*\d|\d/u', $texte, $m, PREG_OFFSET_CAPTURE)) {
            return $trouves;
        }
        foreach ($m[0] as [$brut, $offset]) {
            $valeur = preg_replace('/\D/', '', $brut) ?? '';
            if (strlen($valeur) < 2) {
                continue;
            }
            $extrait = trim(preg_replace('/\s+/u', ' ',
                mb_substr($texte, max(0, self::charOffset($texte, $offset) - 28), 70)) ?? $brut);
            $trouves[$valeur] ??= $extrait;
        }
        return $trouves;
    }

    /** L'offset de preg_match est en octets ; mb_substr le veut en caractères. */
    private static function charOffset(string $texte, int $octets): int
    {
        return mb_strlen(substr($texte, 0, $octets));
    }

    /** Enregistre le rapport, pour qu'il reste consultable après coup. */
    public static function saveReport(string $prospectId, string $page, array $mesures): void
    {
        Store::write(self::reportPath($prospectId), [
            'page' => $page,
            'at' => time(),
            'mesures' => $mesures,
        ]);
    }

    public static function clear(string $prospectId): void
    {
        Store::removeTree(self::dir($prospectId));
    }
}
