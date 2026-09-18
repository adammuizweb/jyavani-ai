<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    http_response_code(404);
    exit;
}

[$userId] = adiwira_require_permission($pdo, JAI_PERMISSION_GENERATE, true);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    adiwira_json(['ok' => false, 'error' => jai_t('Method not allowed.')], 405);
}

$requestLimit = (jai_max_input_bytes() * 6) + 65536;
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength <= 0 || $contentLength > $requestLimit) {
    adiwira_json(['ok' => false, 'error' => jai_t('The AI request is too large.')], 413);
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
if ($contentType !== 'application/json') {
    adiwira_json(['ok' => false, 'error' => jai_t('The AI request must use JSON.')], 415);
}
$raw = file_get_contents('php://input', false, null, 0, $requestLimit + 1);
if (!is_string($raw) || strlen($raw) > $requestLimit) {
    adiwira_json(['ok' => false, 'error' => jai_t('The AI request is too large.')], 413);
}
try {
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    adiwira_json(['ok' => false, 'error' => jai_t('The AI request is invalid.')], 400);
}
if (!is_array($input)) adiwira_json(['ok' => false, 'error' => jai_t('The AI request is invalid.')], 400);

$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => jai_t('Invalid CSRF token.')], 403);
}

$resourceType = strtolower(trim((string)($input['resource_type'] ?? '')));
$editorOperation = strtolower(trim((string)($input['editor_operation'] ?? '')));
$resourceIdRaw = $input['resource_id'] ?? null;
$resourceId = ($resourceIdRaw === null || $resourceIdRaw === '') ? null : (int)$resourceIdRaw;
$editorMode = strtolower(trim((string)($input['editor_mode'] ?? '')));
$operation = strtolower(trim((string)($input['operation'] ?? '')));
$target = strtolower(trim((string)($input['target'] ?? '')));
$instruction = trim((string)($input['instruction'] ?? ''));
$source = (string)($input['content'] ?? '');
$revision = max(0, (int)($input['revision'] ?? 0));

if (!in_array($resourceType, ['article', 'page'], true)
    || !in_array($editorOperation, ['add', 'edit'], true)
    || !in_array($editorMode, ['quill', 'codemirror'], true)
    || !in_array($operation, ['improve', 'rewrite', 'shorten', 'expand', 'translate', 'custom'], true)
    || !in_array($target, ['selection', 'document'], true)) {
    adiwira_json(['ok' => false, 'error' => jai_t('The AI request is invalid.')], 400);
}
if ($source === '' || strlen($source) > jai_max_input_bytes() || !mb_check_encoding($source, 'UTF-8')) {
    adiwira_json(['ok' => false, 'error' => jai_t('The source content is empty or too large.')], 422);
}
if (strlen($instruction) > 2000 || !mb_check_encoding($instruction, 'UTF-8')) {
    adiwira_json(['ok' => false, 'error' => jai_t('The AI instructions are too large.')], 422);
}
if (in_array($operation, ['translate', 'custom'], true) && $instruction === '') {
    adiwira_json(['ok' => false, 'error' => jai_t('Enter instructions for this action.')], 422);
}

try {
    jai_authorize_content_request($pdo, (int)$userId, $resourceType, $editorOperation, $resourceId);
} catch (DomainException $error) {
    adiwira_json(['ok' => false, 'error' => jai_t($error->getMessage())], 403);
}
if (!jai_provider_is_ready()) {
    adiwira_json(['ok' => false, 'error' => jai_t('The AI provider is not configured.')], 503);
}
try {
    $rate = jai_consume_rate_limit($pdo, (int)$userId);
} catch (Throwable $error) {
    error_log('[jyavani-ai] Rate-limit storage is unavailable.');
    adiwira_json(['ok' => false, 'error' => jai_t('The AI service is not ready. Reactivate the plugin to run its migrations.')], 503);
}
if (!$rate['allowed']) {
    header('Retry-After: 60');
    adiwira_json(['ok' => false, 'error' => jai_t('Too many AI requests. Try again in one minute.')], 429);
}

$messages = jai_provider_messages($operation, $instruction, $source, $editorMode, $target);
$started = hrtime(true);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$usage = [
    'user_id' => (int)$userId,
    'resource_type' => $resourceType,
    'resource_id' => $resourceId,
    'operation' => $operation,
    'model' => jai_provider_model(),
    'input_bytes' => strlen($source),
    'output_bytes' => 0,
    'input_tokens' => 0,
    'output_tokens' => 0,
    'duration_ms' => 0,
    'status' => 'failed',
];

try {
    $generated = jai_provider_generate($messages);
    $usage['output_bytes'] = strlen($generated['content']);
    $usage['input_tokens'] = (int)$generated['input_tokens'];
    $usage['output_tokens'] = (int)$generated['output_tokens'];
    $usage['duration_ms'] = (int)round((hrtime(true) - $started) / 1000000);
    $usage['status'] = 'success';
    jai_record_usage($pdo, $usage);
    adiwira_json([
        'ok' => true,
        'html' => $generated['content'],
        'revision' => $revision,
        'usage' => [
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
        ],
    ]);
} catch (Throwable $error) {
    $usage['duration_ms'] = (int)round((hrtime(true) - $started) / 1000000);
    jai_record_usage($pdo, $usage);
    error_log('[jyavani-ai] Provider request failed: ' . $error->getMessage());
    [$publicError, $publicStatus] = jai_provider_public_error($error);
    adiwira_json(['ok' => false, 'error' => $publicError], $publicStatus);
}
