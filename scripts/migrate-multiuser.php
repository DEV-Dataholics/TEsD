<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/config.php';

$db = database();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function tableExists(PDO $db, string $table): bool
{
    $query = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name'
    );
    $query->execute(['name' => $table]);
    return (bool)$query->fetchColumn();
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $query = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $query->execute(['table' => $table, 'column' => $column]);
    return (bool)$query->fetchColumn();
}

function indexExists(PDO $db, string $table, string $index): bool
{
    $query = $db->prepare(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :name'
    );
    $query->execute(['table' => $table, 'name' => $index]);
    return (bool)$query->fetchColumn();
}

function foreignKeyExists(PDO $db, string $table, string $constraint): bool
{
    $query = $db->prepare(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $query->execute(['table' => $table, 'name' => $constraint]);
    return (bool)$query->fetchColumn();
}

try {
    if (!tableExists($db, 'events') || !tableExists($db, 'families') || !tableExists($db, 'family_members')) {
        throw new RuntimeException('Run database/schema.sql or restore the legacy guest-list schema first.');
    }

    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Could not read database/schema.sql.');
    }
    foreach (explode(';', $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->exec($statement);
        }
    }

    if (!columnExists($db, 'events', 'owner_user_id')) {
        $db->exec('ALTER TABLE events ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER id');
    }
    if (!indexExists($db, 'events', 'idx_events_owner_date')) {
        $db->exec('CREATE INDEX idx_events_owner_date ON events (owner_user_id, event_date)');
    }
    if (!foreignKeyExists($db, 'events', 'fk_events_owner')) {
        $db->exec(
            'ALTER TABLE events ADD CONSTRAINT fk_events_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    $adminQuery = $db->prepare(
        "SELECT id FROM users WHERE email = 'development@dataholics.com.mx' AND role = 'admin' AND status = 'active' LIMIT 1"
    );
    $adminQuery->execute();
    $adminId = $adminQuery->fetchColumn();
    if (!$adminId) {
        fwrite(STDOUT, "Multi-user tables are ready. Create the default admin with scripts/bootstrap-admin.php, then rerun this migration.\n");
        exit(0);
    }

    $assignEvents = $db->prepare('UPDATE events SET owner_user_id = :admin_id WHERE owner_user_id IS NULL');
    $assignEvents->execute(['admin_id' => $adminId]);

    $unowned = (int)$db->query('SELECT COUNT(*) FROM events WHERE owner_user_id IS NULL')->fetchColumn();
    if ($unowned !== 0) {
        throw new RuntimeException('Some events still have no owner; guest data migration was stopped.');
    }
    $ownerNullableQuery = $db->prepare(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'owner_user_id'"
    );
    $ownerNullableQuery->execute();
    if ($ownerNullableQuery->fetchColumn() === 'YES') {
        $db->exec('ALTER TABLE events MODIFY owner_user_id BIGINT UNSIGNED NOT NULL');
    }

    $db->beginTransaction();
    $db->exec(
        'INSERT IGNORE INTO contacts
            (user_id, source_legacy_member_id, name, age, type, dietary_restrictions, notes)
         SELECT e.owner_user_id, fm.id, fm.name, fm.age, fm.type, fm.dietary_restrictions, fm.notes
         FROM family_members fm
         JOIN families f ON f.id = fm.family_id
         JOIN events e ON e.id = f.event_id
         WHERE e.owner_user_id IS NOT NULL'
    );
    $db->exec(
        "INSERT IGNORE INTO event_invitations
            (event_id, contact_id, legacy_family_id, guest_name, guest_email, status)
         SELECT f.event_id, c.id, f.id, c.name, c.email, 'pending'
         FROM family_members fm
         JOIN families f ON f.id = fm.family_id
         JOIN events e ON e.id = f.event_id
         JOIN contacts c ON c.source_legacy_member_id = fm.id AND c.user_id = e.owner_user_id"
    );
    $db->exec(
        "INSERT IGNORE INTO schema_migrations (version, applied_at)
         VALUES ('001_multiuser_guest_migration', CURRENT_TIMESTAMP)"
    );
    $db->commit();

    $eventCount = (int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $contactCount = (int)$db->query('SELECT COUNT(*) FROM contacts WHERE user_id = ' . (int)$adminId)->fetchColumn();
    $invitationCount = (int)$db->query('SELECT COUNT(*) FROM event_invitations WHERE legacy_family_id IS NOT NULL')->fetchColumn();
    fwrite(STDOUT, "Migration verified. Owned events: {$eventCount}; legacy roster contacts: {$contactCount}; pending legacy invitations: {$invitationCount}.\n");
    fwrite(STDOUT, "Legacy family status and actual_count were preserved unchanged.\n");
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log((string)$error);
    fwrite(STDERR, "Migration failed. See the server error log; no credentials were printed.\n");
    exit(1);
}
