<?php
declare(strict_types=1);

/** Échappement HTML systématique pour l'affichage. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Raccourci de configuration. */
function cfg(string $path, mixed $default = null): mixed
{
    return App\Config::get($path, $default);
}

/** Construit une URL interne (admin ou publique) en tenant compte des URLs propres. */
function url(string $route, array $params = []): string
{
    return App\Router::url($route, $params);
}

/** Affiche une vue avec ses variables, en retournant le HTML produit. */
function render(string $view, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/Views/' . $view . '.php';
    return (string) ob_get_clean();
}

/** Formate un prix mensuel selon la devise configurée. */
function price(float|int|string $amount): string
{
    $amount = (float) $amount;
    $formatted = rtrim(rtrim(number_format($amount, 2, ',', ' '), '0'), ',');
    return $formatted . ' ' . cfg('offer.currency', '€');
}

/** Date lisible en français. */
function dt(?int $timestamp, string $format = 'd/m/Y à H:i'): string
{
    if (!$timestamp) {
        return '—';
    }
    return date($format, $timestamp);
}

/** Durée relative courte (« il y a 3 j »). */
function ago(?int $timestamp): string
{
    if (!$timestamp) {
        return '—';
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return "à l'instant";
    }
    if ($diff < 3600) {
        return 'il y a ' . (int) ($diff / 60) . ' min';
    }
    if ($diff < 86400) {
        return 'il y a ' . (int) ($diff / 3600) . ' h';
    }
    return 'il y a ' . (int) ($diff / 86400) . ' j';
}
