<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/config.php';

$db = database();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function fgTableExists(PDO $db, string $table): bool
{
    $query = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name'
    );
    $query->execute(['name' => $table]);
    return (bool)$query->fetchColumn();
}

try {
    if (!fgTableExists($db, 'events') || !fgTableExists($db, 'users')) {
        throw new RuntimeException('Run database/schema.sql or scripts/migrate-multiuser.php first.');
    }

    // Idempotently create family_groups / family_group_members / event_family_invites
    // (and any other missing tables) straight from the versioned schema file.
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

    $hasContacts = fgTableExists($db, 'contacts');
    $hasInvitations = fgTableExists($db, 'event_invitations');
    $migratedGroups = 0;
    $migratedInvites = 0;

    if ($hasContacts) {
        $contactCount = (int)$db->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
        if ($contactCount > 0) {
            $db->beginTransaction();
            try {
                // One family_group per legacy contact, headcount inferred from its type.
                $db->exec(
                    "INSERT INTO family_groups
                        (user_id, family_label, responsible_name, responsible_email,
                         estimated_adults, estimated_children, notes)
                     SELECT
                        c.user_id, c.name, c.name, c.email,
                        CASE WHEN c.type = 'adult' THEN 1 ELSE 0 END,
                        CASE WHEN c.type = 'child' THEN 1 ELSE 0 END,
                        NULLIF(TRIM(CONCAT_WS(' · ', c.dietary_restrictions, c.notes)), '')
                     FROM contacts c
                     WHERE NOT EXISTS (
                        SELECT 1 FROM family_groups fg
                        WHERE fg.user_id = c.user_id AND fg.family_label = c.name
                     )"
                );
                $migratedGroups = $db->query('SELECT ROW_COUNT()')->fetchColumn();

                if ($hasInvitations) {
                    $invitationCount = (int)$db->query('SELECT COUNT(*) FROM event_invitations')->fetchColumn();
                    if ($invitationCount > 0) {
                        // Legacy per-person RSVP tokens cannot be reused (they were hashed
                        // with a different, per-person scheme); invites carry over as
                        // 'pending' with no active link. Organizers must re-send links.
                        $db->exec(
                            "INSERT IGNORE INTO event_family_invites
                                (event_id, family_group_id, family_label, responsible_name,
                                 responsible_email, estimated_adults, estimated_children, status)
                             SELECT
                                ei.event_id, fg.id, fg.family_label, fg.responsible_name,
                                fg.responsible_email, fg.estimated_adults, fg.estimated_children,
                                'pending'
                             FROM event_invitations ei
                             JOIN contacts c ON c.id = ei.contact_id
                             JOIN family_groups fg ON fg.user_id = c.user_id AND fg.family_label = c.name
                             WHERE ei.revoked_at IS NULL"
                        );
                        $migratedInvites = $db->query('SELECT ROW_COUNT()')->fetchColumn();
                    }
                }
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
        }
    }

    if ($hasInvitations) {
        $db->exec('DROP TABLE event_invitations');
    }
    if ($hasContacts) {
        $db->exec('DROP TABLE contacts');
    }

    $db->exec(
        "INSERT IGNORE INTO schema_migrations (version, applied_at)
         VALUES ('002_family_roster_model', CURRENT_TIMESTAMP)"
    );

    fwrite(STDOUT, "Family roster model ready. Migrated {$migratedGroups} legacy contact(s) into family_groups and {$migratedInvites} legacy invitation(s) into event_family_invites (as pending; re-send links).\n");
    fwrite(STDOUT, "Dropped legacy contacts/event_invitations tables if they existed.\n");
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log((string)$error);
    fwrite(STDERR, "Migration failed: " . $error->getMessage() . "\n");
    exit(1);
}
