<?php
declare(strict_types=1);

function securityOriginAllowed(bool $requireOrigin = false): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return !$requireOrigin;
    }

    $allowed = array_filter(array_map('trim', explode(',', envValue('APP_ALLOWED_ORIGINS', '') ?? '')));
    $baseUrl = envValue('APP_BASE_URL');
    if ($baseUrl) {
        $allowed[] = rtrim($baseUrl, '/');
    }
    if (!$allowed && isset($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $allowed[] = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    if (in_array(rtrim($origin, '/'), $allowed, true)) {
        return true;
    }

    $originParts = parse_url($origin);
    $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
    $requestHost = strtolower(preg_replace('/:\d+$/', '', $hostHeader));
    $originHost = strtolower((string)($originParts['host'] ?? ''));
    return in_array($requestHost, ['localhost', '127.0.0.1'], true)
        && $requestHost === $originHost
        && ($originParts['scheme'] ?? '') === 'http';
}

function requireSameOrigin(bool $requireOrigin = false): void
{
    if (!securityOriginAllowed($requireOrigin)) {
        respond(['error' => 'Origen no permitido.'], 403);
    }
}

function sessionCookieOptions(int $expires): array
{
    $secure = filter_var(envValue('APP_COOKIE_SECURE', 'auto'), FILTER_VALIDATE_BOOLEAN);
    if (envValue('APP_COOKIE_SECURE', 'auto') === 'auto') {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function issueUserSession(PDO $db, array $user): string
{
    $rawToken = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));
    $expiresAt = new DateTimeImmutable('+7 days');
    $insert = $db->prepare(
        'INSERT INTO sessions (user_id, token_hash, csrf_token_hash, expires_at)
         VALUES (:user_id, :token_hash, :csrf_hash, :expires_at)'
    );
    $insert->execute([
        'user_id' => $user['id'],
        'token_hash' => hash('sha256', $rawToken),
        'csrf_hash' => hash('sha256', $csrfToken),
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);
    setcookie('te_session', $rawToken, sessionCookieOptions($expiresAt->getTimestamp()));
    return $csrfToken;
}

function currentUserSession(PDO $db): array
{
    $rawToken = $_COOKIE['te_session'] ?? '';
    if (!is_string($rawToken) || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        respond(['error' => 'Inicia sesión para continuar.'], 401);
    }

    $query = $db->prepare(
        "SELECT s.id AS session_id, s.csrf_token_hash, s.expires_at,
                u.id, u.display_name, u.email, u.role, u.status
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = :token_hash
           AND s.expires_at > CURRENT_TIMESTAMP
           AND u.status = 'active'
         LIMIT 1"
    );
    $query->execute(['token_hash' => hash('sha256', $rawToken)]);
    $session = $query->fetch();
    if (!$session) {
        setcookie('te_session', '', sessionCookieOptions(time() - 3600));
        respond(['error' => 'La sesión expiró. Inicia sesión nuevamente.'], 401);
    }

    $touch = $db->prepare('UPDATE sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id');
    $touch->execute(['id' => $session['session_id']]);
    $session['user'] = [
        'id' => (int)$session['id'],
        'display_name' => $session['display_name'],
        'email' => $session['email'],
        'role' => $session['role'],
    ];
    $session['session_id'] = (int)$session['session_id'];
    $session['id'] = (int)$session['id'];
    return $session;
}

function requireSessionCsrf(array $session): void
{
    if (!securityOriginAllowed()) {
        respond(['error' => 'Origen no permitido.'], 403);
    }
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || strlen($provided) !== 64 || !hash_equals($session['csrf_token_hash'], hash('sha256', $provided))) {
        respond(['error' => 'Token de seguridad inválido. Actualiza la página e inténtalo nuevamente.'], 403);
    }
}

function rotateCsrfToken(PDO $db, array $session): string
{
    $token = bin2hex(random_bytes(32));
    $update = $db->prepare('UPDATE sessions SET csrf_token_hash = :hash WHERE id = :id');
    $update->execute(['hash' => hash('sha256', $token), 'id' => $session['session_id']]);
    return $token;
}

function auditAdminAction(PDO $db, array $user, string $action, string $targetType, ?int $targetId, array $metadata = []): void
{
    if (($user['role'] ?? '') !== 'admin') {
        return;
    }
    $insert = $db->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, metadata, ip_hash)
         VALUES (:actor, :action, :target_type, :target_id, :metadata, :ip_hash)'
    );
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $insert->execute([
        'actor' => $user['id'],
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        'ip_hash' => $ip !== '' ? hash('sha256', $ip) : null,
    ]);
}

function requireEventAccess(PDO $db, array $user, int $eventId, string $action = 'read'): array
{
    $query = $db->prepare(
        'SELECT id, owner_user_id, event_name, event_date, event_time, theme,
                venue_name, venue_address, venue_capacity, venue_facilities, host_notes
         FROM events WHERE id = :id LIMIT 1'
    );
    $query->execute(['id' => $eventId]);
    $event = $query->fetch();
    if (!$event || ((int)$event['owner_user_id'] !== (int)$user['id'] && $user['role'] !== 'admin')) {
        respond(['error' => 'Evento no encontrado.'], 404);
    }
    $event['id'] = (int)$event['id'];
    $event['owner_user_id'] = (int)$event['owner_user_id'];
    if ($event['owner_user_id'] !== (int)$user['id']) {
        auditAdminAction($db, $user, 'cross_tenant_event_' . $action, 'event', $eventId, ['owner_user_id' => $event['owner_user_id']]);
    }
    return $event;
}

function authRateLimitKey(string $identity): string
{
    $secret = envValue('DB_PASSWORD', 'local-rate-limit-secret') ?? 'local-rate-limit-secret';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash_hmac('sha256', strtolower($identity) . "\0" . $ip, $secret);
}

function enforceAuthRateLimit(PDO $db, string $key): void
{
    $query = $db->prepare('SELECT attempts, window_started_at, blocked_until FROM auth_rate_limits WHERE identifier_hash = :id');
    $query->execute(['id' => $key]);
    $row = $query->fetch();
    if ($row && $row['blocked_until'] !== null && strtotime($row['blocked_until']) > time()) {
        respond(['error' => 'Demasiados intentos. Espera unos minutos antes de volver a intentar.'], 429);
    }
}

function recordAuthFailure(PDO $db, string $key): void
{
    $query = $db->prepare('SELECT attempts, window_started_at FROM auth_rate_limits WHERE identifier_hash = :id');
    $query->execute(['id' => $key]);
    $row = $query->fetch();
    if (!$row) {
        $insert = $db->prepare(
            'INSERT IGNORE INTO auth_rate_limits (identifier_hash, window_started_at, attempts) VALUES (:id, CURRENT_TIMESTAMP, 1)'
        );
        $insert->execute(['id' => $key]);
        return;
    }

    if (strtotime($row['window_started_at']) < time() - 900) {
        $update = $db->prepare(
            'UPDATE auth_rate_limits SET window_started_at = CURRENT_TIMESTAMP, attempts = 1, blocked_until = NULL WHERE identifier_hash = :id'
        );
        $update->execute(['id' => $key]);
        return;
    }

    $attempts = (int)$row['attempts'] + 1;
    $blockedUntil = $attempts >= 6 ? date('Y-m-d H:i:s', time() + 900) : null;
    $update = $db->prepare(
        'UPDATE auth_rate_limits SET attempts = :attempts, blocked_until = :blocked WHERE identifier_hash = :id'
    );
    $update->execute(['attempts' => $attempts, 'blocked' => $blockedUntil, 'id' => $key]);
}

function clearAuthFailures(PDO $db, string $key): void
{
    $delete = $db->prepare('DELETE FROM auth_rate_limits WHERE identifier_hash = :id');
    $delete->execute(['id' => $key]);
}

function createAccountToken(PDO $db, int $userId, string $purpose, int $lifetimeSeconds): string
{
    $token = bin2hex(random_bytes(32));
    $db->prepare('UPDATE account_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE user_id = :user AND purpose = :purpose AND consumed_at IS NULL')
        ->execute(['user' => $userId, 'purpose' => $purpose]);
    $insert = $db->prepare(
        'INSERT INTO account_tokens (user_id, purpose, token_hash, expires_at)
         VALUES (:user, :purpose, :hash, :expires)'
    );
    $insert->execute([
        'user' => $userId,
        'purpose' => $purpose,
        'hash' => hash('sha256', $token),
        'expires' => date('Y-m-d H:i:s', time() + $lifetimeSeconds),
    ]);
    return $token;
}

function appBaseUrl(): string
{
    $baseUrl = rtrim((string)envValue('APP_BASE_URL', ''), '/');
    if ($baseUrl === '') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $originParts = parse_url($origin);
        if (is_array($originParts)
            && ($originParts['scheme'] ?? '') === 'http'
            && in_array($originParts['host'] ?? '', ['localhost', '127.0.0.1'], true)) {
            return rtrim($origin, '/');
        }
    }
    $parts = $baseUrl !== '' ? parse_url($baseUrl) : false;
    $localHttp = is_array($parts)
        && ($parts['scheme'] ?? '') === 'http'
        && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || (($parts['scheme'] ?? '') !== 'https' && !$localHttp)) {
        throw new RuntimeException('Set a valid HTTPS APP_BASE_URL (HTTP is allowed only for localhost development).');
    }
    return $baseUrl;
}

function sendAccountEmail(string $recipient, string $subject, string $body): bool
{
    $from = trim((string)envValue('APP_MAIL_FROM', ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        return false;
    }
    $headers = [
        'From: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: TuEvento',
    ];
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return mail($recipient, $encodedSubject, $body, implode("\r\n", $headers));
}

function sendAccountTokenEmail(string $email, string $displayName, string $token, string $purpose): bool
{
    $base = appBaseUrl();
    if ($purpose === 'verify_email') {
        $url = $base . '/#verify=' . rawurlencode($token);
        $subject = 'Confirma tu correo';
        $action = 'Confirmar correo';
        $message = 'Confirma tu correo para activar tu cuenta.';
    } else {
        $url = $base . '/#reset=' . rawurlencode($token);
        $subject = 'Restablece tu contraseña';
        $action = 'Cambiar contraseña';
        $message = 'Solicitaste cambiar la contraseña de tu cuenta.';
    }
    $name = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $link = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<p>Hola ' . $name . ':</p><p>' . $message . '</p><p><a href="' . $link . '">' . $action . '</a></p><p>Este enlace vence en 60 minutos. Si no solicitaste esta acción, ignora este mensaje.</p>';
    return sendAccountEmail($email, $subject, $html);
}

function publicAuthRoutes(PDO $db, string $path, string $method): bool
{
    if (str_starts_with($path, '/auth/')) {
        requireSameOrigin($method !== 'GET');
    }

    if ($path === '/auth/register' && $method === 'POST') {
        $data = requestData();
        $name = trim((string)($data['display_name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $rateKey = authRateLimitKey($email ?: ($_SERVER['REMOTE_ADDR'] ?? 'register'));
        enforceAuthRateLimit($db, $rateKey);
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 200) {
            recordAuthFailure($db, $rateKey);
            respond(['error' => 'Revisa el nombre, correo y contraseña (mínimo 12 caracteres).'], 422);
        }

        $existing = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existing->execute(['email' => $email]);
        if ($existing->fetchColumn()) {
            clearAuthFailures($db, $rateKey);
            respond(['message' => 'Si el correo puede registrarse, recibirás un mensaje de verificación.'], 202);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        unset($password);
        $insert = $db->prepare(
            "INSERT INTO users (display_name, email, password_hash, role, status)
             VALUES (:name, :email, :hash, 'user', 'pending')"
        );
        $insert->execute(['name' => $name, 'email' => $email, 'hash' => $hash]);
        $userId = (int)$db->lastInsertId();
        $token = createAccountToken($db, $userId, 'verify_email', 3600);
        try {
            $sent = sendAccountTokenEmail($email, $name, $token, 'verify_email');
        } catch (Throwable $error) {
            $sent = false;
            error_log('Registration email configuration failed for user id ' . $userId . '.');
        }
        unset($token, $hash);
        if (!$sent) {
            error_log('Registration email delivery failed for user id ' . $userId . '.');
            respond(['error' => 'No se pudo enviar la verificación. Configura el correo del servidor e inténtalo de nuevo.'], 503);
        }
        clearAuthFailures($db, $rateKey);
        respond(['message' => 'Revisa tu correo para verificar la cuenta.'], 202);
    }

    if ($path === '/auth/verify' && $method === 'POST') {
        $rateKey = authRateLimitKey('verify-email');
        enforceAuthRateLimit($db, $rateKey);
        $data = requestData();
        $token = (string)($data['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            recordAuthFailure($db, $rateKey);
            respond(['error' => 'El enlace de verificación no es válido o expiró.'], 422);
        }
        $db->beginTransaction();
        $query = $db->prepare(
            "SELECT id, user_id FROM account_tokens
             WHERE token_hash = :hash AND purpose = 'verify_email'
               AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE"
        );
        $query->execute(['hash' => hash('sha256', $token)]);
        $accountToken = $query->fetch();
        unset($token);
        if (!$accountToken) {
            $db->rollBack();
            recordAuthFailure($db, $rateKey);
            respond(['error' => 'El enlace de verificación no es válido o expiró.'], 422);
        }
        $db->prepare("UPDATE users SET status = 'active', email_verified_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'pending'")
            ->execute(['id' => $accountToken['user_id']]);
        $db->prepare('UPDATE account_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $accountToken['id']]);
        $db->commit();
        clearAuthFailures($db, $rateKey);
        respond(['message' => 'Correo verificado. Ya puedes iniciar sesión.']);
    }

    if ($path === '/auth/login' && $method === 'POST') {
        $data = requestData();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $rateKey = authRateLimitKey($email ?: ($_SERVER['REMOTE_ADDR'] ?? 'login'));
        enforceAuthRateLimit($db, $rateKey);
        $query = $db->prepare(
            'SELECT id, display_name, email, password_hash, role, status FROM users WHERE email = :email LIMIT 1'
        );
        $query->execute(['email' => $email]);
        $user = $query->fetch();
        $valid = $user && password_verify($password, $user['password_hash']);
        if (!$valid || $user['status'] !== 'active') {
            unset($password, $data['password']);
            recordAuthFailure($db, $rateKey);
            respond(['error' => 'Correo o contraseña incorrectos, o cuenta sin verificar.'], 401);
        }
        clearAuthFailures($db, $rateKey);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }
        unset($password, $data['password']);
        $csrf = issueUserSession($db, $user);
        unset($user['password_hash']);
        $user['id'] = (int)$user['id'];
        respond(['data' => ['user' => $user, 'csrf_token' => $csrf]]);
    }

    if ($path === '/auth/me' && $method === 'GET') {
        $session = currentUserSession($db);
        $csrf = rotateCsrfToken($db, $session);
        respond(['data' => ['user' => $session['user'], 'csrf_token' => $csrf]]);
    }

    if ($path === '/auth/logout' && $method === 'POST') {
        $session = currentUserSession($db);
        requireSessionCsrf($session);
        $db->prepare('DELETE FROM sessions WHERE id = :id')->execute(['id' => $session['session_id']]);
        setcookie('te_session', '', sessionCookieOptions(time() - 3600));
        respond(['message' => 'Sesión cerrada.']);
    }

    if ($path === '/auth/resend-verification' && $method === 'POST') {
        $data = requestData();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $rateKey = authRateLimitKey($email ?: ($_SERVER['REMOTE_ADDR'] ?? 'verify'));
        enforceAuthRateLimit($db, $rateKey);
        recordAuthFailure($db, $rateKey);
        $query = $db->prepare("SELECT id, display_name, email FROM users WHERE email = :email AND status = 'pending' LIMIT 1");
        $query->execute(['email' => $email]);
        $user = $query->fetch();
        if ($user) {
            $token = createAccountToken($db, (int)$user['id'], 'verify_email', 3600);
            try {
                sendAccountTokenEmail($user['email'], $user['display_name'], $token, 'verify_email');
            } catch (Throwable $error) {
                error_log('Verification email delivery failed for user id ' . (int)$user['id'] . '.');
            }
            unset($token);
        }
        respond(['message' => 'Si la cuenta está pendiente, recibirás otro correo de verificación.'], 202);
    }

    if ($path === '/auth/forgot-password' && $method === 'POST') {
        $data = requestData();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $rateKey = authRateLimitKey($email ?: ($_SERVER['REMOTE_ADDR'] ?? 'reset'));
        enforceAuthRateLimit($db, $rateKey);
        recordAuthFailure($db, $rateKey);
        $query = $db->prepare("SELECT id, display_name, email FROM users WHERE email = :email AND status = 'active' LIMIT 1");
        $query->execute(['email' => $email]);
        $user = $query->fetch();
        if ($user) {
            $token = createAccountToken($db, (int)$user['id'], 'reset_password', 3600);
            try {
                sendAccountTokenEmail($user['email'], $user['display_name'], $token, 'reset_password');
            } catch (Throwable $error) {
                error_log('Password reset email delivery failed for user id ' . (int)$user['id'] . '.');
            }
            unset($token);
        }
        respond(['message' => 'Si la cuenta existe, recibirás instrucciones para restablecer la contraseña.'], 202);
    }

    if ($path === '/auth/reset-password' && $method === 'POST') {
        $rateKey = authRateLimitKey('reset-password');
        enforceAuthRateLimit($db, $rateKey);
        recordAuthFailure($db, $rateKey);
        $data = requestData();
        $token = (string)($data['token'] ?? '');
        $password = (string)($data['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 12 || strlen($password) > 200) {
            unset($password);
            respond(['error' => 'El enlace no es válido o la contraseña no cumple el mínimo de 12 caracteres.'], 422);
        }
        $db->beginTransaction();
        $query = $db->prepare(
            "SELECT id, user_id FROM account_tokens
             WHERE token_hash = :hash AND purpose = 'reset_password'
               AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE"
        );
        $query->execute(['hash' => hash('sha256', $token)]);
        $accountToken = $query->fetch();
        unset($token);
        if (!$accountToken) {
            $db->rollBack();
            unset($password);
            respond(['error' => 'El enlace no es válido o expiró.'], 422);
        }
        $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $accountToken['user_id']]);
        unset($password);
        $db->prepare('UPDATE account_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $accountToken['id']]);
        $db->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $accountToken['user_id']]);
        $db->commit();
        clearAuthFailures($db, $rateKey);
        respond(['message' => 'Contraseña actualizada. Inicia sesión nuevamente.']);
    }

    if ($path === '/rsvp/lookup' && $method === 'POST') {
        requireSameOrigin(true);
        $data = requestData();
        $token = (string)($data['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            respond(['error' => 'Esta invitación no existe o expiró.'], 404);
        }
        $query = $db->prepare(
            'SELECT i.family_label, i.responsible_name, i.estimated_adults, i.estimated_children,
                    i.actual_adults, i.actual_children, i.status, i.response_expires_at,
                    e.event_name, e.event_date, e.event_time, e.theme, e.venue_name, e.venue_address
             FROM event_family_invites i JOIN events e ON e.id = i.event_id
             WHERE i.response_token_hash = :hash AND i.revoked_at IS NULL
               AND i.response_expires_at > CURRENT_TIMESTAMP LIMIT 1'
        );
        $query->execute(['hash' => hash('sha256', $token)]);
        unset($token);
        $invite = $query->fetch();
        if (!$invite) {
            respond(['error' => 'Esta invitación no existe o expiró.'], 404);
        }
        $invite['estimated_adults'] = (int)$invite['estimated_adults'];
        $invite['estimated_children'] = (int)$invite['estimated_children'];
        $invite['actual_adults'] = $invite['actual_adults'] === null ? null : (int)$invite['actual_adults'];
        $invite['actual_children'] = $invite['actual_children'] === null ? null : (int)$invite['actual_children'];
        respond(['data' => $invite]);
    }

    if ($path === '/rsvp/respond' && $method === 'POST') {
        requireSameOrigin(true);
        $data = requestData();
        $token = (string)($data['token'] ?? '');
        $status = (string)($data['status'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            respond(['error' => 'Esta invitación no existe o expiró.'], 404);
        }
        if (!in_array($status, ['accepted', 'declined'], true)) {
            respond(['error' => 'La respuesta debe ser accepted o declined.'], 422);
        }
        $rateKey = authRateLimitKey(hash('sha256', $token));
        enforceAuthRateLimit($db, $rateKey);
        recordAuthFailure($db, $rateKey);
        $validToken = $db->prepare(
            'SELECT id, estimated_adults, estimated_children FROM event_family_invites
             WHERE response_token_hash = :hash AND revoked_at IS NULL
               AND response_expires_at > CURRENT_TIMESTAMP LIMIT 1'
        );
        $validToken->execute(['hash' => hash('sha256', $token)]);
        $invite = $validToken->fetch();
        unset($token);
        if (!$invite) {
            respond(['error' => 'Esta invitación no existe o expiró.'], 404);
        }
        // The family's point of contact may adjust the real headcount when
        // responding (e.g. "actually only 3 of us are coming"); default to the
        // estimate the organizer already has if they don't provide one.
        $actualAdults = $status === 'accepted'
            ? filter_var($data['actual_adults'] ?? $invite['estimated_adults'], FILTER_VALIDATE_INT)
            : 0;
        $actualChildren = $status === 'accepted'
            ? filter_var($data['actual_children'] ?? $invite['estimated_children'], FILTER_VALIDATE_INT)
            : 0;
        if ($actualAdults === false || $actualAdults < 0 || $actualAdults > 1000
            || $actualChildren === false || $actualChildren < 0 || $actualChildren > 1000) {
            respond(['error' => 'Los conteos reales deben ser números entre 0 y 1000.'], 422);
        }
        $update = $db->prepare(
            'UPDATE event_family_invites
             SET status = :status, actual_adults = :adults, actual_children = :children, responded_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->execute([
            'status' => $status,
            'adults' => $actualAdults,
            'children' => $actualChildren,
            'id' => $invite['id'],
        ]);
        respond(['message' => 'Respuesta guardada.']);
    }

    return false;
}
