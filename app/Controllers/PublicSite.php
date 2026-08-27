<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Assets;
use App\Config;
use App\Events;
use App\Generator;
use App\Mailer;
use App\Mockup;
use App\Portrait;
use App\Prospect;
use App\Router;
use App\Screenshot;
use App\Sequence;
use App\Suppression;
use App\Tracking;
use App\Util;

/**
 * Pages vues par le prospect : la maquette et son comparatif avant/après, le
 * suivi d'ouverture et de clic, la marque d'intérêt et la désinscription.
 * Aucune de ces pages n'exige d'authentification.
 */
final class PublicSite
{
    /** Landing du lien reçu par email, puis navigation dans les trois pages. */
    public static function mockup(array $params): void
    {
        $prospect = Prospect::findByToken((string) ($params['t'] ?? ''), 'public');
        if ($prospect === null) {
            self::gone('Ce lien n\'est plus valide.');
        }

        $version = (string) ($prospect['mockup']['current'] ?? '');
        if ($version === '' || !Mockup::isComplete((string) $prospect['id'], $version)) {
            self::gone('La maquette n\'est pas encore disponible.');
        }

        $page = (string) ($params['p'] ?? 'intro');
        self::recordView($prospect, $page);

        if ($page === 'intro' || $page === '') {
            // La comparaison reste affichée même sans capture : c'est elle qui
            // porte l'argumentaire. À défaut d'image, le volet « aujourd'hui »
            // présente le diagnostic du site, qui est toujours disponible.
            $hasShot = Screenshot::exists((string) $prospect['id']);
            echo render('public/intro', [
                'prospect' => $prospect,
                'shotUrl' => $hasShot
                    ? Router::publicUrl('shot', ['t' => $prospect['tokens']['public']])
                    : null,
                'mockupUrl' => Router::mockupUrl($prospect, 'accueil'),
                'interestUrl' => Router::publicUrl('interest', ['t' => $prospect['tokens']['public']]),
                // La page de proposition emprunte la charte du prospect : c'est
                // la même couleur que dans la maquette, dès la première ligne.
                'palette' => Generator::palette($prospect),
                'socleUrl' => rtrim(Config::baseUrl(), '/') . '/assets/maquette/socle.css',
            ]);
            return;
        }

        $page = Mockup::safePage($page);
        $html = Mockup::readPage((string) $prospect['id'], $version, $page);
        if ($html === null) {
            self::gone('Cette page de la maquette est indisponible.');
        }

        $links = [];
        foreach (array_keys(Mockup::PAGES) as $key) {
            $links[$key] = Router::mockupUrl($prospect, $key);
        }

        $bar = render('public/bar', [
            'prospect' => $prospect,
            'interestUrl' => Router::publicUrl('interest', ['t' => $prospect['tokens']['public']]),
            'introUrl' => Router::mockupUrl($prospect, 'intro'),
            'currentSiteUrl' => (string) $prospect['url'],
        ]);

        $ressources = Mockup::resources(Mockup::assetPattern(
            Router::publicUrl('asset', ['t' => $prospect['tokens']['public'], 'f' => '{f}'])
        ));

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo Mockup::forPublic($html, $links, $bar, $ressources);
    }

    /**
     * Sert un actif de la maquette : logo, favicon ou photo recopiée du site
     * du prospect. Les fichiers sont hors de la racine web, et le jeton du
     * lien conditionne l'accès comme pour les pages.
     */
    public static function asset(array $params): void
    {
        $prospect = Prospect::findByToken((string) ($params['t'] ?? ''), 'public');
        $path = $prospect === null
            ? null
            : Assets::pathOf((string) $prospect['id'], (string) ($params['f'] ?? ''));
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . Assets::mediaType($path));
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
    }

    /** Sert la capture du site actuel, stockée hors de la racine web. */
    public static function shot(array $params): void
    {
        $prospect = Prospect::findByToken((string) ($params['t'] ?? ''), 'public');
        $path = $prospect === null ? null : Screenshot::usablePath((string) $prospect['id']);
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . Screenshot::mediaType($path));
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
    }

    /** Portrait affiché dans la section « Qui suis-je ». */
    public static function portrait(): void
    {
        $path = Portrait::path();
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . Portrait::mediaType($path));
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
    }

    /** Pixel d'ouverture : renvoie toujours une image, même si le jeton est inconnu. */
    public static function trackOpen(array $params): void
    {
        Tracking::recordOpen((string) ($params['t'] ?? ''));
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo Tracking::pixel();
    }

    /**
     * Clic sur le lien de la maquette. La destination n'est jamais fournie par
     * l'URL : elle est déduite du jeton, ce qui exclut toute redirection ouverte.
     */
    public static function trackClick(array $params): void
    {
        $send = Tracking::recordClick((string) ($params['t'] ?? ''));
        if ($send === null) {
            self::gone('Ce lien n\'est plus valide.');
        }
        $prospect = Prospect::find((string) $send['prospect_id']);
        if ($prospect === null) {
            self::gone('Ce lien n\'est plus valide.');
        }
        Util::redirect(Router::mockupUrl($prospect));
    }

    /** Le prospect se déclare intéressé depuis la maquette. */
    public static function interest(array $params): void
    {
        $prospect = Prospect::findByToken((string) ($params['t'] ?? ''), 'public');
        if ($prospect === null) {
            self::gone('Ce lien n\'est plus valide.');
        }

        $message = trim((string) ($_POST['message'] ?? ''));
        $phone = trim((string) ($_POST['telephone'] ?? ''));
        $prospectId = (string) $prospect['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Prospect::update($prospectId, static function (array $p) use ($message, $phone): array {
                $p['status'] = Prospect::INTERESTED;
                if ($phone !== '' && trim((string) ($p['phone'] ?? '')) === '') {
                    $p['phone'] = $phone;
                }
                $note = trim((string) ($p['notes'] ?? ''));
                $entry = '[' . date('d/m/Y H:i') . '] Intérêt déclaré depuis la maquette'
                    . ($message !== '' ? " :\n" . $message : '.');
                $p['notes'] = $note === '' ? $entry : $note . "\n\n" . $entry;
                return $p;
            });
            Sequence::stop($prospectId, 'Le prospect s\'est déclaré intéressé.');
            Events::log($prospectId, Events::INTEREST, ['message' => $message]);
            self::alert($prospect, $message, $phone);
        }

        echo render('public/interest', ['prospect' => $prospect, 'message' => $message]);
    }

    /** Désinscription en un clic, confirmée par une page dédiée. */
    public static function unsubscribe(array $params): void
    {
        $prospect = Prospect::findByToken((string) ($params['t'] ?? ''), 'unsub');
        if ($prospect === null) {
            self::gone('Ce lien n\'est plus valide.');
        }

        $done = false;
        // L'en-tête List-Unsubscribe-Post déclenche un POST automatique : il doit
        // suffire à désinscrire, sans confirmation supplémentaire.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['confirm'])) {
            Suppression::add((string) $prospect['email'], 'unsubscribe', (string) $prospect['id']);
            Sequence::stop((string) $prospect['id'], 'Désinscription du prospect.');
            Prospect::setStatus((string) $prospect['id'], Prospect::UNSUBSCRIBED, 'Désinscription');
            Events::log((string) $prospect['id'], Events::UNSUB, []);
            $done = true;
        }

        echo render('public/unsubscribe', [
            'prospect' => $prospect,
            'done' => $done,
            'action' => Router::publicUrl('unsubscribe', ['t' => $prospect['tokens']['unsub']]),
        ]);
    }

    /** Enregistre une visite, en évitant de compter les rechargements successifs. */
    private static function recordView(array $prospect, string $page): void
    {
        $prospectId = (string) $prospect['id'];
        $marker = DATA_DIR . '/logs/view_' . preg_replace('/[^a-z0-9]/i', '', $prospectId) . '.txt';
        $last = is_file($marker) ? (int) @file_get_contents($marker) : 0;
        $isNewVisit = (time() - $last) > 1800;
        @file_put_contents($marker, (string) time());

        if (!$isNewVisit) {
            return;
        }

        Prospect::update($prospectId, static function (array $p): array {
            $p['stats']['views'] = (int) ($p['stats']['views'] ?? 0) + 1;
            if (in_array($p['status'] ?? '', [Prospect::SEQUENCE, Prospect::VALIDATED], true)) {
                $p['status'] = Prospect::VIEWED;
            }
            return $p;
        });
        Events::log($prospectId, Events::VIEW, ['page' => $page]);

        if (Config::get('sequence.stop_on_view', false)) {
            Sequence::stop($prospectId, 'Le prospect a consulté la maquette.');
        }
        if (Config::get('alerts.on_view', false)) {
            Mailer::notify(
                'Maquette consultée — ' . Prospect::displayName($prospect),
                '<p><strong>' . e(Prospect::displayName($prospect)) . '</strong> vient de consulter sa maquette.</p>'
            );
        }
    }

    /** Alerte immédiate quand un prospect se déclare intéressé. */
    private static function alert(array $prospect, string $message, string $phone): void
    {
        if (!Config::get('alerts.on_interest', true)) {
            return;
        }
        $html = '<p><strong>' . e(Prospect::displayName($prospect)) . '</strong> s\'est déclaré intéressé.</p>'
            . '<ul>'
            . '<li>Site actuel : ' . e((string) $prospect['url']) . '</li>'
            . '<li>Email : ' . e((string) $prospect['email']) . '</li>'
            . ($phone !== '' ? '<li>Téléphone laissé : ' . e($phone) . '</li>' : '')
            . '<li>Tarif proposé : ' . e(price((float) $prospect['monthly_price'])) . ' / mois</li>'
            . '</ul>'
            . ($message !== '' ? '<p>Message :</p><blockquote>' . nl2br(e($message)) . '</blockquote>' : '');
        Mailer::notify('Prospect intéressé — ' . Prospect::displayName($prospect), $html);
    }

    private static function gone(string $message): never
    {
        http_response_code(410);
        echo render('public/gone', ['message' => $message]);
        exit;
    }
}
