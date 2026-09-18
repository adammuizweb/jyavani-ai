<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$coreRoot = rtrim((string)(getenv('JAI_CORE_ROOT') ?: dirname($pluginRoot, 2) . '/jyavani.lan'), DIRECTORY_SEPARATOR);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

require_once $pluginRoot . '/includes/config.php';
require_once $pluginRoot . '/includes/provider.php';

$check(jai_provider_url_is_valid('https://api.example.com/v1/chat/completions?api-version=1'), 'HTTPS provider endpoints with bounded query metadata are accepted');
foreach (['http://api.example.com/v1', 'https://user:pass@example.com/v1', 'https://example.com/v1#secret', "https://example.com/\nheader"] as $url) {
    $check(!jai_provider_url_is_valid($url), 'unsafe provider endpoint is rejected: ' . json_encode($url));
}
$check(jai_provider_public_addresses('https://8.8.8.8/v1') === ['8.8.8.8'], 'a literal public provider address is accepted without DNS');
foreach ([
    'https://127.0.0.1/v1', 'https://[::1]/v1', 'https://169.254.169.254/v1', 'https://10.0.0.1/v1',
    'https://100.64.0.1/v1', 'https://198.18.0.1/v1', 'https://224.0.0.1/v1',
    'https://192.0.0.1/v1', 'https://198.51.100.1/v1', 'https://[ff02::1]/v1', 'https://[2001:db8::1]/v1',
    'https://[2606:4700:4700::1111]/v1', 'https://[3fff::1]/v1', 'https://[2001:100::1]/v1',
] as $url) {
    try {
        jai_provider_public_addresses($url);
        $privateRejected = false;
    } catch (RuntimeException) {
        $privateRejected = true;
    }
    $check($privateRejected, 'private or reserved provider address is rejected: ' . $url);
}

$oldTimeout = getenv('JYAVANI_AI_TIMEOUT_SECONDS');
$oldInput = getenv('JYAVANI_AI_MAX_INPUT_BYTES');
putenv('JYAVANI_AI_TIMEOUT_SECONDS=9999');
putenv('JYAVANI_AI_MAX_INPUT_BYTES=1');
$check(jai_provider_timeout() === 120, 'provider timeout has a hard upper bound');
$check(jai_max_input_bytes() === 1000, 'source content has a hard lower bound');
if ($oldTimeout === false) putenv('JYAVANI_AI_TIMEOUT_SECONDS'); else putenv('JYAVANI_AI_TIMEOUT_SECONDS=' . $oldTimeout);
if ($oldInput === false) putenv('JYAVANI_AI_MAX_INPUT_BYTES'); else putenv('JYAVANI_AI_MAX_INPUT_BYTES=' . $oldInput);

$check(jai_provider_extract_content(['choices' => [['message' => ['content' => '<p>Safe</p>']]]]) === '<p>Safe</p>', 'string provider content is extracted');
$check(jai_provider_extract_content(['choices' => [['message' => ['content' => [['type' => 'text', 'text' => '<p>One</p>'], ['type' => 'text', 'text' => '<p>Two</p>']]]]]]) === '<p>One</p><p>Two</p>', 'text-part provider content is extracted');
try {
    jai_provider_extract_content(['choices' => [['message' => ['content' => '']]]]);
    $emptyRejected = false;
} catch (RuntimeException) {
    $emptyRejected = true;
}
$check($emptyRejected, 'empty provider content fails closed');
$check(jai_provider_failure_reason(429, ['error' => ['code' => 'credit_balance_exhausted']]) === 'quota'
    && jai_provider_failure_reason(429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']]) === 'quota'
    && jai_provider_failure_reason(503, ['error' => ['status' => 'UNAVAILABLE']]) === 'unavailable'
    && jai_provider_failure_reason(401, []) === 'credentials',
    'provider failures map to bounded secret-safe reasons');
$temporaryError = jai_provider_public_error(new JaiProviderException(503, 'unavailable'));
$check($temporaryError === ['The AI provider is temporarily unavailable. Try again.', 503], 'temporary provider failures expose an actionable safe response');

if (!is_file($coreRoot . '/cfg/helpers/cms_content.php')) {
    fwrite(STDERR, "Canonical Core sanitizer not found at {$coreRoot}. Set JAI_CORE_ROOT.\n");
    exit(1);
}
require_once $coreRoot . '/cfg/helpers/cms_content.php';
require_once $coreRoot . '/cfg/helpers/editor_helpers.php';
$sanitized = jai_sanitize_generated_content('<script>alert(1)</script><p onclick="alert(1)">Safe <a href="javascript:alert(1)">link</a></p>');
$check(!str_contains($sanitized, '<script') && !str_contains($sanitized, 'onclick=') && !str_contains($sanitized, 'javascript:'), 'generated HTML always passes through the restricted Core sanitizer');
$check(str_contains($sanitized, '<p>') && str_contains($sanitized, 'Safe'), 'safe generated prose survives sanitization');

$messages = jai_provider_messages('custom', 'Use a calm tone.', '<p>Draft</p>', 'codemirror', 'document');
$encodedMessages = json_encode($messages, JSON_UNESCAPED_SLASHES);
$check(str_contains((string)$encodedMessages, 'Use a calm tone.') && str_contains((string)$encodedMessages, '<p>Draft</p>'), 'provider prompt contains the bounded user task and source');
$check(!str_contains((string)$encodedMessages, 'JYAVANI_AI_API_KEY') && !str_contains((string)$encodedMessages, 'Authorization'), 'provider prompt contains no credential metadata');

$endpoint = (string)file_get_contents($pluginRoot . '/admin/generate.php');
$provider = (string)file_get_contents($pluginRoot . '/includes/provider.php');
$usage = (string)file_get_contents($pluginRoot . '/includes/usage.php');
$client = (string)file_get_contents($pluginRoot . '/assets/js/editor-assistant.js');
$migration = (string)file_get_contents($pluginRoot . '/migrations/0001-ai-usage.sql');
$check(str_contains($endpoint, "REQUEST_METHOD'] ?? 'GET'")
    && str_contains($endpoint, "CONTENT_TYPE'] ?? ''")
    && str_contains($endpoint, 'CONTENT_LENGTH')
    && str_contains($endpoint, '(jai_max_input_bytes() * 6) + 65536')
    && str_contains($endpoint, "file_get_contents('php://input', false, null, 0, \$requestLimit + 1)"),
    'endpoint enforces method, JSON content type, and JSON-escape-aware bounded request reads');
$check(str_contains($endpoint, 'adiwira_csrf_validate($csrf)')
    && str_contains($endpoint, 'session_write_close()')
    && str_contains($endpoint, "header('Retry-After: 60')"),
    'endpoint validates CSRF, releases the session, and provides bounded retry guidance');
$check(str_contains($provider, 'CURLOPT_SSL_VERIFYPEER => true')
    && str_contains($provider, 'CURLOPT_SSL_VERIFYHOST => 2')
    && str_contains($provider, 'CURLOPT_FOLLOWLOCATION => false')
    && str_contains($provider, 'CURLOPT_WRITEFUNCTION')
    && str_contains($provider, 'CURLOPT_RESOLVE')
    && str_contains($provider, "CURLOPT_PROXY => ''")
    && str_contains($provider, "CURLOPT_NOPROXY => '*'")
    && str_contains($provider, 'CURLPROTO_HTTPS')
    && str_contains($provider, '$status !== 503 || $attempt === 1')
    && str_contains($provider, '$providerAddresses[$attempt % count($providerAddresses)]'),
    'provider transport verifies TLS, pins public DNS, rejects redirects, restricts protocols, caps response bytes, and retries one transient 503');
$check(!preg_match('/prompt|content|selection|api_key|authorization/i', $migration)
    && str_contains($migration, '`input_bytes`')
    && str_contains($migration, '`output_tokens`'),
    'usage schema stores bounded metadata without prompts, content, selections, or credentials');
$check(!str_contains($usage, "error->getMessage") && !str_contains($usage, 'content'), 'usage recorder never stores provider errors or content');
$check(str_contains($client, 'result.value = payload.html')
    && !str_contains($client, 'result.innerHTML = payload.html')
    && !str_contains($client, 'localStorage')
    && !str_contains($client, 'sessionStorage'),
    'browser preview treats generated HTML as text and does not persist drafts in browser storage');
$check(str_contains($client, 'function invalidateResult()')
    && str_contains($client, "instruction.addEventListener('input', invalidateResult)")
    && substr_count($client, 'invalidateResult();') >= 2,
    'changing generation controls invalidates stale generated results');
$check(str_contains($client, "format('Only the selected text will be sent to %s.', config.providerHost)")
    && str_contains($client, 'element.inert = true')
    && str_contains($client, 'returnFocus.focus()'),
    'dialog identifies the provider, isolates background content, and restores activating focus');
$authorization = (string)file_get_contents($pluginRoot . '/includes/authorization.php');
$check(str_contains($authorization, "apply_filters(\$statusHook")
    && str_contains($authorization, 'content_schedule_editor_status')
    && str_contains($authorization, "permissions['publish']"),
    'resource authorization follows workflow-filtered and scheduled Core editor status');
$check(str_contains($usage, 'LAST_INSERT_ID(1)')
    && str_contains($usage, 'request_count` = LAST_INSERT_ID(IF(')
    && !str_contains($usage, 'SELECT `window_started_at`'),
    'rate-limit consumption returns its own atomic statement result');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " security assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
