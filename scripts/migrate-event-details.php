<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/config.php';

$db = database();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function edColumnExists(PDO $db, string $table, string $column): bool
{
    $query = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $query->execute(['table' => $table, 'column' => $column]);
    return (bool)$query->fetchColumn();
}

$columns = [
    'event_time' => 'TIME NULL',
    'theme' => 'VARCHAR(160) NULL',
    'venue_name' => 'VARCHAR(160) NULL',
    'venue_address' => 'VARCHAR(300) NULL',
    'venue_capacity' => 'SMALLINT UNSIGNED NULL',
    'venue_facilities' => 'JSON NULL',
    'host_notes' => 'TEXT NULL',
];

$added = [];
foreach ($columns as $column => $definition) {
    if (!edColumnExists($db, 'events', $column)) {
        $db->exec("ALTER TABLE events ADD COLUMN {$column} {$definition}");
        $added[] = $column;
    }
}

if ($added) {
    echo 'Added columns to events: ' . implode(', ', $added) . PHP_EOL;
} else {
    echo 'events table already has all venue/theme/host-only columns; nothing to do.' . PHP_EOL;
}
