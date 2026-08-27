<?php
declare(strict_types=1);

namespace App;

/**
 * Routage à point d'entrée unique (public/index.php).
 *
 * L'administration passe toujours par des paramètres de requête. Les pages
 * publiques — celles que verra le prospect — utilisent des URL propres quand
 * la réécriture est disponible, avec repli automatique sur les paramètres pour
 * les hébergements sans mod_rewrite.
 */
final class Router
{
    /** Routes publiques : gabarit d'URL propre et paramètres attendus. */
    private const PUBLIC_ROUTES = [
        'mockup' => ['pretty' => 'm/{t}/{p}', 'key' => 'm', 'params' => ['t', 'p']],
        'track_open' => ['pretty' => 'o/{t}.gif', 'key' => 'o', 'params' => ['t']],
        'track_click' => ['pretty' => 'c/{t}', 'key' => 'c', 'params' => ['t']],
        'unsubscribe' => ['pretty' => 'u/{t}', 'key' => 'u', 'params' => ['t']],
        'interest' => ['pretty' => 'i/{t}', 'key' => 'i', 'params' => ['t']],
        'shot' => ['pretty' => 's/{t}.jpg', 'key' => 's', 'params' => ['t']],
        'portrait' => ['pretty' => 'p/portrait.jpg', 'key' => 'p', 'params' => []],
    ];

    /** URL relative utilisable dans les pages de l'administration. */
    public static function url(string $route, array $params = []): string
    {
        return self::path($route, $params);
    }

    /** URL absolue, indispensable dans les emails et les liens partagés. */
    public static function publicUrl(string $route, array $params = []): string
    {
        return Config::baseUrl() . '/' . ltrim(self::path($route, $params), '/');
    }

    public static function path(string $route, array $params = []): string
    {
        if (isset(self::PUBLIC_ROUTES[$route])) {
            return self::publicPath($route, $params);
        }
        $query = array_merge(['r' => $route], array_filter(
            $params,
            static fn ($value): bool => $value !== null && $value !== ''
        ));
        return 'index.php?' . http_build_query($query);
    }

    private static function publicPath(string $route, array $params): string
    {
        $definition = self::PUBLIC_ROUTES[$route];

        if (Config::get('app.pretty_urls', true)) {
            $path = $definition['pretty'];
            foreach ($definition['params'] as $name) {
                $value = (string) ($params[$name] ?? '');
                $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
            }
            return $path;
        }

        $query = ['r' => $definition['key']];
        foreach ($definition['params'] as $name) {
            if (isset($params[$name]) && $params[$name] !== '') {
                $query[$name] = $params[$name];
            }
        }
        return 'index.php?' . http_build_query($query);
    }

    /**
     * Détermine la route demandée.
     * @return array{route:string,params:array}
     */
    public static function dispatch(): array
    {
        $segments = self::segments();

        if ($segments !== []) {
            $head = $segments[0];
            foreach (self::PUBLIC_ROUTES as $route => $definition) {
                if ($head !== $definition['key']) {
                    continue;
                }
                $params = [];
                $values = array_slice($segments, 1);
                foreach ($definition['params'] as $position => $name) {
                    $params[$name] = $values[$position] ?? '';
                }
                // Le pixel de suivi porte une extension pour ressembler à une image.
                if (isset($params['t'])) {
                    $params['t'] = preg_replace('/\.(gif|png|jpe?g|webp)$/i', '', (string) $params['t']) ?? $params['t'];
                }
                return ['route' => $route, 'params' => $params];
            }
        }

        $key = (string) ($_GET['r'] ?? 'dashboard');
        foreach (self::PUBLIC_ROUTES as $route => $definition) {
            if ($key === $definition['key']) {
                $params = [];
                foreach ($definition['params'] as $name) {
                    $params[$name] = (string) ($_GET[$name] ?? '');
                }
                return ['route' => $route, 'params' => $params];
            }
        }

        return ['route' => $key, 'params' => $_GET];
    }

    /** Segments de chemin situés après le script d'entrée. */
    private static function segments(): array
    {
        $path = (string) ($_SERVER['PATH_INFO'] ?? '');
        if ($path === '') {
            $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $base = rtrim(dirname($script), '/');
            if ($base !== '' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }
            $path = $uri;
        }
        $path = trim(rawurldecode($path), '/');
        if ($path === '' || str_starts_with($path, 'index.php')) {
            return [];
        }
        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }

    /** Le lien de la maquette tel qu'il sera partagé au prospect. */
    public static function mockupUrl(array $prospect, string $page = 'intro'): string
    {
        return self::publicUrl('mockup', [
            't' => (string) ($prospect['tokens']['public'] ?? ''),
            'p' => $page,
        ]);
    }
}
