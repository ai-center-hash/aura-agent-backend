<?php

/**
 * Local stand-in for AURA-AGENT / FREYA-AGENT: speaks the shared /v1 contract
 * (docs: 01-api-contract) so the backend can be tested without the real agents.
 *
 *   php -S 127.0.0.1:8199 dev/mock-agent.php
 *
 * Point both agents at it in .env:
 *   AURA_AGENT_URL=http://127.0.0.1:8199   AURA_AGENT_SERVICE_KEY=local-mock-key
 *   FREYA_AGENT_URL=http://127.0.0.1:8199  FREYA_AGENT_SERVICE_KEY=local-mock-key
 *
 * Behaviour:
 *   - X-Service-Key must equal MOCK_AGENT_KEY (default "local-mock-key"), else 401
 *   - no Authorization header = guest: thread_id must be a UUID (else 422)
 *   - a message containing "expired" streams error TOKEN_EXPIRED, "down" streams LLM_UNAVAILABLE
 *   - DELETE /v1/chat/{thread_id} needs Authorization → 204
 */
$key = getenv('MOCK_AGENT_KEY') ?: 'local-mock-key';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

$json = function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
};

if ($path === '/v1/health') {
    $json(200, ['status' => 'ok', 'llm' => 'ok', 'registrar_db' => 'ok', 'mock' => 'yes']);

    return;
}

if (! hash_equals($key, $_SERVER['HTTP_X_SERVICE_KEY'] ?? '')) {
    $json(401, ['detail' => 'invalid service key']);

    return;
}

if ($authorization !== '' && ! preg_match('/^Bearer \S+$/', $authorization)) {
    $json(401, ['detail' => 'Authorization must be "Bearer <token>"']);

    return;
}

if ($method === 'DELETE' && str_starts_with($path, '/v1/chat/')) {
    http_response_code($authorization === '' ? 401 : 204);

    return;
}

if ($method !== 'POST' || $path !== '/v1/chat') {
    $json(404, ['detail' => 'not found']);

    return;
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$thread = (string) ($body['thread_id'] ?? '');
$message = (string) ($body['message'] ?? '');
$guest = $authorization === '';

if ($thread === '' || mb_strlen($thread) > 100 || $message === '' || mb_strlen($message) > 1000
    || ($guest && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $thread))) {
    $json(422, ['detail' => 'invalid thread_id or message']);

    return;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

$send = function (string $event, array $data): void {
    echo "event: {$event}\r\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE)."\r\n\r\n";
    @ob_flush();
    flush();
};

$started = microtime(true);

if (str_contains($message, 'expired')) {
    $send('error', ['code' => 'TOKEN_EXPIRED', 'text' => 'เซสชันหมดอายุแล้วค่ะ กรุณาเข้าสู่ระบบใหม่']);

    return;
}

if (str_contains($message, 'down')) {
    $send('error', ['code' => 'LLM_UNAVAILABLE', 'text' => 'ผู้ช่วยไม่พร้อมใช้งานชั่วคราวค่ะ']);

    return;
}

$send('status', ['text' => $guest ? 'กำลังดูกติกาของระบบทะเบียน...' : 'กำลังดูวิชาที่ลงทะเบียนและตารางเรียน...']);
usleep(400_000);

$answer = $guest
    ? ['(mock · ผู้ใช้ทั่วไป) ', 'ตอบได้เฉพาะ**กติกากลาง**ค่ะ ', 'ถ้าต้องการข้อมูลของตัวเองกรุณาเข้าสู่ระบบด้วย YRU Passport']
    : ['(mock) ', 'คำถาม: "'.mb_strimwidth($message, 0, 80, '…').'"', "\n\n", 'เทอมนี้ลงทะเบียน ', '**13 หน่วยกิต** ค่ะ'];

foreach ($answer as $chunk) {
    $send('token', ['text' => $chunk]);
    usleep(150_000);
}

$send('done', [
    'thread_id' => $thread,
    'tools_used' => $guest ? ['search_rules'] : ['get_my_enrollment'],
    'latency_ms' => (int) ((microtime(true) - $started) * 1000),
    'prompt_tokens' => 3100,
    'completion_tokens' => 90,
]);
