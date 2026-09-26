SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    status ENUM('pending', 'active', 'disabled') NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    csrf_token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_token_hash (token_hash),
    KEY idx_sessions_user_expires (user_id, expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('verify_email', 'reset_password') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_tokens_hash (token_hash),
    KEY idx_account_tokens_user_purpose (user_id, purpose, expires_at),
    CONSTRAINT fk_account_tokens_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    identifier_hash CHAR(64) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME NULL,
    PRIMARY KEY (identifier_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    event_name VARCHAR(160) NOT NULL,
    event_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_events_owner_date (owner_user_id, event_date),
    CONSTRAINT fk_events_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS families (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    family_name VARCHAR(160) NOT NULL,
    status ENUM('draft', 'confirmed') NOT NULL DEFAULT 'draft',
    estimated_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    actual_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    semaphore_color ENUM('green', 'yellow', 'red') NOT NULL DEFAULT 'green',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_families_event_status (event_id, status),
    CONSTRAINT fk_families_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    age TINYINT UNSIGNED NULL,
    type ENUM('adult', 'child') NOT NULL DEFAULT 'adult',
    dietary_restrictions TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_family_members_family (family_id),
    CONSTRAINT fk_family_members_family
        FOREIGN KEY (family_id) REFERENCES families (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable roster: a family card owned by a user, invitable to any of their events.
-- Details beyond the responsible contact and headcounts are intentionally optional
-- ("flimsy") — organizers may know only "family of 4, 2 adults, 2 kids" and add
-- names/restrictions later via family_group_members.
CREATE TABLE IF NOT EXISTS family_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    family_label VARCHAR(160) NOT NULL,
    responsible_name VARCHAR(160) NOT NULL,
    responsible_email VARCHAR(254) NULL,
    responsible_phone VARCHAR(40) NULL,
    estimated_adults SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_children SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    source_legacy_family_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_family_groups_legacy (source_legacy_family_id),
    KEY idx_family_groups_user (user_id, family_label),
    CONSTRAINT fk_family_groups_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_family_groups_legacy FOREIGN KEY (source_legacy_family_id) REFERENCES families (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional, named detail for a family_group. Never required to create or invite a family.
CREATE TABLE IF NOT EXISTS family_group_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_group_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NULL,
    age TINYINT UNSIGNED NULL,
    type ENUM('adult', 'child') NOT NULL DEFAULT 'adult',
    dietary_restrictions TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_family_group_members_group (family_group_id),
    CONSTRAINT fk_family_group_members_group FOREIGN KEY (family_group_id) REFERENCES family_groups (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Join between an event and a family_group, plus the family-level RSVP link.
-- Snapshots the contact/headcount at invite time so history survives roster edits.
CREATE TABLE IF NOT EXISTS event_family_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    family_group_id BIGINT UNSIGNED NULL,
    family_label VARCHAR(160) NOT NULL,
    responsible_name VARCHAR(160) NOT NULL,
    responsible_email VARCHAR(254) NULL,
    responsible_phone VARCHAR(40) NULL,
    estimated_adults SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_children SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    actual_adults SMALLINT UNSIGNED NULL,
    actual_children SMALLINT UNSIGNED NULL,
    status ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
    response_token_hash CHAR(64) NULL,
    response_expires_at DATETIME NULL,
    responded_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_family_invite (event_id, family_group_id),
    UNIQUE KEY uq_event_family_invite_token (response_token_hash),
    KEY idx_event_family_invites_status (event_id, status),
    CONSTRAINT fk_event_family_invites_event FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_event_family_invites_group FOREIGN KEY (family_group_id) REFERENCES family_groups (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    metadata TEXT NULL,
    ip_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor_created (actor_user_id, created_at),
    KEY idx_audit_target_created (target_type, target_id, created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
