<?php
declare(strict_types=1);

/**
 * Outbound mail via PHPMailer SMTP.
 */

if (!function_exists('portal_mail_env')) {
    function portal_mail_env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        return trim((string) $value);
    }
}

if (!function_exists('portal_mail_configured')) {
    function portal_mail_configured(): bool
    {
        return portal_mail_env('SMTP_HOST') !== ''
            && portal_mail_env('SMTP_FROM_EMAIL') !== ''
            && class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
    }
}

if (!function_exists('portal_mail_html_to_text')) {
    function portal_mail_html_to_text(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\s*\/p\s*>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}

if (!function_exists('portal_mail_send')) {
    /**
     * Send an email. Returns false when SMTP is not configured, PHPMailer is
     * missing, or delivery fails. Failures are logged without exposing details
     * to end users.
     */
    function portal_mail_send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $to = strtolower(trim($to));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (!portal_mail_configured()) {
            if (function_exists('portal_log_security_event')) {
                portal_log_security_event(
                    'mail_not_configured',
                    'low',
                    'Outbound mail skipped: SMTP not configured or PHPMailer missing'
                );
            }
            return false;
        }

        if ($textBody === '') {
            $textBody = portal_mail_html_to_text($htmlBody);
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = portal_mail_env('SMTP_HOST');
            $mail->Port = (int) (portal_mail_env('SMTP_PORT', '587') ?: '587');
            $mail->SMTPAuth = portal_mail_env('SMTP_USER') !== '';

            if ($mail->SMTPAuth) {
                $mail->Username = portal_mail_env('SMTP_USER');
                $mail->Password = portal_mail_env('SMTP_PASS');
            }

            $encryption = strtolower(portal_mail_env('SMTP_ENCRYPTION', 'tls'));
            if ($encryption === 'ssl' || $encryption === 'smtps') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $fromEmail = portal_mail_env('SMTP_FROM_EMAIL');
            $fromName = portal_mail_env('SMTP_FROM_NAME');
            if ($fromName === '') {
                $fromName = function_exists('portal_school_name') ? portal_school_name() : 'School Portal';
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : $subject;
            $mail->CharSet = 'UTF-8';

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            if (function_exists('portal_log_security_event')) {
                portal_log_security_event(
                    'mail_send_failed',
                    'medium',
                    'Outbound mail failed: ' . substr($e->getMessage(), 0, 200)
                );
            }
            return false;
        }
    }
}
