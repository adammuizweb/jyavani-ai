<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function jai_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function jai_env_override(string $name): ?string
{
    $value = getenv($name);
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

function jai_config_integer(string $environmentName, string $storedKey, int $minimum, int $maximum): int
{
    $stored = jai_load_provider_config()[$storedKey] ?? $minimum;
    $raw = jai_env_override($environmentName);
    $value = $raw !== null && preg_match('/\A\d+\z/', $raw) === 1 ? (int)$raw : (int)$stored;
    return max($minimum, min($maximum, $value));
}

function jai_provider_api_key(): string
{
    $environment = jai_env_override('JYAVANI_AI_API_KEY');
    if ($environment !== null) return strlen($environment) <= 4096 ? $environment : '';
    $provider = jai_active_provider();
    $providerEnvironment = jai_env_override('JYAVANI_AI_' . strtoupper($provider) . '_API_KEY');
    if ($providerEnvironment !== null) return strlen($providerEnvironment) <= 4096 ? $providerEnvironment : '';
    $encrypted = (string)(jai_load_provider_config()[$provider . '_api_key_encrypted'] ?? '');
    return $encrypted === '' ? '' : (jai_decrypt_api_key($encrypted) ?? '');
}

function jai_active_provider(): string
{
    $provider = (string)(jai_load_provider_config()['active_provider'] ?? 'openai');
    return array_key_exists($provider, jai_provider_presets()) ? $provider : 'openai';
}

function jai_active_provider_preset(): array
{
    return jai_provider_presets()[jai_active_provider()];
}

function jai_provider_url(): string
{
    if (jai_env_override('JYAVANI_AI_API_KEY') !== null) {
        return jai_env_override('JYAVANI_AI_API_URL') ?? (string)jai_active_provider_preset()['provider_url'];
    }
    return (string)jai_active_provider_preset()['provider_url'];
}

function jai_provider_model(): string
{
    if (jai_env_override('JYAVANI_AI_API_KEY') !== null) {
        return substr(jai_env_override('JYAVANI_AI_MODEL') ?? (string)jai_active_provider_preset()['model'], 0, 191);
    }
    return (string)jai_active_provider_preset()['model'];
}

function jai_provider_timeout(): int
{
    return jai_config_integer('JYAVANI_AI_TIMEOUT_SECONDS', 'timeout_seconds', 10, 120);
}

function jai_max_input_bytes(): int
{
    return jai_config_integer('JYAVANI_AI_MAX_INPUT_BYTES', 'max_input_bytes', 1000, 524288);
}

function jai_max_output_tokens(): int
{
    return jai_config_integer('JYAVANI_AI_MAX_OUTPUT_TOKENS', 'max_output_tokens', 256, 16384);
}

function jai_requests_per_minute(): int
{
    return jai_config_integer('JYAVANI_AI_REQUESTS_PER_MINUTE', 'requests_per_minute', 1, 60);
}

function jai_provider_key_source(): string
{
    if (jai_env_override('JYAVANI_AI_API_KEY') !== null) return 'environment';
    $provider = jai_active_provider();
    if (jai_env_override('JYAVANI_AI_' . strtoupper($provider) . '_API_KEY') !== null) return 'environment';
    $encrypted = (string)(jai_load_provider_config()[$provider . '_api_key_encrypted'] ?? '');
    if ($encrypted === '') return 'missing';
    return jai_decrypt_api_key($encrypted) === null ? 'unavailable' : 'dashboard';
}

function jai_environment_overrides(): array
{
    $result = [];
    foreach ([
        'api_key' => 'JYAVANI_AI_API_KEY',
        'openai_api_key' => 'JYAVANI_AI_OPENAI_API_KEY',
        'gemini_api_key' => 'JYAVANI_AI_GEMINI_API_KEY',
        'timeout_seconds' => 'JYAVANI_AI_TIMEOUT_SECONDS',
        'max_input_bytes' => 'JYAVANI_AI_MAX_INPUT_BYTES',
        'max_output_tokens' => 'JYAVANI_AI_MAX_OUTPUT_TOKENS',
        'requests_per_minute' => 'JYAVANI_AI_REQUESTS_PER_MINUTE',
    ] as $field => $environmentName) {
        if (jai_env_override($environmentName) !== null) $result[$field] = $environmentName;
    }
    if (jai_env_override('JYAVANI_AI_API_KEY') !== null) {
        foreach (['provider_url' => 'JYAVANI_AI_API_URL', 'model' => 'JYAVANI_AI_MODEL'] as $field => $environmentName) {
            if (jai_env_override($environmentName) !== null) $result[$field] = $environmentName;
        }
    }
    return $result;
}

function jai_environment_override_errors(): array
{
    $errors = [];
    foreach (['JYAVANI_AI_API_KEY', 'JYAVANI_AI_OPENAI_API_KEY', 'JYAVANI_AI_GEMINI_API_KEY'] as $name) {
        $apiKey = jai_env_override($name);
        if ($apiKey !== null && (strlen($apiKey) > 4096 || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1)) $errors[] = $name;
    }
    if (jai_env_override('JYAVANI_AI_API_KEY') !== null) {
        $url = jai_env_override('JYAVANI_AI_API_URL');
        if ($url !== null && !jai_provider_url_is_valid($url)) $errors[] = 'JYAVANI_AI_API_URL';
        $model = jai_env_override('JYAVANI_AI_MODEL');
        if ($model !== null && (strlen($model) > 191 || preg_match('/[\x00-\x1f\x7f]/', $model) === 1)) $errors[] = 'JYAVANI_AI_MODEL';
    }
    foreach ([
        'JYAVANI_AI_TIMEOUT_SECONDS' => [10, 120],
        'JYAVANI_AI_MAX_INPUT_BYTES' => [1000, 524288],
        'JYAVANI_AI_MAX_OUTPUT_TOKENS' => [256, 16384],
        'JYAVANI_AI_REQUESTS_PER_MINUTE' => [1, 60],
    ] as $name => [$minimum, $maximum]) {
        $raw = jai_env_override($name);
        if ($raw !== null && (preg_match('/\A\d+\z/', $raw) !== 1 || (int)$raw < $minimum || (int)$raw > $maximum)) $errors[] = $name;
    }
    return $errors;
}

function jai_provider_url_is_valid(string $url): bool
{
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
    if (!isset($parts['host']) || trim((string)$parts['host']) === '') return false;
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    $host = trim((string)$parts['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/i', $host) !== 1) return false;
    return true;
}

function jai_ip_in_cidr(string $ip, string $cidr): bool
{
    [$network, $prefixRaw] = explode('/', $cidr, 2);
    $addressBytes = inet_pton($ip);
    $networkBytes = inet_pton($network);
    if (!is_string($addressBytes) || !is_string($networkBytes) || strlen($addressBytes) !== strlen($networkBytes)) return false;
    $prefix = (int)$prefixRaw;
    $maximum = strlen($addressBytes) * 8;
    if ($prefix < 0 || $prefix > $maximum) return false;
    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) return false;
    if ($remainingBits === 0) return true;
    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
}

function jai_ip_is_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
    $blocked = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];
    foreach ($blocked as $cidr) {
        if (jai_ip_in_cidr($ip, $cidr)) return false;
    }
    return true;
}

function jai_provider_public_addresses(string $url): array
{
    if (!jai_provider_url_is_valid($url)) throw new RuntimeException('Invalid AI provider URL.');
    $parts = parse_url($url);
    $host = trim((string)($parts['host'] ?? ''), '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!jai_ip_is_public($host)) throw new RuntimeException('AI provider resolved to a non-public address.');
        return [$host];
    }
    $records = dns_get_record($host, DNS_A);
    if (!is_array($records) || $records === []) throw new RuntimeException('Unable to resolve the AI provider.');
    $addresses = [];
    foreach ($records as $record) {
        $address = is_string($record['ip'] ?? null) ? $record['ip'] : '';
        if ($address === '' || !jai_ip_is_public($address)) throw new RuntimeException('AI provider resolved to a non-public address.');
        $addresses[$address] = true;
    }
    if ($addresses === []) throw new RuntimeException('Unable to resolve the AI provider.');
    return array_keys($addresses);
}

function jai_provider_host(): string
{
    $parts = parse_url(jai_provider_url());
    return is_array($parts) ? trim((string)($parts['host'] ?? ''), '[]') : '';
}

function jai_provider_is_ready(): bool
{
    $key = jai_provider_api_key();
    return jai_environment_override_errors() === []
        && $key !== '' && strlen($key) <= 4096
        && jai_provider_model() !== ''
        && jai_provider_url_is_valid(jai_provider_url());
}

function jai_locale(): string
{
    $locale = function_exists('get_locale') ? strtolower((string)get_locale()) : 'en';
    return explode('-', str_replace('_', '-', $locale), 2)[0];
}

function jai_t(string $source, mixed ...$args): string
{
    $translated = function_exists('__') ? (string)__($source) : $source;
    if ($translated === $source) {
        static $catalogs = [];
        $locale = jai_locale();
        if (!array_key_exists($locale, $catalogs)) {
            $file = dirname(__DIR__) . '/languages/' . $locale . '.php';
            $catalog = is_file($file) ? require $file : [];
            $catalogs[$locale] = is_array($catalog) ? $catalog : [];
        }
        $translated = (string)($catalogs[$locale][$source] ?? $source);
    }
    return $args === [] ? $translated : sprintf($translated, ...$args);
}

function jai_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jai_editor_i18n(): array
{
    $strings = [
        'AI Assistant', 'Generate or rewrite content', 'AI writing assistant', 'Close',
        'Action', 'Improve writing', 'Rewrite', 'Shorten', 'Expand', 'Translate', 'Custom instructions',
        'Apply to', 'Selected text', 'Entire document', 'Instructions',
        'Describe the intended result, tone, language, or constraints.',
        'Only the selected text will be sent to %s.',
        'The complete document will be sent to %s.',
        'Source', 'Generated result', 'Generate', 'Generating...', 'Apply result', 'Cancel',
        'Select text in the editor first.', 'Enter instructions for this action.',
        'The AI provider is not configured.', 'Open settings', 'The request failed.',
        'The editor changed while AI was generating. Generate again to avoid overwriting newer work.',
        'Generated content was applied. Review it before saving.', 'No generated content was returned.',
    ];
    $result = [];
    foreach ($strings as $source) $result[$source] = jai_t($source);
    return $result;
}
