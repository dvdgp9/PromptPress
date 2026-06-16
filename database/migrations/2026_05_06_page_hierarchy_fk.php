<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'pages'
           AND CONSTRAINT_NAME = 'fk_pages_parent'
         LIMIT 1"
    );
    $stmt->execute();
    if ($stmt->fetchColumn()) {
        return;
    }

    $pdo->exec(
        'ALTER TABLE pages
         ADD CONSTRAINT fk_pages_parent
         FOREIGN KEY (parent_id) REFERENCES pages(id) ON DELETE SET NULL'
    );
};
