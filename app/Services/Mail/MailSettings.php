<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Core\App;
use Core\Crypto;
use Core\Database;

/**
 * Lee/escribe la configuración de correo de un sitio en la tabla `settings`.
 * La contraseña SMTP se guarda cifrada (AES-256-GCM con `app_key`), igual que
 * las API keys de IA.
 */
final class MailSettings
{
    public const KEY_TRANSPORT   = 'mail_transport';      // 'smtp' (único por ahora)
    public const KEY_SMTP_HOST   = 'mail_smtp_host';
    public const KEY_SMTP_PORT   = 'mail_smtp_port';
    public const KEY_SMTP_USER   = 'mail_smtp_user';
    public const KEY_SMTP_PASS   = 'mail_smtp_pass';      // cifrada
    public const KEY_SMTP_ENC    = 'mail_smtp_encryption'; // 'tls' | 'ssl' | 'none'
    public const KEY_FROM_EMAIL  = 'mail_from_email';
    public const KEY_FROM_NAME   = 'mail_from_name';

    /**
     * Devuelve la config de correo del sitio con la contraseña ya descifrada.
     *
     * @return array{transport:string,host:string,port:int,user:string,pass:string,encryption:string,from_email:string,from_name:string}
     */
    public static function forSite(int $siteId): array
    {
        $rows = Database::select(
            'SELECT setting_key, setting_value, is_encrypted
             FROM settings WHERE site_id = ? AND setting_key IN (?,?,?,?,?,?,?,?)',
            [
                $siteId,
                self::KEY_TRANSPORT, self::KEY_SMTP_HOST, self::KEY_SMTP_PORT,
                self::KEY_SMTP_USER, self::KEY_SMTP_PASS, self::KEY_SMTP_ENC,
                self::KEY_FROM_EMAIL, self::KEY_FROM_NAME,
            ]
        );

        $vals = [];
        $appKey = (string) (App::config()['app_key'] ?? '');
        foreach ($rows as $row) {
            $value = (string) ($row['setting_value'] ?? '');
            if ((int) ($row['is_encrypted'] ?? 0) === 1 && $value !== '' && $appKey !== '') {
                try {
                    $value = Crypto::decrypt($value, $appKey);
                } catch (\Throwable) {
                    $value = '';
                }
            }
            $vals[(string) $row['setting_key']] = $value;
        }

        return [
            'transport'  => $vals[self::KEY_TRANSPORT]  ?? 'smtp',
            'host'       => $vals[self::KEY_SMTP_HOST]   ?? '',
            'port'       => (int) ($vals[self::KEY_SMTP_PORT] ?? 0),
            'user'       => $vals[self::KEY_SMTP_USER]   ?? '',
            'pass'       => $vals[self::KEY_SMTP_PASS]   ?? '',
            'encryption' => $vals[self::KEY_SMTP_ENC]    ?? 'tls',
            'from_email' => $vals[self::KEY_FROM_EMAIL]  ?? '',
            'from_name'  => $vals[self::KEY_FROM_NAME]   ?? '',
        ];
    }

    /** ¿Hay datos mínimos para poder enviar? (host + remitente). */
    public static function isConfigured(int $siteId): bool
    {
        $c = self::forSite($siteId);
        return $c['host'] !== '' && $c['from_email'] !== '';
    }
}
