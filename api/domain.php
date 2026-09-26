<?php
declare(strict_types=1);

function familyGroupForUser(PDO $db, array $user, int $groupId, string $action = 'read'): array
{
    $query = $db->prepare('SELECT * FROM family_groups WHERE id = :id LIMIT 1');
    $query->execute(['id' => $groupId]);
    $group = $query->fetch();
    if (!$group || ((int)$group['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin')) {
        respond(['error' => 'Familia no encontrada.'], 404);
    }
    $group['id'] = (int)$group['id'];
    $group['user_id'] = (int)$group['user_id'];
    if ($group['user_id'] !== (int)$user['id']) {
        auditAdminAction($db, $user, 'cross_tenant_family_group_' . $action, 'family_group', $groupId, ['owner_user_id' => $group['user_id']]);
    }
    return $group;
}

function validatedFamilyGroup(array $data, array $current = []): array
{
    $label = trim((string)($data['family_label'] ?? $current['family_label'] ?? ''));
    if ($label === '' || mb_strlen($label) > 160) {
        throw new InvalidArgumentException('El nombre de la familia es obligatorio y debe tener máximo 160 caracteres.');
    }
    $responsibleName = trim((string)($data['responsible_name'] ?? $current['responsible_name'] ?? ''));
    if ($responsibleName === '' || mb_strlen($responsibleName) > 160) {
        throw new InvalidArgumentException('El punto de contacto responsable es obligatorio y debe tener máximo 160 caracteres.');
    }
    $emailValue = trim((string)($data['responsible_email'] ?? $current['responsible_email'] ?? ''));
    $email = $emailValue === '' ? null : strtolower($emailValue);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El correo del responsable no es válido.');
    }
    $phone = trim((string)($data['responsible_phone'] ?? $current['responsible_phone'] ?? ''));
    if (mb_strlen($phone) > 40) {
        throw new InvalidArgumentException('El teléfono del responsable debe tener máximo 40 caracteres.');
    }
    $adults = filter_var($data['estimated_adults'] ?? $current['estimated_adults'] ?? 0, FILTER_VALIDATE_INT);
    $children = filter_var($data['estimated_children'] ?? $current['estimated_children'] ?? 0, FILTER_VALIDATE_INT);
    if ($adults === false || $adults < 0 || $adults > 100 || $children === false || $children < 0 || $children > 100) {
        throw new InvalidArgumentException('Los conteos estimados deben ser números entre 0 y 100.');
    }
    if ($adults + $children < 1) {
        throw new InvalidArgumentException('La familia debe tener al menos 1 integrante estimado.');
    }
    $notes = trim((string)($data['notes'] ?? $current['notes'] ?? ''));
    if (mb_strlen($notes) > 2000) {
        throw new InvalidArgumentException('Las notas deben tener máximo 2000 caracteres.');
    }
    return [
        'family_label' => $label,
        'responsible_name' => $responsibleName,
        'responsible_email' => $email,
        'responsible_phone' => $phone ?: null,
        'estimated_adults' => $adults,
        'estimated_children' => $children,
        'notes' => $notes ?: null,
    ];
}

function familyGroupMembers(PDO $db, int $groupId): array
{
    $query = $db->prepare(
        'SELECT id, family_group_id, name, age, type, dietary_restrictions, notes, created_at
         FROM family_group_members WHERE family_group_id = :id ORDER BY id ASC'
    );
    $query->execute(['id' => $groupId]);
    $members = $query->fetchAll();
    foreach ($members as &$member) {
        $member['id'] = (int)$member['id'];
        $member['family_group_id'] = (int)$member['family_group_id'];
        $member['age'] = $member['age'] === null ? null : (int)$member['age'];
    }
    unset($member);
    return $members;
}

function familyGroupComplexity(array $group, array $members): string
{
    // Details beyond the responsible contact and headcounts are optional, so
    // complexity is derived from whatever loose notes/restrictions exist rather
    // than requiring named members up front.
    $signalRows = $members;
    if (trim((string)($group['notes'] ?? '')) !== '') {
        $signalRows[] = ['dietary_restrictions' => '', 'notes' => $group['notes']];
    }
    return complexityColor($signalRows);
}

function handleDomainRoutes(PDO $db, string $path, string $method, array $user): bool
{
    if ($path === '/family-groups' && $method === 'GET') {
        $search = trim((string)($_GET['search'] ?? ''));
        $params = [];
        $sql = 'SELECT fg.id, fg.user_id, fg.family_label, fg.responsible_name, fg.responsible_email,
                       fg.responsible_phone, fg.estimated_adults, fg.estimated_children, fg.notes, fg.created_at';
        if ($user['role'] === 'admin') {
            auditAdminAction($db, $user, 'list_all_family_groups', 'family_group', null);
            $sql .= ', u.email AS owner_email FROM family_groups fg JOIN users u ON u.id = fg.user_id';
        } else {
            $sql .= ' FROM family_groups fg WHERE fg.user_id = :owner';
            $params['owner'] = $user['id'];
        }
        if ($search !== '') {
            $sql .= $user['role'] === 'admin' ? ' WHERE' : ' AND';
            $sql .= ' (fg.family_label LIKE :search OR fg.responsible_name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY fg.family_label, fg.id LIMIT 500';
        $query = $db->prepare($sql);
        $query->execute($params);
        $groups = $query->fetchAll();
        if ($groups) {
            $ids = array_map(static fn(array $group): int => (int)$group['id'], $groups);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $memberQuery = $db->prepare(
                "SELECT id, family_group_id, name, age, type, dietary_restrictions, notes
                 FROM family_group_members WHERE family_group_id IN ({$placeholders})"
            );
            $memberQuery->execute($ids);
            $membersByGroup = [];
            foreach ($memberQuery->fetchAll() as $member) {
                $membersByGroup[(int)$member['family_group_id']][] = $member;
            }
            foreach ($groups as &$group) {
                $group['id'] = (int)$group['id'];
                $group['user_id'] = (int)$group['user_id'];
                $group['estimated_adults'] = (int)$group['estimated_adults'];
                $group['estimated_children'] = (int)$group['estimated_children'];
                $members = $membersByGroup[$group['id']] ?? [];
                $group['member_count'] = count($members);
                $group['semaphore_color'] = familyGroupComplexity($group, $members);
            }
            unset($group);
        }
        respond(['data' => $groups]);
    }

    if ($path === '/family-groups' && $method === 'POST') {
        $group = validatedFamilyGroup(requestData());
        $insert = $db->prepare(
            'INSERT INTO family_groups
                (user_id, family_label, responsible_name, responsible_email, responsible_phone,
                 estimated_adults, estimated_children, notes)
             VALUES (:user, :label, :name, :email, :phone, :adults, :children, :notes)'
        );
        $insert->execute([
            'user' => $user['id'],
            'label' => $group['family_label'],
            'name' => $group['responsible_name'],
            'email' => $group['responsible_email'],
            'phone' => $group['responsible_phone'],
            'adults' => $group['estimated_adults'],
            'children' => $group['estimated_children'],
            'notes' => $group['notes'],
        ]);
        respond(['data' => ['id' => (int)$db->lastInsertId()]], 201);
    }

    if (preg_match('#^/family-groups/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $groupId = (int)$matches[1];
        $current = familyGroupForUser($db, $user, $groupId, 'update');
        $group = validatedFamilyGroup(requestData(), $current);
        $update = $db->prepare(
            'UPDATE family_groups SET family_label = :label, responsible_name = :name,
                responsible_email = :email, responsible_phone = :phone,
                estimated_adults = :adults, estimated_children = :children, notes = :notes
             WHERE id = :id'
        );
        $update->execute([
            'label' => $group['family_label'],
            'name' => $group['responsible_name'],
            'email' => $group['responsible_email'],
            'phone' => $group['responsible_phone'],
            'adults' => $group['estimated_adults'],
            'children' => $group['estimated_children'],
            'notes' => $group['notes'],
            'id' => $groupId,
        ]);
        respond(['data' => ['id' => $groupId]]);
    }

    if (preg_match('#^/family-groups/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        $groupId = (int)$matches[1];
        familyGroupForUser($db, $user, $groupId, 'delete');
        $db->prepare('DELETE FROM family_groups WHERE id = :id')->execute(['id' => $groupId]);
        respond(['message' => 'Familia eliminada; las invitaciones enviadas conservan sus datos históricos.']);
    }

    if (preg_match('#^/family-groups/(\d+)/members$#', $path, $matches) && $method === 'GET') {
        $groupId = (int)$matches[1];
        familyGroupForUser($db, $user, $groupId);
        respond(['data' => familyGroupMembers($db, $groupId)]);
    }

    if (preg_match('#^/family-groups/(\d+)/members$#', $path, $matches) && $method === 'POST') {
        $groupId = (int)$matches[1];
        familyGroupForUser($db, $user, $groupId, 'update');
        $member = cleanMember(requestData());
        $insert = $db->prepare(
            'INSERT INTO family_group_members (family_group_id, name, age, type, dietary_restrictions, notes)
             VALUES (:group, :name, :age, :type, :diet, :notes)'
        );
        $insert->execute([
            'group' => $groupId,
            'name' => $member['name'],
            'age' => $member['age'],
            'type' => $member['type'],
            'diet' => $member['dietary_restrictions'] ?: null,
            'notes' => $member['notes'] ?: null,
        ]);
        respond(['data' => ['id' => (int)$db->lastInsertId()]], 201);
    }

    if (preg_match('#^/family-group-members/(\d+)$#', $path, $matches) && ($method === 'PATCH' || $method === 'DELETE')) {
        $memberId = (int)$matches[1];
        $query = $db->prepare('SELECT family_group_id FROM family_group_members WHERE id = :id');
        $query->execute(['id' => $memberId]);
        $groupId = $query->fetchColumn();
        if (!$groupId) {
            respond(['error' => 'Integrante no encontrado.'], 404);
        }
        familyGroupForUser($db, $user, (int)$groupId, 'update');
        if ($method === 'DELETE') {
            $db->prepare('DELETE FROM family_group_members WHERE id = :id')->execute(['id' => $memberId]);
            respond(['message' => 'Integrante eliminado.']);
        }
        $member = cleanMember(requestData());
        $update = $db->prepare(
            'UPDATE family_group_members SET name = :name, age = :age, type = :type,
                dietary_restrictions = :diet, notes = :notes WHERE id = :id'
        );
        $update->execute([
            'name' => $member['name'],
            'age' => $member['age'],
            'type' => $member['type'],
            'diet' => $member['dietary_restrictions'] ?: null,
            'notes' => $member['notes'] ?: null,
            'id' => $memberId,
        ]);
        respond(['data' => ['id' => $memberId]]);
    }

    if (preg_match('#^/events/(\d+)/family-invites$#', $path, $matches) && $method === 'GET') {
        $eventId = (int)$matches[1];
        requireEventAccess($db, $user, $eventId);
        $query = $db->prepare(
            'SELECT id, event_id, family_group_id, family_label, responsible_name, responsible_email,
                    responsible_phone, estimated_adults, estimated_children, actual_adults, actual_children,
                    status, responded_at, created_at
             FROM event_family_invites WHERE event_id = :event_id AND revoked_at IS NULL
             ORDER BY family_label, id'
        );
        $query->execute(['event_id' => $eventId]);
        $invites = $query->fetchAll();
        foreach ($invites as &$invite) {
            $invite['id'] = (int)$invite['id'];
            $invite['event_id'] = (int)$invite['event_id'];
            $invite['family_group_id'] = $invite['family_group_id'] === null ? null : (int)$invite['family_group_id'];
            $invite['estimated_adults'] = (int)$invite['estimated_adults'];
            $invite['estimated_children'] = (int)$invite['estimated_children'];
            $invite['actual_adults'] = $invite['actual_adults'] === null ? null : (int)$invite['actual_adults'];
            $invite['actual_children'] = $invite['actual_children'] === null ? null : (int)$invite['actual_children'];
        }
        unset($invite);
        respond(['data' => $invites]);
    }

    if (preg_match('#^/events/(\d+)/family-invites$#', $path, $matches) && $method === 'POST') {
        $eventId = (int)$matches[1];
        $event = requireEventAccess($db, $user, $eventId, 'invite');
        $data = requestData();
        $groupIds = $data['family_group_ids'] ?? [];
        if (!is_array($groupIds) || count($groupIds) < 1 || count($groupIds) > 500) {
            throw new InvalidArgumentException('Selecciona entre 1 y 500 familias.');
        }
        $normalizedIds = [];
        foreach ($groupIds as $rawId) {
            $groupId = filter_var($rawId, FILTER_VALIDATE_INT);
            if ($groupId === false || $groupId < 1) {
                throw new InvalidArgumentException('La selección de familias no es válida.');
            }
            $normalizedIds[] = $groupId;
        }
        $groupIds = array_values(array_unique($normalizedIds));
        if (count($groupIds) !== count($normalizedIds)) {
            throw new InvalidArgumentException('La selección de familias no es válida.');
        }
        $baseUrl = appBaseUrl();
        $existingQuery = $db->prepare('SELECT id FROM event_family_invites WHERE event_id = :event AND family_group_id = :group');
        $insert = $db->prepare(
            "INSERT INTO event_family_invites
                (event_id, family_group_id, family_label, responsible_name, responsible_email, responsible_phone,
                 estimated_adults, estimated_children, status, response_token_hash, response_expires_at)
             VALUES (:event, :group, :label, :name, :email, :phone, :adults, :children, 'pending', :token_hash, :expires)"
        );
        $created = [];
        $expiresAt = $event['event_date']
            ? date('Y-m-d 23:59:59', strtotime($event['event_date'] . ' +7 days'))
            : date('Y-m-d H:i:s', time() + 90 * 86400);

        $db->beginTransaction();
        try {
            foreach ($groupIds as $groupId) {
                $group = familyGroupForUser($db, $user, $groupId, 'invite');
                if ((int)$group['user_id'] !== (int)$event['owner_user_id'] && $user['role'] !== 'admin') {
                    throw new InvalidArgumentException('Solo puedes invitar familias de tu roster.');
                }
                $existingQuery->execute(['event' => $eventId, 'group' => $groupId]);
                if ($existingQuery->fetchColumn()) {
                    throw new InvalidArgumentException('Una o más familias ya están invitadas a este evento.');
                }
                $rawToken = bin2hex(random_bytes(32));
                $insert->execute([
                    'event' => $eventId,
                    'group' => $groupId,
                    'label' => $group['family_label'],
                    'name' => $group['responsible_name'],
                    'email' => $group['responsible_email'],
                    'phone' => $group['responsible_phone'],
                    'adults' => $group['estimated_adults'],
                    'children' => $group['estimated_children'],
                    'token_hash' => hash('sha256', $rawToken),
                    'expires' => $expiresAt,
                ]);
                $created[] = [
                    'id' => (int)$db->lastInsertId(),
                    'family_label' => $group['family_label'],
                    'responsible_name' => $group['responsible_name'],
                    'responsible_email' => $group['responsible_email'],
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
            if ($invite['responsible_email']) {
                $name = htmlspecialchars($invite['responsible_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $label = htmlspecialchars($invite['family_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $url = htmlspecialchars($invite['rsvp_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $title = htmlspecialchars($event['event_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $invite['email_sent'] = sendAccountEmail(
                    $invite['responsible_email'],
                    'Invitación: ' . $event['event_name'],
                    '<p>Hola ' . $name . ':</p><p>La familia ' . $label . ' está invitada a ' . $title . '.</p><p><a href="' . $url . '">Responder invitación</a></p>'
                );
            }
        }
        unset($invite);
        respond(['data' => $created], 201);
    }

    if (preg_match('#^/family-invites/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $inviteId = (int)$matches[1];
        $query = $db->prepare('SELECT event_id, estimated_adults, estimated_children FROM event_family_invites WHERE id = :id AND revoked_at IS NULL');
        $query->execute(['id' => $inviteId]);
        $invite = $query->fetch();
        if (!$invite) {
            respond(['error' => 'Invitación no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$invite['event_id'], 'update_family_invite');
        $data = requestData();
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['pending', 'accepted', 'declined'], true)) {
            throw new InvalidArgumentException('status debe ser pending, accepted o declined.');
        }
        $actualAdults = filter_var($data['actual_adults'] ?? $invite['estimated_adults'], FILTER_VALIDATE_INT);
        $actualChildren = filter_var($data['actual_children'] ?? $invite['estimated_children'], FILTER_VALIDATE_INT);
        if ($actualAdults === false || $actualAdults < 0 || $actualAdults > 1000
            || $actualChildren === false || $actualChildren < 0 || $actualChildren > 1000) {
            throw new InvalidArgumentException('Los conteos reales deben ser números entre 0 y 1000.');
        }
        $update = $db->prepare(
            'UPDATE event_family_invites
             SET status = :status, actual_adults = :adults, actual_children = :children
             WHERE id = :id'
        );
        $update->execute([
            'status' => $status,
            'adults' => $actualAdults,
            'children' => $actualChildren,
            'id' => $inviteId,
        ]);
        respond(['data' => ['id' => $inviteId, 'status' => $status, 'actual_adults' => $actualAdults, 'actual_children' => $actualChildren]]);
    }

    if (preg_match('#^/family-invites/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        $inviteId = (int)$matches[1];
        $query = $db->prepare('SELECT event_id FROM event_family_invites WHERE id = :id AND revoked_at IS NULL');
        $query->execute(['id' => $inviteId]);
        $eventId = $query->fetchColumn();
        if (!$eventId) {
            respond(['error' => 'Invitación no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$eventId, 'revoke_family_invite');
        $db->prepare('UPDATE event_family_invites SET revoked_at = CURRENT_TIMESTAMP, response_token_hash = NULL WHERE id = :id')
            ->execute(['id' => $inviteId]);
        respond(['message' => 'Invitación revocada.']);
    }

    if (preg_match('#^/family-invites/(\d+)/link$#', $path, $matches) && $method === 'POST') {
        $inviteId = (int)$matches[1];
        $query = $db->prepare(
            'SELECT i.event_id, e.event_date
             FROM event_family_invites i JOIN events e ON e.id = i.event_id
             WHERE i.id = :id AND i.revoked_at IS NULL LIMIT 1'
        );
        $query->execute(['id' => $inviteId]);
        $invite = $query->fetch();
        if (!$invite) {
            respond(['error' => 'Invitación no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$invite['event_id'], 'rotate_rsvp_link');
        $token = bin2hex(random_bytes(32));
        $expiresAt = $invite['event_date']
            ? date('Y-m-d 23:59:59', strtotime($invite['event_date'] . ' +7 days'))
            : date('Y-m-d H:i:s', time() + 90 * 86400);
        $update = $db->prepare(
            'UPDATE event_family_invites SET response_token_hash = :hash, response_expires_at = :expires
             WHERE id = :id AND revoked_at IS NULL'
        );
        $update->execute([
            'hash' => hash('sha256', $token),
            'expires' => $expiresAt,
            'id' => $inviteId,
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
