<?php

declare(strict_types=1);

namespace App\Services;

use Core\App;
use Core\Database;

final class FormSubmissionService
{
    public static function ensureSchema(): void
    {
        Database::execute(
            "CREATE TABLE IF NOT EXISTS form_submissions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id INT UNSIGNED NOT NULL,
                page_id INT UNSIGNED NOT NULL,
                section_id INT UNSIGNED NOT NULL,
                page_title VARCHAR(500) NOT NULL,
                section_heading VARCHAR(255) DEFAULT NULL,
                sender_name VARCHAR(255) DEFAULT NULL,
                sender_email VARCHAR(255) DEFAULT NULL,
                sender_phone VARCHAR(100) DEFAULT NULL,
                payload JSON NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                status ENUM('unread','read') NOT NULL DEFAULT 'unread',
                email_status ENUM('skipped','sent','failed') NOT NULL DEFAULT 'skipped',
                email_error VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME DEFAULT NULL,
                INDEX idx_form_submissions_site_date (site_id, created_at),
                INDEX idx_form_submissions_page_date (page_id, created_at),
                INDEX idx_form_submissions_section_date (section_id, created_at),
                INDEX idx_form_submissions_status (site_id, status),
                INDEX idx_form_submissions_rate (section_id, ip_hash, created_at),
                CONSTRAINT fk_form_submissions_site
                    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_form_submissions_page
                    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
                CONSTRAINT fk_form_submissions_section
                    FOREIGN KEY (section_id) REFERENCES page_sections(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function ipHash(string $ip): string
    {
        $key = (string) (App::config()['app_key'] ?? 'promptpress');
        return hash_hmac('sha256', $ip, $key);
    }

    public static function isRateLimited(int $sectionId, string $ipHash): bool
    {
        self::ensureSchema();
        $row = Database::selectOne(
            'SELECT COUNT(*) AS n
             FROM form_submissions
             WHERE section_id = ? AND ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
            [$sectionId, $ipHash]
        );
        return (int) ($row['n'] ?? 0) >= 5;
    }

    public static function recipientForSite(int $siteId): ?string
    {
        $memory = Database::selectOne(
            'SELECT field_value FROM site_memory WHERE site_id = ? AND field_key = ? LIMIT 1',
            [$siteId, 'contact_info']
        );
        $text = (string) ($memory['field_value'] ?? '');
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            return $m[0];
        }

        $user = Database::selectOne('SELECT email FROM users ORDER BY id ASC LIMIT 1');
        $email = (string) ($user['email'] ?? '');
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @param array<string,string> $payload */
    public static function emailBody(array $meta, array $payload): string
    {
        $lines = [
            'Nuevo mensaje recibido desde PromptPress',
            '',
            'Página: ' . (string) ($meta['page_title'] ?? ''),
            'Sección: ' . (string) ($meta['section_heading'] ?? 'Formulario'),
            'Fecha: ' . date('Y-m-d H:i:s'),
            '',
            'Datos enviados:',
        ];

        foreach ($payload as $label => $value) {
            $lines[] = '- ' . $label . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
