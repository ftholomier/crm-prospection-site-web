<?php
declare(strict_types=1);

namespace App;

/**
 * Dépôt des prospects. Un fichier JSON par prospect dans data/prospects/,
 * plus un index léger (data/index.json) pour les listes et le tableau de bord.
 */
final class Prospect
{
    public const NEW = 'nouveau';
    public const ANALYZED = 'analyse';
    public const MOCKUP = 'maquette';
    public const VALIDATED = 'validee';
    public const SEQUENCE = 'sequence';
    public const VIEWED = 'vu';
    public const INTERESTED = 'interesse';
    public const CUSTOMER = 'client';
    public const LOST = 'perdu';
    public const UNSUBSCRIBED = 'desinscrit';

    /** Ordre du pipeline, utilisé par le tableau kanban. */
    public const PIPELINE = [
        self::NEW => 'À analyser',
        self::ANALYZED => 'Analysé',
        self::MOCKUP => 'Maquette à valider',
        self::VALIDATED => 'Prêt à envoyer',
        self::SEQUENCE => 'Séquence en cours',
        self::VIEWED => 'Maquette consultée',
        self::INTERESTED => 'Intéressé',
        self::CUSTOMER => 'Client',
        self::LOST => 'Perdu',
        self::UNSUBSCRIBED => 'Désinscrit',
    ];

    public static function label(string $status): string
    {
        return self::PIPELINE[$status] ?? $status;
    }

    public static function file(string $id): string
    {
        return DATA_DIR . '/prospects/' . preg_replace('/[^a-z0-9]/i', '', $id) . '.json';
    }

    public static function indexFile(): string
    {
        return DATA_DIR . '/index.json';
    }

    /** Squelette d'un nouveau prospect. */
    public static function blank(string $url): array
    {
        $now = time();
        return [
            'id' => Util::id('p'),
            'url' => $url,
            'domain' => Util::domain($url),
            'company' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'siren' => '',
            'city' => '',
            'sector' => '',
            'monthly_price' => (float) Config::get('offer.monthly_price', 79),
            'status' => self::NEW,
            'audit' => [],
            'analysis' => [],
            'enrichment' => [],
            'candidate_emails' => [],
            'mockup' => ['current' => null, 'versions' => [], 'validated' => false],
            'design_prompt' => '',
            'sequence' => [
                'active' => false,
                'step' => 0,
                'next_at' => null,
                'sent' => [],
                'stopped_reason' => '',
            ],
            'tokens' => [
                'public' => Util::token(18),
                'unsub' => Util::token(12),
            ],
            'stats' => ['opens' => 0, 'clicks' => 0, 'views' => 0],
            'notes' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public static function find(string $id): ?array
    {
        $data = Store::read(self::file($id));
        return $data === [] ? null : $data;
    }

    /** Retrouve un prospect à partir d'un jeton public ou de désinscription. */
    public static function findByToken(string $token, string $kind = 'public'): ?array
    {
        foreach (self::index() as $row) {
            if (hash_equals((string) ($row['tokens'][$kind] ?? ''), $token)) {
                return self::find((string) $row['id']);
            }
        }
        return null;
    }

    public static function findByUrl(string $url): ?array
    {
        $domain = Util::domain($url);
        foreach (self::index() as $row) {
            if (($row['domain'] ?? '') === $domain) {
                return self::find((string) $row['id']);
            }
        }
        return null;
    }

    /** Enregistre un prospect et rafraîchit l'index. */
    public static function save(array $prospect): array
    {
        $prospect['updated_at'] = time();
        Store::write(self::file((string) $prospect['id']), $prospect);
        self::indexPut($prospect);
        return $prospect;
    }

    /** Applique une modification sous verrou (évite les écrasements concurrents). */
    public static function update(string $id, callable $mutator): ?array
    {
        $lock = Store::lock('prospect_' . $id);
        try {
            $prospect = self::find($id);
            if ($prospect === null) {
                return null;
            }
            $updated = $mutator($prospect);
            if (!is_array($updated)) {
                return $prospect;
            }
            return self::save($updated);
        } finally {
            Store::unlock($lock);
        }
    }

    public static function create(string $url): array
    {
        $prospect = self::blank($url);
        self::save($prospect);
        Events::log($prospect['id'], 'prospect_created', ['url' => $url]);
        return $prospect;
    }

    public static function delete(string $id): void
    {
        @unlink(self::file($id));
        Store::removeTree(DATA_DIR . '/mockups/' . preg_replace('/[^a-z0-9]/i', '', $id));
        Store::mutate(self::indexFile(), static function (array $index) use ($id): array {
            unset($index[$id]);
            return $index;
        });
    }

    /** Change le statut en journalisant la transition. */
    public static function setStatus(string $id, string $status, string $reason = ''): ?array
    {
        return self::update($id, static function (array $p) use ($status, $reason): array {
            if (($p['status'] ?? '') === $status) {
                return $p;
            }
            Events::log($p['id'], 'status_changed', ['from' => $p['status'] ?? '', 'to' => $status, 'reason' => $reason]);
            $p['status'] = $status;
            return $p;
        });
    }

    /** Index allégé : uniquement les champs nécessaires aux listes. */
    public static function index(): array
    {
        return Store::read(self::indexFile());
    }

    private static function indexPut(array $prospect): void
    {
        Store::mutate(self::indexFile(), static function (array $index) use ($prospect): array {
            $index[(string) $prospect['id']] = [
                'id' => $prospect['id'],
                'url' => $prospect['url'],
                'domain' => $prospect['domain'],
                'company' => $prospect['company'],
                'first_name' => $prospect['first_name'],
                'last_name' => $prospect['last_name'],
                'email' => $prospect['email'],
                'city' => $prospect['city'] ?? '',
                'status' => $prospect['status'],
                'score' => $prospect['audit']['score'] ?? null,
                'monthly_price' => $prospect['monthly_price'] ?? null,
                'has_mockup' => !empty($prospect['mockup']['current']),
                'validated' => !empty($prospect['mockup']['validated']),
                'sequence' => [
                    'active' => (bool) ($prospect['sequence']['active'] ?? false),
                    'step' => (int) ($prospect['sequence']['step'] ?? 0),
                    'next_at' => $prospect['sequence']['next_at'] ?? null,
                ],
                'stats' => $prospect['stats'] ?? [],
                'tokens' => $prospect['tokens'] ?? [],
                'created_at' => $prospect['created_at'],
                'updated_at' => $prospect['updated_at'],
            ];
            return $index;
        });
    }

    /** Reconstruit l'index depuis les fichiers prospects (réparation). */
    public static function rebuildIndex(): int
    {
        $count = 0;
        foreach (glob(DATA_DIR . '/prospects/*.json') ?: [] as $file) {
            $prospect = Store::read($file);
            if ($prospect !== []) {
                self::indexPut($prospect);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Liste filtrée et triée pour l'écran Prospects.
     * @param array{search?:string,status?:string,sort?:string} $filters
     */
    public static function search(array $filters = []): array
    {
        $rows = array_values(self::index());
        $term = strtolower(trim((string) ($filters['search'] ?? '')));
        $status = (string) ($filters['status'] ?? '');

        if ($term !== '') {
            $rows = array_filter($rows, static function (array $row) use ($term): bool {
                $haystack = strtolower(implode(' ', [
                    $row['company'] ?? '', $row['domain'] ?? '', $row['email'] ?? '',
                    $row['first_name'] ?? '', $row['last_name'] ?? '', $row['city'] ?? '',
                ]));
                return str_contains($haystack, $term);
            });
        }
        if ($status !== '') {
            $rows = array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === $status);
        }

        $sort = (string) ($filters['sort'] ?? 'recent');
        usort($rows, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'score' => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
                'company' => strcasecmp((string) ($a['company'] ?: $a['domain']), (string) ($b['company'] ?: $b['domain'])),
                default => ((int) ($b['updated_at'] ?? 0)) <=> ((int) ($a['updated_at'] ?? 0)),
            };
        });
        return array_values($rows);
    }

    /** Nom d'affichage : société si connue, domaine sinon. */
    public static function displayName(array $row): string
    {
        $company = trim((string) ($row['company'] ?? ''));
        return $company !== '' ? $company : (string) ($row['domain'] ?? 'Sans nom');
    }

    /** Nom complet du contact, vide si inconnu. */
    public static function contactName(array $row): string
    {
        return trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
    }

    /** Le prospect est-il envoyable (email valide, non désinscrit) ? */
    public static function isMailable(array $prospect): bool
    {
        $email = (string) ($prospect['email'] ?? '');
        if (!Util::isEmail($email) || Suppression::has($email)) {
            return false;
        }
        return ($prospect['status'] ?? '') !== self::UNSUBSCRIBED;
    }
}
