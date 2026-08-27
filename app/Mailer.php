<?php
declare(strict_types=1);

namespace App;

use App\Mail\Message;
use App\Mail\Smtp;

/**
 * Rendu et envoi des emails de la séquence : substitution des variables, mise
 * en page HTML, injection du suivi et du lien de désinscription, remise SMTP.
 */
final class Mailer
{
    /**
     * Remplace les variables d'un texte.
     * Syntaxes gérées : {{var}}, {{var|repli}} et {{#si var}}…{{/si}}.
     */
    public static function interpolate(string $template, array $vars): string
    {
        // Blocs conditionnels d'abord : ils peuvent contenir des variables.
        $template = preg_replace_callback(
            '/\{\{#si\s+([a-z0-9_]+)\}\}(.*?)\{\{\/si\}\}/su',
            static function (array $m) use ($vars): string {
                $value = trim((string) ($vars[$m[1]] ?? ''));
                return $value !== '' ? $m[2] : '';
            },
            $template
        ) ?? $template;

        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*(?:\|([^}]*))?\}\}/u',
            static function (array $m) use ($vars): string {
                $value = trim((string) ($vars[$m[1]] ?? ''));
                return $value !== '' ? $value : trim($m[2] ?? '');
            },
            $template
        ) ?? $template;
    }

    /**
     * Jeu de variables d'un prospect.
     * @param string|null $trackToken jeton de suivi ; sans lui, le lien pointe directement vers la maquette
     */
    public static function variables(array $prospect, ?string $trackToken = null): array
    {
        $audit = $prospect['audit'] ?? [];
        $arguments = Audit::topArguments($audit, 3);
        $company = Prospect::displayName($prospect);
        $base = Config::baseUrl();

        $mockupLink = $trackToken !== null
            ? Router::publicUrl('track_click', ['t' => $trackToken])
            : Router::mockupUrl($prospect);

        $included = (array) Config::get('offer.included', []);
        $includedList = $included === [] ? '' : '<ul>' . implode('', array_map(
            static fn (string $item): string => '<li>' . e($item) . '</li>',
            $included
        )) . '</ul>';

        $findingsList = $arguments === [] ? '' : '<ul>' . implode('', array_map(
            static fn (string $item): string => '<li>' . e($item) . '</li>',
            $arguments
        )) . '</ul>';

        return [
            'prenom' => (string) ($prospect['first_name'] ?? ''),
            'nom' => (string) ($prospect['last_name'] ?? ''),
            'nom_complet' => Prospect::contactName($prospect),
            'societe' => $company,
            'email' => (string) ($prospect['email'] ?? ''),
            'domaine' => (string) ($prospect['domain'] ?? ''),
            'url_site' => (string) ($prospect['url'] ?? ''),
            'ville' => (string) ($prospect['city'] ?? ''),
            'secteur' => (string) ($prospect['sector'] ?? ''),
            'tarif' => price((float) ($prospect['monthly_price'] ?? Config::get('offer.monthly_price', 79))),
            'lien_maquette' => $mockupLink,
            'score' => (string) ($audit['score'] ?? ''),
            'constat_1' => $arguments[0] ?? '',
            'constat_2' => $arguments[1] ?? '',
            'constat_3' => $arguments[2] ?? '',
            'constats_liste' => $findingsList,
            'inclus_liste' => $includedList,
            'signature' => self::signature(),
            'expediteur' => (string) Config::get('smtp.from_name', ''),
            'lien_desinscription' => Router::publicUrl('unsubscribe', ['t' => $prospect['tokens']['unsub'] ?? '']),
        ];
    }

    private static function signature(): string
    {
        $signature = trim((string) Config::get('app.signature', ''));
        if ($signature === '') {
            $name = (string) Config::get('smtp.from_name', '');
            return $name !== '' ? '<p>' . e($name) . '</p>' : '';
        }
        return str_contains($signature, '<') ? $signature : '<p>' . nl2br(e($signature)) . '</p>';
    }

    /**
     * Prépare un email complet.
     * @return array{subject:string,html:string,text:string,vars:array}
     */
    public static function render(array $prospect, int $step, ?string $trackToken = null): array
    {
        $template = Templates::get($step);
        $vars = self::variables($prospect, $trackToken);

        $subject = trim(self::interpolate((string) $template['subject'], $vars));
        $body = self::interpolate((string) $template['body'], $vars);

        if (!str_contains($body, $vars['lien_desinscription'])) {
            $body .= "\n"; // le pied de page ajoutera le lien de désinscription
        }

        $html = self::wrap($body, $vars, $trackToken);
        $text = Message::htmlToText($body) . "\n\n---\nPour ne plus recevoir de message : " . $vars['lien_desinscription'];

        return ['subject' => $subject, 'html' => $html, 'text' => $text, 'vars' => $vars];
    }

    /** Gabarit HTML de l'email : styles en ligne pour la compatibilité clients mail. */
    public static function wrap(string $body, array $vars, ?string $trackToken = null): string
    {
        // Les clients mail ignorent souvent les feuilles de style : on remplace
        // la classe « bouton » par ses styles en ligne.
        $buttonStyle = 'display:inline-block;background:#1f4ed8;color:#ffffff;text-decoration:none;'
            . 'padding:14px 26px;border-radius:8px;font-weight:600;font-size:16px;margin:8px 0;';
        $body = str_replace(['class="bouton"', "class='bouton'"], 'style="' . $buttonStyle . '"', $body);

        $pixel = '';
        if ($trackToken !== null) {
            $pixel = '<img src="' . e(Router::publicUrl('track_open', ['t' => $trackToken]))
                . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
        }

        $unsub = e($vars['lien_desinscription'] ?? '');
        $sender = e((string) Config::get('smtp.from_name', ''));
        $fromEmail = e((string) Config::get('smtp.from_email', ''));

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$sender}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Une maquette de votre nouveau site vous attend.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;padding:32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#1f2933;">
<tr><td>
{$body}
</td></tr>
<tr><td style="padding-top:28px;border-top:1px solid #e4e7eb;margin-top:24px;color:#7b8794;font-size:13px;line-height:1.5;">
<p style="margin:12px 0 0;">Vous recevez ce message parce que votre entreprise est visible publiquement en ligne. <a href="{$unsub}" style="color:#7b8794;">Je ne souhaite plus être contacté</a>.</p>
<p style="margin:8px 0 0;">{$sender} — {$fromEmail}</p>
</td></tr>
</table>
</td></tr>
</table>
{$pixel}
</body>
</html>
HTML;
    }

    /**
     * Envoie une étape de la séquence à un prospect.
     * @return array{ok:bool,error:string,token:?string,log:array}
     */
    public static function sendStep(array $prospect, int $step): array
    {
        $guard = self::guard($prospect, $step);
        if ($guard !== null) {
            return ['ok' => false, 'error' => $guard, 'token' => null, 'log' => []];
        }

        $token = Tracking::register((string) $prospect['id'], $step, '');
        $rendered = self::render($prospect, $step, $token);

        $result = self::deliver(
            (string) $prospect['email'],
            Prospect::contactName($prospect),
            $rendered['subject'],
            $rendered['html'],
            $rendered['text'],
            (string) ($prospect['tokens']['unsub'] ?? '')
        );

        if (!$result['ok']) {
            Events::log((string) $prospect['id'], 'error', ['step' => $step, 'message' => $result['error']]);
            return ['ok' => false, 'error' => $result['error'], 'token' => $token, 'log' => $result['log']];
        }

        Store::mutate(Tracking::path(), static function (array $sends) use ($token, $rendered): array {
            if (isset($sends[$token])) {
                $sends[$token]['subject'] = $rendered['subject'];
            }
            return $sends;
        });

        Events::log((string) $prospect['id'], Events::SENT, [
            'step' => $step,
            'subject' => $rendered['subject'],
            'to' => $prospect['email'],
        ]);

        return ['ok' => true, 'error' => '', 'token' => $token, 'log' => $result['log']];
    }

    /** Vérifications de sécurité avant tout envoi. */
    private static function guard(array $prospect, int $step): ?string
    {
        if (trim((string) Config::get('smtp.host', '')) === '') {
            return 'Le serveur SMTP n\'est pas configuré (Réglages → Envoi des emails).';
        }
        if (trim((string) Config::get('smtp.from_email', '')) === '') {
            return 'L\'adresse d\'expédition n\'est pas renseignée.';
        }
        if (!Util::isEmail((string) ($prospect['email'] ?? ''))) {
            return 'Le prospect n\'a pas d\'adresse email valide.';
        }
        if (Suppression::has((string) $prospect['email'])) {
            return 'Cette adresse est sur la liste de désinscription.';
        }
        if (empty($prospect['mockup']['validated'])) {
            return 'La maquette doit être validée avant tout envoi.';
        }
        $template = Templates::get($step);
        if (empty($template['enabled'])) {
            return 'L\'email ' . $step . ' est désactivé dans les modèles.';
        }
        return null;
    }

    /**
     * Remise SMTP d'un message quelconque.
     * @return array{ok:bool,error:string,log:array}
     */
    public static function deliver(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        string $unsubToken = ''
    ): array {
        $fromEmail = (string) Config::get('smtp.from_email', '');
        $fromName = (string) Config::get('smtp.from_name', '');
        $replyTo = (string) Config::get('smtp.reply_to', '');
        $domain = substr($fromEmail, strpos($fromEmail, '@') + 1) ?: 'localhost';

        $message = new Message($fromEmail, $fromName, $toEmail, $toName, $subject, $html, $text);
        $message->messageId($domain);
        $message->header('Reply-To', $replyTo !== '' ? $replyTo : $fromEmail);
        $message->header('X-Mailer', 'Prospect Studio ' . APP_VERSION);
        $message->header('Auto-Submitted', 'auto-generated');

        if ($unsubToken !== '') {
            $link = Router::publicUrl('unsubscribe', ['t' => $unsubToken]);
            $message->header('List-Unsubscribe', '<' . $link . '>' . ($replyTo !== '' ? ', <mailto:' . $replyTo . '>' : ''));
            $message->header('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        $smtp = Smtp::fromConfig();
        $result = $smtp->send($fromEmail, [$toEmail], $message->build());
        return ['ok' => $result['ok'], 'error' => $result['error'], 'log' => $result['log']];
    }

    /** Notification interne (alerte d'intérêt, résumé du cron). */
    public static function notify(string $subject, string $html): bool
    {
        $to = trim((string) Config::get('alerts.email', ''));
        if ($to === '' || !Util::isEmail($to) || trim((string) Config::get('smtp.host', '')) === '') {
            return false;
        }
        $result = self::deliver($to, '', $subject, $html, Message::htmlToText($html));
        return $result['ok'];
    }
}
