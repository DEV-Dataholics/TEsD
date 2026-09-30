<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/domain.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('El cuerpo de la solicitud no es JSON válido.');
    }
    return $data;
}

function cleanMember($member): array
{
    if (!is_array($member)) {
        throw new InvalidArgumentException('Cada integrante debe ser un objeto válido.');
    }

    $name = trim((string)($member['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) {
        throw new InvalidArgumentException('El nombre de cada integrante es obligatorio y debe tener máximo 160 caracteres.');
    }

    $type = (string)($member['type'] ?? 'adult');
    if (!in_array($type, ['adult', 'child'], true)) {
        throw new InvalidArgumentException('El tipo de integrante debe ser adult o child.');
    }

    $ageValue = $member['age'] ?? null;
    $age = ($ageValue === '' || $ageValue === null) ? null : filter_var($ageValue, FILTER_VALIDATE_INT);
    if ($ageValue !== '' && $ageValue !== null && ($age === false || $age < 0 || $age > 120)) {
        throw new InvalidArgumentException('La edad debe estar entre 0 y 120 años.');
    }

    return [
        'name' => $name,
        'age' => $age,
        'type' => $type,
        'dietary_restrictions' => trim((string)($member['dietary_restrictions'] ?? '')),
        'notes' => trim((string)($member['notes'] ?? '')),
    ];
}

function complexityColor(array $members): string
{
    $signals = 0;
    foreach ($members as $member) {
        $restrictions = trim((string)($member['dietary_restrictions'] ?? ''));
        $notes = trim((string)($member['notes'] ?? ''));
        if ($restrictions !== '') {
            $items = preg_split('/[,;\r\n]+/', $restrictions, -1, PREG_SPLIT_NO_EMPTY);
            $signals += max(1, count($items ?: []));
        }
        if ($notes !== '') {
            $signals++;
        }
    }

    if ($signals >= 4) {
        return 'red';
    }
    return $signals >= 2 ? 'yellow' : 'green';
}

function insertMembers(PDO $db, int $familyId, array $members): void
{
    $statement = $db->prepare(
        'INSERT INTO family_members (family_id, name, age, type, dietary_restrictions, notes)
         VALUES (:family_id, :name, :age, :type, :dietary_restrictions, :notes)'
    );
    foreach ($members as $rawMember) {
        $member = cleanMember($rawMember);
        $statement->execute([
            'family_id' => $familyId,
            'name' => $member['name'],
            'age' => $member['age'],
            'type' => $member['type'],
            'dietary_restrictions' => $member['dietary_restrictions'] ?: null,
            'notes' => $member['notes'] ?: null,
        ]);
    }
}

function refreshFamily(PDO $db, int $familyId): void
{
    $statement = $db->prepare(
        'SELECT dietary_restrictions, notes FROM family_members WHERE family_id = :family_id'
    );
    $statement->execute(['family_id' => $familyId]);
    $members = $statement->fetchAll();
    $update = $db->prepare(
        'UPDATE families SET estimated_count = :count, semaphore_color = :color WHERE id = :id'
    );
    $update->execute([
        'count' => count($members),
        'color' => complexityColor($members),
        'id' => $familyId,
    ]);
}

function createFamily(PDO $db, int $eventId, string $familyName, array $members): int
{
    $familyName = trim($familyName);
    if ($familyName === '' || mb_strlen($familyName) > 160) {
        throw new InvalidArgumentException('El nombre de la familia es obligatorio y debe tener máximo 160 caracteres.');
    }
    if (count($members) < 1 || count($members) > 100) {
        throw new InvalidArgumentException('Una familia debe incluir entre 1 y 100 integrantes.');
    }

    $statement = $db->prepare(
        "INSERT INTO families (event_id, family_name, status, estimated_count, actual_count, semaphore_color)
         VALUES (:event_id, :family_name, 'draft', 0, 0, 'green')"
    );
    $statement->execute(['event_id' => $eventId, 'family_name' => $familyName]);
    $familyId = (int)$db->lastInsertId();
    insertMembers($db, $familyId, $members);
    refreshFamily($db, $familyId);
    return $familyId;
}

function familyList(PDO $db, int $eventId, ?string $status): array
{
    $sql = 'SELECT id, event_id, family_name, status, estimated_count, actual_count, semaphore_color, created_at
            FROM families WHERE event_id = :event_id';
    $params = ['event_id' => $eventId];
    if ($status !== null) {
        if (!in_array($status, ['draft', 'confirmed'], true)) {
            throw new InvalidArgumentException('El filtro status debe ser draft o confirmed.');
        }
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY family_name ASC, id ASC';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $families = $statement->fetchAll();
    if (!$families) {
        return [];
    }

    $ids = array_map(static fn(array $family): int => (int)$family['id'], $families);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $memberQuery = $db->prepare(
        "SELECT id, family_id, name, age, type, dietary_restrictions, notes, created_at
         FROM family_members WHERE family_id IN ({$placeholders}) ORDER BY name ASC"
    );
    $memberQuery->execute($ids);
    $membersByFamily = [];
    foreach ($memberQuery->fetchAll() as $member) {
        $member['id'] = (int)$member['id'];
        $member['family_id'] = (int)$member['family_id'];
        $member['age'] = $member['age'] === null ? null : (int)$member['age'];
        $membersByFamily[$member['family_id']][] = $member;
    }

    foreach ($families as &$family) {
        $family['id'] = (int)$family['id'];
        $family['event_id'] = (int)$family['event_id'];
        $family['estimated_count'] = (int)$family['estimated_count'];
        $family['actual_count'] = (int)$family['actual_count'];
        $family['members'] = $membersByFamily[$family['id']] ?? [];
    }
    unset($family);
    return $families;
}

function storeOcrFamilies(PDO $db, int $eventId, array $families): array
{
    if (count($families) < 1 || count($families) > 100) {
        throw new InvalidArgumentException('No se detectaron familias válidas en la imagen.');
    }

    $db->beginTransaction();
    try {
        $ids = [];
        foreach ($families as $family) {
            if (!is_array($family) || !isset($family['members']) || !is_array($family['members'])) {
                continue;
            }
            $members = array_map('cleanMember', $family['members']);
            $ids[] = createFamily(
                $db,
                $eventId,
                (string)($family['family_name'] ?? ''),
                $members
            );
        }
        if (!$ids) {
            throw new InvalidArgumentException('No se detectaron familias con integrantes válidos.');
        }
        $db->commit();
        return $ids;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function extractFamiliesFromImage(string $imagePath, string $mimeType): array
{
    $apiKey = envValue('OPENAI_API_KEY');
    if ($apiKey === null || $apiKey === '') {
        respond(['error' => 'El OCR no está configurado. Define OPENAI_API_KEY en el servidor.'], 503);
    }
    if (!function_exists('curl_init')) {
        respond(['error' => 'El OCR requiere la extensión cURL habilitada en PHP.'], 503);
    }

    $image = file_get_contents($imagePath);
    if ($image === false) {
        throw new RuntimeException('No se pudo leer la imagen recibida.');
    }

    $memberSchema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => ['integer', 'null']],
            'type' => ['type' => 'string', 'enum' => ['adult', 'child']],
            'dietary_restrictions' => ['type' => ['string', 'null']],
            'notes' => ['type' => ['string', 'null']],
        ],
        'required' => ['name', 'age', 'type', 'dietary_restrictions', 'notes'],
    ];
    $familySchema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'family_name' => ['type' => 'string'],
            'members' => ['type' => 'array', 'items' => $memberSchema],
        ],
        'required' => ['family_name', 'members'],
    ];
    $body = [
        'model' => envValue('OPENAI_MODEL', 'gpt-4.1-mini'),
        'input' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_text',
                    'text' => 'Transcribe la lista manuscrita de invitados. Agrupa por familia cuando sea posible. No inventes edades ni restricciones; usa null si no aparecen. Clasifica como child solo cuando la lista indique claramente que es menor; de lo contrario usa adult. Devuelve únicamente los datos que cumplan el esquema.',
                ],
                [
                    'type' => 'input_image',
                    'image_url' => 'data:' . $mimeType . ';base64,' . base64_encode($image),
                ],
            ],
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'guest_list',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'families' => ['type' => 'array', 'items' => $familySchema],
                    ],
                    'required' => ['families'],
                ],
            ],
        ],
    ];

    $curl = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
        error_log('OpenAI OCR request failed: ' . ($curlError ?: 'HTTP ' . $httpStatus));
        throw new RuntimeException('El servicio OCR no pudo procesar la imagen.');
    }

    $result = json_decode($response, true);
    $outputText = $result['output_text'] ?? null;
    if (!is_string($outputText)) {
        foreach (($result['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $outputText = $content['text'];
                    break 2;
                }
            }
        }
    }
    $parsed = is_string($outputText) ? json_decode($outputText, true) : null;
    if (!is_array($parsed) || !isset($parsed['families']) || !is_array($parsed['families'])) {
        throw new RuntimeException('La respuesta OCR no tuvo el formato esperado.');
    }
    return $parsed['families'];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = preg_replace('#^/api(?=/|$)#', '', $requestPath) ?: '/';
$path = '/' . trim($requestPath, '/');
if ($path === '//') {
    $path = '/';
}

try {
    if ($path === '/health' && $method === 'GET') {
        database()->query('SELECT 1');
        respond(['status' => 'ok', 'database' => 'connected']);
    }

    $db = database();
    
    // Solicitud de Proveedores (Registro público)
    if (($path === '/solicitudes_proveedores/create_solicitud' || $path === '/solicitudes_proveedores') && $method === 'POST') {
        $data = requestData();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $nombre = trim((string)($data['nombre'] ?? ''));
        $tipo = filter_var($data['tipo_proveedor_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $detalles = trim((string)($data['detalles_servicios'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Por favor introduce un correo electrónico válido.'], 422);
        }
        if ($nombre === '') {
            $nombre = explode('@', $email)[0];
        }

        try {
            $db = database();
            $db->exec("CREATE TABLE IF NOT EXISTS solicitudes_proveedores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                tipo_proveedor_id INT NULL,
                detalles_servicios TEXT,
                estado ENUM('pendiente', 'aprobado', 'rechazado') DEFAULT 'pendiente',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");

            $stmt = $db->prepare(
                "INSERT INTO solicitudes_proveedores (nombre, email, tipo_proveedor_id, detalles_servicios)
                 VALUES (:nombre, :email, :tipo, :detalles)"
            );
            $stmt->execute([
                'nombre' => $nombre,
                'email' => $email,
                'tipo' => $tipo,
                'detalles' => $detalles ?: null
            ]);

            respond([
                'status' => 'success',
                'message' => 'Solicitud enviada exitosamente. El administrador revisará tu información.',
                'data' => [
                    'id' => (int)$db->lastInsertId(),
                    'email' => $email
                ]
            ], 201);
        } catch (Throwable $e) {
            error_log('Error en registro de solicitud de proveedor: ' . $e->getMessage());
            respond(['error' => 'No se pudo guardar la solicitud. Verifica los datos.'], 500);
        }
    }

    if (publicAuthRoutes($db, $path, $method)) {
        respond(['error' => 'Ruta no encontrada.'], 404);
    }

    $session = currentUserSession($db);
    $user = $session['user'];
    if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
        requireSessionCsrf($session);
    }
    if (handleDomainRoutes($db, $path, $method, $user)) {
        respond(['error' => 'Ruta no encontrada.'], 404);
    }

    
    if ($path === '/solicitudes_proveedores' && $method === 'GET') {
        if ($user['role'] !== 'admin') {
            respond(['error' => 'Acceso denegado.'], 403);
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS solicitudes_proveedores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                tipo_proveedor_id INT NULL,
                detalles_servicios TEXT,
                estado ENUM('pendiente', 'aprobado', 'rechazado') DEFAULT 'pendiente',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $rows = $db->query("SELECT * FROM solicitudes_proveedores ORDER BY id DESC")->fetchAll();
            respond(['data' => $rows]);
        } catch (Throwable $e) {
            respond(['error' => 'Error al consultar solicitudes.'], 500);
        }
    }

    if (preg_match('#^/solicitudes_proveedores/(\\d+)$#', $path, $matches) && $method === 'PATCH') {
        if ($user['role'] !== 'admin') {
            respond(['error' => 'Acceso denegado.'], 403);
        }
        $solicitudId = (int)$matches[1];
        $data = requestData();
        $nuevoEstado = $data['estado'] ?? 'pendiente';
        if (!in_array($nuevoEstado, ['pendiente', 'aprobado', 'rechazado'], true)) {
            respond(['error' => 'Estado no válido.'], 422);
        }
        $stmt = $db->prepare("UPDATE solicitudes_proveedores SET estado = :estado WHERE id = :id");
        $stmt->execute(['estado' => $nuevoEstado, 'id' => $solicitudId]);
        respond(['message' => 'Estado de solicitud actualizado.', 'id' => $solicitudId, 'estado' => $nuevoEstado]);
    }

    if ($path === '/events' && $method === 'GET') {
        if ($user['role'] === 'admin') {
            auditAdminAction($db, $user, 'list_all_events', 'event', null);
            $events = $db->query(
                'SELECT e.id, e.owner_user_id, e.event_name, e.event_date, u.email AS owner_email
                 FROM events e JOIN users u ON u.id = e.owner_user_id ORDER BY e.event_date, e.id'
            )->fetchAll();
        } else {
            $query = $db->prepare(
                'SELECT id, owner_user_id, event_name, event_date FROM events WHERE owner_user_id = :owner ORDER BY event_date, id'
            );
            $query->execute(['owner' => $user['id']]);
            $events = $query->fetchAll();
        }
        foreach ($events as &$event) {
            $event['id'] = (int)$event['id'];
            $event['owner_user_id'] = (int)$event['owner_user_id'];
        }
        unset($event);
        respond(['data' => $events]);
    }

    if ($path === '/events' && $method === 'POST') {
        $data = requestData();
        $name = trim((string)($data['event_name'] ?? ''));
        $date = trim((string)($data['event_date'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('El nombre del evento es obligatorio y debe tener máximo 160 caracteres.');
        }
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('event_date debe tener formato YYYY-MM-DD.');
        }
        $insert = $db->prepare(
            'INSERT INTO events (owner_user_id, event_name, event_date) VALUES (:owner, :name, :date)'
        );
        $insert->execute(['owner' => $user['id'], 'name' => $name, 'date' => $date !== '' ? $date : null]);
        respond(['data' => ['id' => (int)$db->lastInsertId(), 'event_name' => $name, 'event_date' => $date ?: null]], 201);
    }

    if (preg_match('#^/events/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $eventId = (int)$matches[1];
        $event = requireEventAccess($db, $user, $eventId, 'update');
        $data = requestData();
        $name = trim((string)($data['event_name'] ?? $event['event_name']));
        $date = array_key_exists('event_date', $data) ? trim((string)$data['event_date']) : (string)($event['event_date'] ?? '');
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('El nombre del evento es obligatorio y debe tener máximo 160 caracteres.');
        }
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('event_date debe tener formato YYYY-MM-DD.');
        }
        $update = $db->prepare('UPDATE events SET event_name = :name, event_date = :date WHERE id = :id');
        $update->execute(['name' => $name, 'date' => $date !== '' ? $date : null, 'id' => $eventId]);
        respond(['data' => ['id' => $eventId, 'event_name' => $name, 'event_date' => $date ?: null]]);
    }

    if (preg_match('#^/events/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        $eventId = (int)$matches[1];
        requireEventAccess($db, $user, $eventId, 'delete');
        $db->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        respond(['message' => 'Evento eliminado.']);
    }

    if ($path === '/auth/me' && $method === 'GET') {
        respond(['data' => ['user' => $user, 'csrf_token' => rotateCsrfToken($db, $session)]]);
    }

    if ($path === '/families' && $method === 'GET') {
        $eventId = filter_var($_GET['event_id'] ?? 1, FILTER_VALIDATE_INT);
        if ($eventId === false || $eventId < 1) {
            throw new InvalidArgumentException('event_id debe ser un entero positivo.');
        }
        requireEventAccess($db, $user, $eventId);
        respond(['data' => familyList($db, $eventId, isset($_GET['status']) ? (string)$_GET['status'] : null)]);
    }

    if ($path === '/families' && $method === 'POST') {
        $data = requestData();
        $eventId = filter_var($data['event_id'] ?? 1, FILTER_VALIDATE_INT);
        if ($eventId === false || $eventId < 1) {
            throw new InvalidArgumentException('event_id debe ser un entero positivo.');
        }
        requireEventAccess($db, $user, $eventId, 'create_family');
        $members = $data['members'] ?? [];
        if (!is_array($members)) {
            throw new InvalidArgumentException('members debe ser una lista.');
        }
        $db->beginTransaction();
        try {
            $familyId = createFamily($db, $eventId, (string)($data['family_name'] ?? ''), $members);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        respond(['data' => ['id' => $familyId]], 201);
    }

    if (preg_match('#^/families/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $familyId = (int)$matches[1];
        $data = requestData();
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['draft', 'confirmed'], true)) {
            throw new InvalidArgumentException('status debe ser draft o confirmed.');
        }
        $familyQuery = $db->prepare('SELECT estimated_count, event_id FROM families WHERE id = :id');
        $familyQuery->execute(['id' => $familyId]);
        $family = $familyQuery->fetch();
        if (!$family) {
            respond(['error' => 'Familia no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$family['event_id'], 'update_family');
        $actualCount = filter_var($data['actual_count'] ?? $family['estimated_count'], FILTER_VALIDATE_INT);
        if ($actualCount === false || $actualCount < 0 || $actualCount > 10000) {
            throw new InvalidArgumentException('actual_count debe estar entre 0 y 10000.');
        }
        $update = $db->prepare('UPDATE families SET status = :status, actual_count = :actual_count WHERE id = :id');
        $update->execute(['status' => $status, 'actual_count' => $actualCount, 'id' => $familyId]);
        respond(['data' => ['id' => $familyId, 'status' => $status, 'actual_count' => $actualCount]]);
    }

    if ($path === '/members' && $method === 'GET') {
        $familyId = filter_var($_GET['family_id'] ?? null, FILTER_VALIDATE_INT);
        if ($familyId === false || $familyId === null || $familyId < 1) {
            throw new InvalidArgumentException('family_id es obligatorio y debe ser un entero positivo.');
        }
        $familyEventQuery = $db->prepare('SELECT event_id FROM families WHERE id = :id');
        $familyEventQuery->execute(['id' => $familyId]);
        $familyEventId = $familyEventQuery->fetchColumn();
        if (!$familyEventId) {
            respond(['error' => 'Familia no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$familyEventId);
        $statement = $db->prepare(
            'SELECT id, family_id, name, age, type, dietary_restrictions, notes, created_at
             FROM family_members WHERE family_id = :family_id ORDER BY name ASC'
        );
        $statement->execute(['family_id' => $familyId]);
        respond(['data' => $statement->fetchAll()]);
    }

    if ($path === '/members' && $method === 'POST') {
        $data = requestData();
        $familyId = filter_var($data['family_id'] ?? null, FILTER_VALIDATE_INT);
        if ($familyId === false || $familyId === null || $familyId < 1) {
            throw new InvalidArgumentException('family_id es obligatorio y debe ser un entero positivo.');
        }
        $familyEventQuery = $db->prepare('SELECT event_id FROM families WHERE id = :id');
        $familyEventQuery->execute(['id' => $familyId]);
        $familyEventId = $familyEventQuery->fetchColumn();
        if (!$familyEventId) {
            respond(['error' => 'Familia no encontrada.'], 404);
        }
        requireEventAccess($db, $user, (int)$familyEventId, 'add_member');
        $member = cleanMember($data);
        $statement = $db->prepare(
            'INSERT INTO family_members (family_id, name, age, type, dietary_restrictions, notes)
             VALUES (:family_id, :name, :age, :type, :dietary_restrictions, :notes)'
        );
        $statement->execute([
            'family_id' => $familyId,
            'name' => $member['name'],
            'age' => $member['age'],
            'type' => $member['type'],
            'dietary_restrictions' => $member['dietary_restrictions'] ?: null,
            'notes' => $member['notes'] ?: null,
        ]);
        refreshFamily($db, $familyId);
        respond(['data' => ['id' => (int)$db->lastInsertId(), 'family_id' => $familyId]], 201);
    }

    if ($path === '/upload-ocr' && $method === 'POST') {
        $eventId = filter_var($_POST['event_id'] ?? 1, FILTER_VALIDATE_INT);
        if ($eventId === false || $eventId < 1) {
            throw new InvalidArgumentException('event_id debe ser un entero positivo.');
        }
        requireEventAccess($db, $user, $eventId, 'upload_ocr');
        $upload = $_FILES['image'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Selecciona una imagen válida para procesar.');
        }
        if (($upload['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('La imagen no puede superar 10 MB.');
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('Formato no permitido. Usa JPG, PNG o WebP.');
        }
        $families = extractFamiliesFromImage($upload['tmp_name'], $mimeType);
        $ids = storeOcrFamilies($db, $eventId, $families);
        respond(['data' => ['created' => count($ids), 'family_ids' => $ids]], 201);
    }

    respond(['error' => 'Ruta no encontrada.'], 404);
} catch (InvalidArgumentException $error) {
    respond(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log((string)$error);
    respond(['error' => 'Ocurrió un error al procesar la solicitud.'], 500);
}
