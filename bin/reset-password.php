#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Reprise en main de l'accès au back-office, en ligne de commande.
 *
 *   php bin/reset-password.php frederic@exemple.fr monNouveauMotDePasse
 *
 * Définit l'identifiant et le mot de passe, lève le blocage éventuel après
 * trop de tentatives, et annule tout lien de réinitialisation en circulation.
 * À utiliser quand la récupération par email est impossible : SMTP pas encore
 * configuré, ou boîte inaccessible.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute uniquement en ligne de commande.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Config;
use App\Store;
use App\Util;

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage : php bin/reset-password.php <email> <mot de passe>\n");
    exit(1);
}
if (!Util::isEmail($email)) {
    fwrite(STDERR, "Adresse email invalide : {$email}\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Le mot de passe doit faire au moins 8 caractères.\n");
    exit(1);
}

if (!is_writable(DATA_DIR)) {
    fwrite(STDERR, "Le dossier " . DATA_DIR . " n'est pas accessible en écriture.\n");
    fwrite(STDERR, "Corrigez les droits (chmod -R 775 data) puis relancez.\n");
    exit(1);
}

Config::merge(['app' => [
    'email' => strtolower(trim($email)),
    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'password_changed_at' => time(),
]]);

// Un utilisateur qui en arrive là est souvent aussi bloqué par le compteur de
// tentatives : on repart d'une ardoise propre.
Store::write(App\Auth::storePath(), []);

// Relecture pour confirmer que l'écriture a bien atteint le disque.
Config::load(true);
$ok = password_verify($password, (string) Config::get('app.password_hash', ''))
    && Config::get('app.email') === strtolower(trim($email));

if (!$ok) {
    fwrite(STDERR, "L'écriture de data/config.json a échoué. Vérifiez les droits du dossier data/.\n");
    exit(1);
}

echo "Accès rétabli.\n";
echo "  Identifiant : " . Config::get('app.email') . "\n";
echo "  Mot de passe : celui que vous venez de saisir\n";
echo "  Blocage des tentatives : levé\n";
echo "  Liens de réinitialisation en circulation : annulés\n";
echo "\nToutes les sessions ouvertes ailleurs ont été fermées.\n";
exit(0);
