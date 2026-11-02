<?php

declare(strict_types=1);

/**
 * ASST-V2 F6d — El chat del Studio guarda su conversación en la misma tabla
 * que el asistente, una por página. page_id NULL = conversación del asistente
 * (todas las que ya existían). Sin clave foránea: si la página se borra, su
 * conversación se va igual a los 30 días (AssistantConversationStore::prune).
 */
return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute(['assistant_conversations', 'page_id']);
    if ($stmt->fetchColumn()) {
        return;
    }

    $pdo->exec('ALTER TABLE assistant_conversations
        ADD COLUMN page_id INT UNSIGNED NULL AFTER user_id,
        ADD KEY idx_ac_page (site_id, user_id, page_id, updated_at)');
};
