# Prospect Studio

Application de prospection pour agences web : vous donnez l'adresse d'un site
vieillissant, l'application l'analyse, en calcule le degré de vétusté, génère
trois maquettes de refonte en HTML/CSS avec l'API Claude, puis déroule seule
une séquence de trois emails vers le dirigeant.

**PHP natif uniquement.** Aucune base de données, aucune dépendance Composer,
aucun outil de build. Les données vivent dans des fichiers JSON, le front est
dans `/public`.

---

## Ce que fait l'application

1. **Analyse** — lecture de la page d'accueil et des pages clés (contact,
   mentions légales, à propos, prestations), extraction du contenu, des
   couleurs, des polices, du logo, des photos, de l'email, du téléphone et du
   SIREN.
2. **Score de vétusté sur 100** — seize contrôles (absence de responsive, HTTP
   non sécurisé, mise en page en tableaux, Flash, jQuery obsolète, copyright
   daté, images non optimisées, temps de réponse…). Chaque constat est rédigé
   pour être réutilisé tel quel dans l'email de prospection.
3. **Génération des maquettes** — un brief de direction artistique commun, puis
   trois pages HTML/CSS autonomes : accueil, à propos, prestations. Un champ de
   prompt permet de retoucher autant de fois que nécessaire, chaque passe
   créant une nouvelle version.
4. **Validation** — prévisualisation ordinateur/tablette/mobile, comparaison des
   versions, validation explicite avant tout envoi.
5. **Séquence automatique** — trois emails espacés, envoyés par le cron dans la
   fenêtre horaire choisie, avec arrêt automatique sur clic, intérêt déclaré ou
   désinscription.
6. **Suivi** — tableau de bord, entonnoir de conversion, pipeline, ouvertures,
   clics, consultations de maquette et prospects intéressés.

---

## Prérequis

| Élément | Nécessité |
|---|---|
| PHP 8.1 ou plus | requis (développé et testé sur PHP 8.4) |
| Extensions `curl`, `mbstring`, `dom`, `json` | requises |
| Accès en écriture au dossier `data/` | requis |
| Tâche planifiée (cron) | requise pour la séquence automatique |
| Clé API Anthropic | requise pour générer les maquettes |
| Compte SMTP | requis pour envoyer les emails |
| Clé d'un service de capture d'écran | facultative (comparatif avant/après) |
| Clé API Pappers | facultative (dirigeant, SIREN, effectif) |

---

## Installation

### 1. Déposer les fichiers et faire pointer le domaine

**Faites pointer le domaine sur `public/`.** C'est la seule configuration qui
met `data/config.json` — donc votre clé API, votre mot de passe SMTP et le hash
de votre mot de passe d'accès — physiquement hors de l'arborescence web. Un
`.htaccess` mal pris en compte ne peut alors rien exposer.

```
/home/monsite/prospection/          ← dépôt complet, hors du web
/home/monsite/prospection/public/   ← racine web du domaine ou sous-domaine
```

Où le régler : chez OVH mutualisé, Multisite → « Dossier racine » ;
chez o2switch et les hébergements cPanel, Domaines → Document Root. Les deux
l'autorisent, y compris pour un sous-domaine.

**Si votre hébergeur impose la racine du projet**, le fichier `.htaccess` placé
à la racine du dépôt prend le relais : il redirige tout vers `public/` sans
exposer `app/`, `data/` ni `bin/`. Dans ce cas, **renseignez impérativement
l'URL publique dans les Réglages** — sans elle, l'application la devine depuis
la requête et y ajoute `/public`, ce qui casse les liens envoyés aux prospects.

Dans les deux cas, vérifiez après installation que
`https://votredomaine/data/config.json` renvoie bien une erreur et non du JSON.

### 2. Droits d'écriture

```bash
chmod -R 775 data
```

### 3. Premier lancement

Ouvrez l'application dans un navigateur. L'écran d'installation demande une
**adresse email** — elle sert d'identifiant de connexion et reçoit les liens de
récupération — et un **mot de passe** de 8 caractères minimum.

Choisissez une adresse que vous consultez réellement : c'est par elle que passe
la procédure « mot de passe oublié ». Elle est indépendante de l'adresse
d'expédition des emails de prospection, que vous réglerez ensuite.

Vous pourrez modifier l'identifiant et le mot de passe dans **Réglages → Accès
au back-office**. Un changement de mot de passe ferme les sessions ouvertes sur
vos autres appareils.

### 4. Réglages indispensables

Dans **Réglages** :

- **URL publique** — l'adresse exacte de l'application. Elle sert à construire
  les liens envoyés aux prospects ; sans elle, l'URL est devinée depuis la
  requête, ce qui échoue derrière un proxy.
- **Clé API Claude** — depuis `console.anthropic.com`. Le bouton « Tester »
  vérifie qu'elle répond.
- **SMTP** — serveur, port, identifiants et adresse d'expédition. Le bouton
  « Tester » valide la connexion, et envoie un vrai message si vous renseignez
  une adresse de test.
- **Offre** — tarif mensuel par défaut et liste de ce qui est inclus.

### 5. Tâche planifiée

En ligne de commande, toutes les quinze minutes :

```
0,15,30,45 * * * * /usr/bin/php /chemin/vers/prospection/bin/cron.php
```

Si votre hébergeur ne propose que des crons de type URL, l'adresse à appeler
est affichée dans **Réglages → Tâche planifiée** ; elle contient une clé
secrète.

### 6. Délivrabilité

La prospection à froid finit en indésirables sans authentification du domaine
expéditeur. Publiez **SPF**, **DKIM** et **DMARC** avant le premier envoi, et
laissez le plafond quotidien bas les premières semaines.

---

## Développement en local

```bash
php -S localhost:8000 -t public bin/serve.php
```

Le script `bin/serve.php` reproduit le comportement de `mod_rewrite` : il sert
les fichiers statiques existants et confie le reste au point d'entrée.

---

## Architecture

```
app/
├── bootstrap.php            Amorçage : chemins, autoloader, configuration
├── functions.php            Aides de vue (échappement, URL, prix, dates)
├── Config.php               Réglages fusionnés avec les valeurs par défaut
├── Store.php                Lecture/écriture JSON atomique et verrous
├── Router.php               Routage, URL propres et repli sur paramètres
├── Auth.php / Csrf.php      Mot de passe unique, session, jeton anti-CSRF
├── Prospect.php             Dépôt des fiches + index allégé
├── Scraper.php              Lecture du site cible et extraction
├── Audit.php                Score de vétusté et argumentaire
├── Enrich.php               Enrichissement en cascade (site → base entreprise)
├── Screenshot.php           Capture du site actuel (service externe ou import)
├── Claude.php               Client Messages API en HTTP brut (classique + flux)
├── Generator.php            Brief de direction artistique puis pages
├── Mockup.php               Stockage, versions et préparation à l'affichage
├── Templates.php            Modèles des trois emails
├── Mailer.php               Variables, gabarit HTML, remise
├── Mail/Smtp.php            Client SMTP sur sockets natifs
├── Mail/Message.php         Construction MIME
├── Sequence.php             Moteur de relance automatique
├── Tracking.php             Ouvertures, clics, plafonds
├── Cron.php                 Traitement périodique
├── Stats.php / Events.php   Agrégats et journal d'activité
├── Controllers/             Admin, pages publiques, flux SSE
└── Views/                   Gabarits PHP

public/                      Racine web : point d'entrée, CSS, JS
data/                        Données JSON (jamais versionnées)
bin/                         cron.php et serve.php
```

### Stockage

| Fichier | Contenu |
|---|---|
| `data/config.json` | Réglages et secrets |
| `data/prospects/{id}.json` | Une fiche complète par prospect |
| `data/index.json` | Index allégé pour les listes et le tableau de bord |
| `data/mockups/{id}/v{n}/` | Pages HTML d'une version + `brief.json` |
| `data/mockups/{id}/avant.jpg` | Capture du site actuel |
| `data/events.jsonl` | Journal d'activité append-only |
| `data/sends.json` | Envois, ouvertures et clics |
| `data/suppression.json` | Désinscriptions |
| `data/auth.json` | Tentatives de connexion et jeton de réinitialisation (haché) |

Les écritures passent par un fichier temporaire suivi d'un `rename`, et les
cycles lecture-modification-écriture sont protégés par un verrou : le cron et
l'interface peuvent tourner en même temps sans se corrompre.

---

## Fonctionnement de la génération

La génération se fait **en deux temps et en plusieurs requêtes**, ce qui est
nécessaire sur un hébergement mutualisé où une requête est coupée au bout de
quelques dizaines de secondes :

1. Un appel court produit un **brief structuré** (palette, typographie, ton,
   prestations réelles, plan de chaque page), imposé par un schéma JSON.
2. Chaque page est ensuite générée par **une requête distincte**, en flux
   (Server-Sent Events). Le navigateur affiche la progression en direct, et le
   flux continu empêche toute coupure intermédiaire.

Si une capture du site est disponible, elle est jointe à la requête : le modèle
voit alors réellement le site avant de le refondre, au lieu de le deviner à
partir du code.

Le modèle par défaut est `claude-opus-5`, avec la réflexion adaptative. Modèle,
niveau d'effort et plafond de tokens sont modifiables dans les Réglages.

> L'API est appelée en **HTTP brut via cURL**, sans le SDK Anthropic : le
> projet impose du PHP natif sans Composer.

---

## Variables des emails

`{{prenom}}` `{{nom}}` `{{nom_complet}}` `{{societe}}` `{{email}}` `{{domaine}}`
`{{url_site}}` `{{ville}}` `{{secteur}}` `{{tarif}}` `{{lien_maquette}}`
`{{score}}` `{{constat_1}}` `{{constat_2}}` `{{constat_3}}` `{{constats_liste}}`
`{{inclus_liste}}` `{{signature}}` `{{expediteur}}` `{{lien_desinscription}}`

Deux syntaxes supplémentaires :

- `{{societe|votre entreprise}}` — valeur de repli si la variable est vide.
- `{{#si prenom}} {{prenom}}{{/si}}` — n'affiche le bloc que si la variable est
  renseignée. C'est ce qui permet d'écrire « Bonjour Michel, » quand le prénom
  est connu et « Bonjour, » sinon.

Le lien de désinscription et le pied de page légal sont ajoutés
automatiquement à chaque message.

---

## Sécurité et conformité

- Connexion par identifiant (adresse email) et mot de passe haché, session PHP,
  jeton CSRF sur chaque formulaire.
- Blocage de 15 minutes après huit tentatives échouées, compté **côté serveur
  par adresse IP** : supprimer ses cookies ne remet pas le compteur à zéro.
- Récupération de mot de passe par email : jeton de 32 octets **stocké haché**,
  valable une heure, à usage unique, limité à cinq demandes par heure. La
  réponse affichée est la même que l'adresse existe ou non, pour qu'aucune
  tentative extérieure ne puisse confirmer votre identifiant.
- Un changement de mot de passe invalide immédiatement les sessions ouvertes
  ailleurs.
- Les liens envoyés au prospect reposent sur des jetons aléatoires de 18 et
  12 octets, impossibles à deviner ou à énumérer.
- Le lien de suivi des clics **ne prend aucune URL en paramètre** : la
  destination est déduite du jeton, ce qui exclut toute redirection ouverte.
- Le JavaScript et les attributs événementiels sont retirés des maquettes avant
  de les servir : ce sont des pages générées, publiées sur votre domaine.
- Toutes les pages générées portent `noindex, nofollow`.
- Lien de désinscription et en-têtes `List-Unsubscribe` conformes sur chaque
  email ; une adresse désinscrite ne peut plus jamais être contactée, quelle
  que soit la séquence.

---

## Import en masse

**Import en masse** accepte des lignes collées ou un fichier CSV. Seule la
première colonne est obligatoire :

```
url ; email ; prénom ; nom ; société ; tarif mensuel
```

Les doublons de domaine sont ignorés. Le cron analyse ensuite les fiches
importées par petits lots. La génération automatique des maquettes est
désactivée par défaut, chaque maquette consommant des crédits API ; si vous
l'activez, réservez-la au cron en ligne de commande.

---

## Dépannage

| Symptôme | Cause probable |
|---|---|
| Liens des emails erronés | URL publique non renseignée dans les Réglages |
| Erreur 404 sur `/m/jeton/accueil` | `mod_rewrite` absent — décochez « URLs propres » |
| « Extension cURL indisponible » | Activez l'extension `curl` chez votre hébergeur |
| Génération interrompue | Augmentez « Tokens maximum », ou relancez : les pages déjà produites sont conservées |
| Le site cible renvoie 403 | Site protégé contre les robots : saisissez les informations à la main et importez une capture |
| Emails en indésirables | SPF, DKIM et DMARC non publiés sur le domaine expéditeur |
| Séquence bloquée | Vérifiez le cron, la fenêtre horaire, les jours d'envoi et le plafond quotidien |
| « Trop de tentatives » | Blocage de 15 minutes ; pour le lever tout de suite, supprimez `data/auth.json` |
| Mot de passe perdu et SMTP non configuré | Videz la valeur `app.password_hash` dans `data/config.json` : l'application repasse par l'écran d'installation |
| Le lien de réinitialisation n'arrive pas | Vérifiez le SMTP dans les Réglages, puis `data/logs/auth.jsonl` qui journalise les échecs d'envoi |
