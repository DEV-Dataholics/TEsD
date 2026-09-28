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

const EVENT_FACILITY_OPTIONS = [
    'speaker', 'ice_boxes', 'fridge', 'coal_grill', 'gas_grill', 'pool',
    'parking', 'wifi', 'tables_chairs', 'restrooms',
];

function validatedEventDetails(array $data, array $current = []): array
{
    $name = trim((string)($data['event_name'] ?? $current['event_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) {
        throw new InvalidArgumentException('El nombre del evento es obligatorio y debe tener máximo 160 caracteres.');
    }

    $date = array_key_exists('event_date', $data) ? trim((string)$data['event_date']) : (string)($current['event_date'] ?? '');
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('event_date debe tener formato YYYY-MM-DD.');
    }

    $time = array_key_exists('event_time', $data) ? trim((string)$data['event_time']) : (string)($current['event_time'] ?? '');
    if ($time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        throw new InvalidArgumentException('event_time debe tener formato HH:MM.');
    }

    $theme = trim((string)($data['theme'] ?? $current['theme'] ?? ''));
    if (mb_strlen($theme) > 160) {
        throw new InvalidArgumentException('El tema debe tener máximo 160 caracteres.');
    }

    $venueName = trim((string)($data['venue_name'] ?? $current['venue_name'] ?? ''));
    if (mb_strlen($venueName) > 160) {
        throw new InvalidArgumentException('El nombre del lugar debe tener máximo 160 caracteres.');
    }

    $venueAddress = trim((string)($data['venue_address'] ?? $current['venue_address'] ?? ''));
    if (mb_strlen($venueAddress) > 300) {
        throw new InvalidArgumentException('La dirección debe tener máximo 300 caracteres.');
    }

    // Host-only fields below: never exposed on the public RSVP page.
    $capacityValue = $data['venue_capacity'] ?? $current['venue_capacity'] ?? null;
    $capacity = ($capacityValue === '' || $capacityValue === null) ? null : filter_var($capacityValue, FILTER_VALIDATE_INT);
    if ($capacityValue !== '' && $capacityValue !== null && ($capacity === false || $capacity < 0 || $capacity > 100000)) {
        throw new InvalidArgumentException('El aforo debe ser un número entre 0 y 100000.');
    }

    $facilitiesInput = array_key_exists('venue_facilities', $data) ? $data['venue_facilities'] : ($current['venue_facilities'] ?? []);
    if (!is_array($facilitiesInput)) {
        throw new InvalidArgumentException('venue_facilities debe ser una lista.');
    }
    $facilities = [];
    foreach ($facilitiesInput as $facility) {
        $facility = trim((string)$facility);
        if ($facility === '' || mb_strlen($facility) > 60) {
            continue;
        }
        $facilities[] = $facility;
    }
    $facilities = array_values(array_unique($facilities));
    if (count($facilities) > 30) {
        throw new InvalidArgumentException('Se permiten máximo 30 elementos en las instalaciones del lugar.');
    }

    $hostNotes = trim((string)($data['host_notes'] ?? $current['host_notes'] ?? ''));
    if (mb_strlen($hostNotes) > 2000) {
        throw new InvalidArgumentException('Las notas para el organizador deben tener máximo 2000 caracteres.');
    }

    return [
        'event_name' => $name,
        'event_date' => $date ?: null,
        'event_time' => $time ?: null,
        'theme' => $theme ?: null,
        'venue_name' => $venueName ?: null,
        'venue_address' => $venueAddress ?: null,
        'venue_capacity' => $capacity,
        'venue_facilities' => $facilities,
        'host_notes' => $hostNotes ?: null,
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

    if ($path === '/events' && $method === 'GET') {
        $eventFields = 'e.id, e.owner_user_id, e.event_name, e.event_date, e.event_time, e.theme,
                        e.venue_name, e.venue_address, e.venue_capacity, e.venue_facilities, e.host_notes';
        if ($user['role'] === 'admin') {
            auditAdminAction($db, $user, 'list_all_events', 'event', null);
            $events = $db->query(
                "SELECT {$eventFields}, u.email AS owner_email
                 FROM events e JOIN users u ON u.id = e.owner_user_id ORDER BY e.event_date, e.id"
            )->fetchAll();
        } else {
            $query = $db->prepare(
                "SELECT {$eventFields} FROM events e WHERE e.owner_user_id = :owner ORDER BY e.event_date, e.id"
            );
            $query->execute(['owner' => $user['id']]);
            $events = $query->fetchAll();
        }
        foreach ($events as &$event) {
            $event['id'] = (int)$event['id'];
            $event['owner_user_id'] = (int)$event['owner_user_id'];
            $event['venue_capacity'] = $event['venue_capacity'] === null ? null : (int)$event['venue_capacity'];
            $event['venue_facilities'] = $event['venue_facilities'] ? json_decode($event['venue_facilities'], true) : [];
        }
        unset($event);
        respond(['data' => $events]);
    }

    if ($path === '/events' && $method === 'POST') {
        $event = validatedEventDetails(requestData());
        $insert = $db->prepare(
            'INSERT INTO events (owner_user_id, event_name, event_date, event_time, theme,
                                 venue_name, venue_address, venue_capacity, venue_facilities, host_notes)
             VALUES (:owner, :name, :date, :time, :theme, :venue_name, :venue_address, :capacity, :facilities, :host_notes)'
        );
        $insert->execute([
            'owner' => $user['id'],
            'name' => $event['event_name'],
            'date' => $event['event_date'],
            'time' => $event['event_time'],
            'theme' => $event['theme'],
            'venue_name' => $event['venue_name'],
            'venue_address' => $event['venue_address'],
            'capacity' => $event['venue_capacity'],
            'facilities' => json_encode($event['venue_facilities'], JSON_UNESCAPED_UNICODE),
            'host_notes' => $event['host_notes'],
        ]);
        $event['id'] = (int)$db->lastInsertId();
        respond(['data' => $event], 201);
    }

    if (preg_match('#^/events/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $eventId = (int)$matches[1];
        $current = requireEventAccess($db, $user, $eventId, 'update');
        $current['venue_facilities'] = $current['venue_facilities'] ? (json_decode((string)$current['venue_facilities'], true) ?: []) : [];
        $event = validatedEventDetails(requestData(), $current);
        $update = $db->prepare(
            'UPDATE events SET event_name = :name, event_date = :date, event_time = :time, theme = :theme,
                    venue_name = :venue_name, venue_address = :venue_address, venue_capacity = :capacity,
                    venue_facilities = :facilities, host_notes = :host_notes WHERE id = :id'
        );
        $update->execute([
            'name' => $event['event_name'],
            'date' => $event['event_date'],
            'time' => $event['event_time'],
            'theme' => $event['theme'],
            'venue_name' => $event['venue_name'],
            'venue_address' => $event['venue_address'],
            'capacity' => $event['venue_capacity'],
            'facilities' => json_encode($event['venue_facilities'], JSON_UNESCAPED_UNICODE),
            'host_notes' => $event['host_notes'],
            'id' => $eventId,
        ]);
        $event['id'] = $eventId;
        respond(['data' => $event]);
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
