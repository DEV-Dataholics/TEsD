<?php
declare(strict_types=1);

function contactForUser(PDO $db, array $user, int $contactId, string $action = 'read'): array
{
    $query = $db->prepare('SELECT * FROM contacts WHERE id = :id LIMIT 1');
    $query->execute(['id' => $contactId]);
    $contact = $query->fetch();
    if (!$contact || ((int)$contact['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin')) {
        respond(['error' => 'Contacto no encontrado.'], 404);
    }
    $contact['id'] = (int)$contact['id'];
    $contact['user_id'] = (int)$contact['user_id'];
    if ($contact['user_id'] !== (int)$user['id']) {
        auditAdminAction($db, $user, 'cross_tenant_contact_' . $action, 'contact', $contactId, ['owner_user_id' => $contact['user_id']]);
    }
    return $contact;
}

function validatedContact(array $data, array $current = []): array
{
    $member = cleanMember(array_merge([
        'name' => $current['name'] ?? '',
        'age' => $current['age'] ?? null,
        'type' => $current['type'] ?? 'adult',
        'dietary_restrictions' => $current['dietary_restrictions'] ?? '',
        'notes' => $current['notes'] ?? '',
    ], $data));
    $emailValue = trim((string)($data['email'] ?? $current['email'] ?? ''));
    $email = $emailValue === '' ? null : strtolower($emailValue);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El correo del contacto no es válido.');
    }
    return $member + ['email' => $email];
}

function handleDomainRoutes(PDO $db, string $path, string $method, array $user): bool
{
    if ($path === '/contacts' && $method === 'GET') {
        $search = trim((string)($_GET['search'] ?? ''));
        $params = [];
        $sql = 'SELECT c.id, c.user_id, c.name, c.email, c.age, c.type, c.dietary_restrictions, c.notes, c.created_at';
        if ($user['role'] === 'admin') {
            auditAdminAction($db, $user, 'list_all_contacts', 'contact', null);
            $sql .= ', u.email AS owner_email FROM contacts c JOIN users u ON u.id = c.user_id';
        } else {
            $sql .= ' FROM contacts c WHERE c.user_id = :owner';
            $params['owner'] = $user['id'];
        }
        if ($search !== '') {
            $sql .= $user['role'] === 'admin' ? ' WHERE' : ' AND';
            $sql .= ' (c.name LIKE :search OR c.email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY c.name, c.id LIMIT 500';
        $query = $db->prepare($sql);
        $query->execute($params);
        $contacts = $query->fetchAll();
        foreach ($contacts as &$contact) {
            $contact['id'] = (int)$contact['id'];
            $contact['user_id'] = (int)$contact['user_id'];
            $contact['age'] = $contact['age'] === null ? null : (int)$contact['age'];
        }
        unset($contact);
        respond(['data' => $contacts]);
    }

    if ($path === '/contacts' && $method === 'POST') {
        $contact = validatedContact(requestData());
        $insert = $db->prepare(
            'INSERT INTO contacts (user_id, name, email, age, type, dietary_restrictions, notes)
             VALUES (:user, :name, :email, :age, :type, :diet, :notes)'
        );
        $insert->execute([
            'user' => $user['id'],
            'name' => $contact['name'],
            'email' => $contact['email'],
            'age' => $contact['age'],
            'type' => $contact['type'],
            'diet' => $contact['dietary_restrictions'] ?: null,
            'notes' => $contact['notes'] ?: null,
        ]);
        respond(['data' => ['id' => (int)$db->lastInsertId()]], 201);
    }

    if (preg_match('#^/contacts/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $contactId = (int)$matches[1];
        $current = contactForUser($db, $user, $contactId, 'update');
        $contact = validatedContact(requestData(), $current);
        $update = $db->prepare(
            'UPDATE contacts SET name = :name, email = :email, age = :age, type = :type,
                dietary_restrictions = :diet, notes = :notes WHERE id = :id'
        );
        $update->execute([
            'name' => $contact['name'],
            'email' => $contact['email'],
            'age' => $contact['age'],
            'type' => $contact['type'],
            'diet' => $contact['dietary_restrictions'] ?: null,
            'notes' => $contact['notes'] ?: null,
            'id' => $contactId,
        ]);
        respond(['data' => ['id' => $contactId]]);
    }

    if (preg_match('#^/contacts/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        $contactId = (int)$matches[1];
        contactForUser($db, $user, $contactId, 'delete');
        $db->prepare('DELETE FROM contacts WHERE id = :id')->execute(['id' => $contactId]);
        respond(['message' => 'Contacto eliminado; las invitaciones conservan sus datos históricos.']);
    }

    if (preg_match('#^/events/(\d+)/invitations$#', $path, $matches) && $method === 'GET') {
        $eventId = (int)$matches[1];
        requireEventAccess($db, $user, $eventId);
        $query = $db->prepare(
            'SELECT i.id, i.event_id, i.contact_id, i.guest_name, i.guest_email,
                    i.status, i.responded_at, i.created_at, c.user_id AS contact_owner_id
             FROM event_invitations i LEFT JOIN contacts c ON c.id = i.contact_id
             WHERE i.event_id = :event_id AND i.revoked_at IS NULL
             ORDER BY i.guest_name, i.id'
        );
        $query->execute(['event_id' => $eventId]);
        $invitations = $query->fetchAll();
        foreach ($invitations as &$invitation) {
            $invitation['id'] = (int)$invitation['id'];
            $invitation['event_id'] = (int)$invitation['event_id'];
            $invitation['contact_id'] = $invitation['contact_id'] === null ? null : (int)$invitation['contact_id'];
            unset($invitation['contact_owner_id']);
        }
        unset($invitation);
        respond(['data' => $invitations]);
    }

    if (preg_match('#^/events/(\d+)/invitations$#', $path, $matches) && $method === 'POST') {
        $eventId = (int)$matches[1];
        $event = requireEventAccess($db, $user, $eventId, 'invite');
        $data = requestData();
        $contactIds = $data['contact_ids'] ?? [];
        if (!is_array($contactIds) || count($contactIds) < 1 || count($contactIds) > 500) {
            throw new InvalidArgumentException('Selecciona entre 1 y 500 contactos.');
        }
        $normalizedIds = [];
        foreach ($contactIds as $rawId) {
            $contactId = filter_var($rawId, FILTER_VALIDATE_INT);
            if ($contactId === false || $contactId < 1) {
                throw new InvalidArgumentException('La selección de contactos no es válida.');
            }
            $normalizedIds[] = $contactId;
        }
        $contactIds = array_values(array_unique($normalizedIds));
        if (count($contactIds) !== count($normalizedIds)) {
            throw new InvalidArgumentException('La selección de contactos no es válida.');
        }
        $baseUrl = appBaseUrl();
        $contactQuery = $db->prepare('SELECT * FROM contacts WHERE id = :id');
        $existingQuery = $db->prepare('SELECT id FROM event_invitations WHERE event_id = :event AND contact_id = :contact');
        $insert = $db->prepare(
            "INSERT INTO event_invitations
                (event_id, contact_id, guest_name, guest_email, status, response_token_hash, response_expires_at)
             VALUES (:event, :contact, :name, :email, 'pending', :token_hash, :expires)"
        );
        $created = [];
        $expiresAt = $event['event_date']
            ? date('Y-m-d 23:59:59', strtotime($event['event_date'] . ' +7 days'))
            : date('Y-m-d H:i:s', time() + 90 * 86400);

        $db->beginTransaction();
        try {
            foreach ($contactIds as $contactId) {
                $contact = contactForUser($db, $user, $contactId, 'invite');
                if ((int)$contact['user_id'] !== (int)$event['owner_user_id'] && $user['role'] !== 'admin') {
                    throw new InvalidArgumentException('Solo puedes invitar contactos de tu roster.');
                }
                $existingQuery->execute(['event' => $eventId, 'contact' => $contactId]);
                if ($existingQuery->fetchColumn()) {
                    throw new InvalidArgumentException('Uno o más contactos ya están invitados a este evento.');
                }
                $rawToken = bin2hex(random_bytes(32));
                $insert->execute([
                    'event' => $eventId,
                    'contact' => $contactId,
                    'name' => $contact['name'],
                    'email' => $contact['email'],
                    'token_hash' => hash('sha256', $rawToken),
                    'expires' => $expiresAt,
                ]);
                $created[] = [
                    'id' => (int)$db->lastInsertId(),
                    'guest_name' => $contact['name'],
                    'guest_email' => $contact['email'],
                    'rsvp_url' => $baseUrl . '/#rsvp=' . rawurlencode($rawToken),
                ];
                unset($rawToken);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        foreach ($created as &$invite) {
            $invite['email_sent'] = false;
            if ($invite['guest_email']) {
                $name = htmlspecialchars($invite['guest_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $url = htmlspecialchars($invite['rsvp_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $title = htmlspecialchars($event['event_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $invite['email_sent'] = sendAccountEmail(
                    $invite['guest_email'],
                    'Invitación: ' . $event['event_name'],
                    '<p>Hola ' . $name . ':</p><p>Estás invitado/a a ' . $title . '.</p><p><a href="' . $url . '">Responder invitación</a></p>'
                );
            }
        }
        unset($invite);
        respond(['data' => $created], 201);
    }

    if (preg_match('#^/invitations/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        $invitationId = (int)$matches[1];
        $query = $db->prepare('SELECT event_id FROM event_invitations WHERE id = :id AND revoked_at IS NULL');
        $query->execute(['id' => $invitationId]);
        $eventId = $query->fetchColumn();
        if (!$eventId) {
            respond(['error' => 'Invitación no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$eventId, 'revoke_invitation');
        $db->prepare('UPDATE event_invitations SET revoked_at = CURRENT_TIMESTAMP, response_token_hash = NULL WHERE id = :id')
            ->execute(['id' => $invitationId]);
        respond(['message' => 'Invitación revocada.']);
    }

    if (preg_match('#^/invitations/(\d+)/link$#', $path, $matches) && $method === 'POST') {
        $invitationId = (int)$matches[1];
        $query = $db->prepare(
            'SELECT i.event_id, i.guest_name, i.guest_email, e.event_date
             FROM event_invitations i JOIN events e ON e.id = i.event_id
             WHERE i.id = :id AND i.revoked_at IS NULL LIMIT 1'
        );
        $query->execute(['id' => $invitationId]);
        $invitation = $query->fetch();
        if (!$invitation) {
            respond(['error' => 'Invitación no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$invitation['event_id'], 'rotate_rsvp_link');
        $token = bin2hex(random_bytes(32));
        $expiresAt = $invitation['event_date']
            ? date('Y-m-d 23:59:59', strtotime($invitation['event_date'] . ' +7 days'))
            : date('Y-m-d H:i:s', time() + 90 * 86400);
        $update = $db->prepare(
            'UPDATE event_invitations SET response_token_hash = :hash, response_expires_at = :expires
             WHERE id = :id AND revoked_at IS NULL'
        );
        $update->execute([
            'hash' => hash('sha256', $token),
            'expires' => $expiresAt,
            'id' => $invitationId,
        ]);
        $url = appBaseUrl() . '/#rsvp=' . rawurlencode($token);
        unset($token);
        respond(['data' => ['rsvp_url' => $url, 'expires_at' => $expiresAt]]);
    }

    if (preg_match('#^/admin/users/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        if ($user['role'] !== 'admin') {
            respond(['error' => 'No autorizado.'], 403);
        }
        $targetId = (int)$matches[1];
        $data = requestData();
        $role = (string)($data['role'] ?? '');
        $status = (string)($data['status'] ?? '');
        if (($role !== '' && !in_array($role, ['user', 'admin'], true))
            || ($status !== '' && !in_array($status, ['active', 'disabled'], true))
            || ($role === '' && $status === '')) {
            throw new InvalidArgumentException('El rol o estado no es válido.');
        }
        $targetQuery = $db->prepare('SELECT id, role, status FROM users WHERE id = :id');
        $targetQuery->execute(['id' => $targetId]);
        $target = $targetQuery->fetch();
        if (!$target) {
            respond(['error' => 'Usuario no encontrado.'], 404);
        }
        $newRole = $role ?: $target['role'];
        $newStatus = $status ?: $target['status'];
        if ($targetId === (int)$user['id'] && ($newRole !== 'admin' || $newStatus !== 'active')) {
            respond(['error' => 'No puedes desactivar ni quitar tu propio rol de administrador.'], 409);
        }
        if ($target['role'] === 'admin' && $target['status'] === 'active' && ($newRole !== 'admin' || $newStatus !== 'active')) {
            $activeAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($activeAdmins <= 1) {
                respond(['error' => 'Debe permanecer al menos un administrador activo.'], 409);
            }
        }
        $update = $db->prepare('UPDATE users SET role = :role, status = :status WHERE id = :id');
        $update->execute(['role' => $newRole, 'status' => $newStatus, 'id' => $targetId]);
        auditAdminAction($db, $user, 'update_user', 'user', $targetId, ['role' => $newRole, 'status' => $newStatus]);
        if ($newStatus === 'disabled') {
            $db->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $targetId]);
        }
        respond(['message' => 'Usuario actualizado.']);
    }

    if ($path === '/admin/users' && $method === 'GET') {
        if ($user['role'] !== 'admin') {
            respond(['error' => 'No autorizado.'], 403);
        }
        auditAdminAction($db, $user, 'list_users', 'user', null);
        $users = $db->query(
            'SELECT id, display_name, email, role, status, email_verified_at, created_at
             FROM users ORDER BY created_at DESC LIMIT 1000'
        )->fetchAll();
        foreach ($users as &$row) {
            $row['id'] = (int)$row['id'];
        }
        unset($row);
        respond(['data' => $users]);
    }

    if ($path === '/admin/audit' && $method === 'GET') {
        if ($user['role'] !== 'admin') {
            respond(['error' => 'No autorizado.'], 403);
        }
        $query = $db->query(
            'SELECT id, actor_user_id, action, target_type, target_id, metadata, ip_hash, created_at
             FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT 500'
        );
        $logs = $query->fetchAll();
        auditAdminAction($db, $user, 'view_audit_log', 'audit_log', null);
        respond(['data' => $logs]);
    }

    return false;
}
