<?php
declare(strict_types=1);

function jai_default_provider_config(): array
{
    return [
        'active_provider' => 'openai',
        'openai_api_key_encrypted' => '',
        'gemini_api_key_encrypted' => '',
        'timeout_seconds' => 60,
        'max_input_bytes' => 200000,
        'max_output_tokens' => 4000,
        'requests_per_minute' => 10,
    ];
}

function jai_provider_presets(): array
{
    return [
        'openai' => [
            'label' => 'ChatGPT (OpenAI)',
            'provider_url' => 'https://api.openai.com/v1/chat/completions',
            'model' => 'gpt-4.1-mini',
        ],
        'gemini' => [
            'label' => 'Gemini',
            'provider_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
            'model' => 'models/gemini-flash-lite-latest',
        ],
    ];
}

function jai_secret_source(): string
{
    foreach (['JYAVANI_AI_SECRET_KEY', 'APP_KEY', 'SESSION_SECRET'] as $name) {
        $value = getenv($name);
        if (is_string($value) && strlen($value) >= 32) return $value;
    }
    return '';
}

function jai_crypto_available(): bool
{
    return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') && jai_secret_source() !== '';
}

function jai_encryption_key(): string
{
    $secret = jai_secret_source();
    if ($secret === '' || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        throw new RuntimeException('AI credential encryption is unavailable.');
    }
    return hash('sha256', "jyavani-ai:provider-key:v1\0" . $secret, true);
}

function jai_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function jai_base64url_decode(string $value): ?string
{
    if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) return null;
    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
    return is_string($decoded) ? $decoded : null;
}

function jai_encrypt_api_key(string $apiKey): string
{
    $apiKey = trim($apiKey);
    if ($apiKey === '' || strlen($apiKey) > 4096 || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1) {
        throw new DomainException(jai_t('Enter a valid API key.'));
    }
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
        $apiKey,
        'jyavani-ai-provider-config-v1',
        $nonce,
        jai_encryption_key()
    );
    return 'v1:' . jai_base64url_encode($nonce . $ciphertext);
}

function jai_decrypt_api_key(string $encoded): ?string
{
    if (!str_starts_with($encoded, 'v1:')) return null;
    try {
        $payload = jai_base64url_decode(substr($encoded, 3));
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($payload === null || strlen($payload) <= $nonceBytes) return null;
        $apiKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($payload, $nonceBytes),
            'jyavani-ai-provider-config-v1',
            substr($payload, 0, $nonceBytes),
            jai_encryption_key()
        );
        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    } catch (Throwable $error) {
        return null;
    }
}

function jai_normalize_provider_config(array $config): array
{
    $defaults = jai_default_provider_config();
    $config = array_merge($defaults, array_intersect_key($config, $defaults));
    $activeProvider = is_string($config['active_provider']) ? $config['active_provider'] : '';
    $config['active_provider'] = array_key_exists($activeProvider, jai_provider_presets()) ? $activeProvider : $defaults['active_provider'];
    foreach (['openai_api_key_encrypted', 'gemini_api_key_encrypted'] as $key) {
        $config[$key] = is_string($config[$key]) ? $config[$key] : '';
    }
    foreach (['timeout_seconds', 'max_input_bytes', 'max_output_tokens', 'requests_per_minute'] as $key) {
        $config[$key] = is_int($config[$key]) ? $config[$key] : (int)$config[$key];
    }
    return $config;
}

function jai_load_provider_config(?PDO $pdo = null): array
{
    if (isset($GLOBALS['jai_provider_config_cache']) && is_array($GLOBALS['jai_provider_config_cache'])) {
        return $GLOBALS['jai_provider_config_cache'];
    }
    $config = jai_default_provider_config();
    $pdo ??= $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        try {
            $row = $pdo->query('SELECT `active_provider`, `openai_api_key_encrypted`, `gemini_api_key_encrypted`, `timeout_seconds`, `max_input_bytes`, `max_output_tokens`, `requests_per_minute` FROM `' . JAI_SETTINGS_TABLE . '` WHERE `id` = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) $config = jai_normalize_provider_config($row);
        } catch (Throwable $error) {
            // The defaults keep the plugin inactive until its migration is available.
        }
    }
    return $GLOBALS['jai_provider_config_cache'] = $config;
}

function jai_reset_provider_config_cache(): void
{
    unset($GLOBALS['jai_provider_config_cache']);
}

function jai_bounded_integer(mixed $value, string $field, int $minimum, int $maximum): int
{
    $raw = is_int($value) ? (string)$value : (is_string($value) ? trim($value) : '');
    if ($raw === '' || preg_match('/\A\d+\z/', $raw) !== 1) {
        throw new DomainException(jai_t('Enter a valid value for %s.', $field));
    }
    $integer = (int)$raw;
    if ($integer < $minimum || $integer > $maximum) {
        throw new DomainException(jai_t('%s must be between %d and %d.', $field, $minimum, $maximum));
    }
    return $integer;
}

function jai_provider_config_from_input(array $input, array $current): array
{
    $activeProvider = strtolower(trim((string)($input['active_provider'] ?? '')));
    if (!array_key_exists($activeProvider, jai_provider_presets())) {
        throw new DomainException(jai_t('Choose a valid AI provider.'));
    }

    $current = jai_normalize_provider_config($current);
    $keys = [];
    foreach (['openai', 'gemini'] as $provider) {
        $field = $provider . '_api_key';
        $encryptedField = $field . '_encrypted';
        $keys[$encryptedField] = $current[$encryptedField];
        if (!array_key_exists($field, $input)) continue;
        $apiKey = trim((string)$input[$field]);
        if ($apiKey === '') {
            $keys[$encryptedField] = '';
            continue;
        }
        if (!jai_crypto_available()) throw new DomainException(jai_t('Credential encryption is unavailable. Configure a server master secret.'));
        $keys[$encryptedField] = jai_encrypt_api_key($apiKey);
    }

    return jai_normalize_provider_config([
        'active_provider' => $activeProvider,
        'openai_api_key_encrypted' => $keys['openai_api_key_encrypted'],
        'gemini_api_key_encrypted' => $keys['gemini_api_key_encrypted'],
        'timeout_seconds' => jai_bounded_integer($input['timeout_seconds'] ?? '', jai_t('Timeout'), 10, 120),
        'max_input_bytes' => jai_bounded_integer($input['max_input_bytes'] ?? '', jai_t('Input limit'), 1000, 524288),
        'max_output_tokens' => jai_bounded_integer($input['max_output_tokens'] ?? '', jai_t('Output token limit'), 256, 16384),
        'requests_per_minute' => jai_bounded_integer($input['requests_per_minute'] ?? '', jai_t('Rate limit'), 1, 60),
    ]);
}

function jai_save_provider_config(PDO $pdo, array $config, int $userId): bool
{
    $config = jai_normalize_provider_config($config);
    $preset = jai_provider_presets()[$config['active_provider']];
    $activeKey = $config[$config['active_provider'] . '_api_key_encrypted'];
    $activePlaintext = $activeKey === '' ? '' : (jai_decrypt_api_key($activeKey) ?? '');
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO `' . JAI_SETTINGS_TABLE . '`
            (`id`, `active_provider`, `openai_api_key_encrypted`, `gemini_api_key_encrypted`, `provider_url`, `model`, `api_key_encrypted`, `api_key_hint`, `timeout_seconds`, `max_input_bytes`, `max_output_tokens`, `requests_per_minute`, `updated_by`, `updated_at`)
            VALUES (1, :active_provider, :openai_api_key_encrypted, :gemini_api_key_encrypted, :provider_url, :model, :api_key_encrypted, :api_key_hint, :timeout_seconds, :max_input_bytes, :max_output_tokens, :requests_per_minute, :updated_by, UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE `active_provider` = VALUES(`active_provider`), `openai_api_key_encrypted` = VALUES(`openai_api_key_encrypted`),
            `gemini_api_key_encrypted` = VALUES(`gemini_api_key_encrypted`), `provider_url` = VALUES(`provider_url`), `model` = VALUES(`model`),
            `api_key_encrypted` = VALUES(`api_key_encrypted`), `api_key_hint` = VALUES(`api_key_hint`), `timeout_seconds` = VALUES(`timeout_seconds`), `max_input_bytes` = VALUES(`max_input_bytes`),
            `max_output_tokens` = VALUES(`max_output_tokens`), `requests_per_minute` = VALUES(`requests_per_minute`), `updated_by` = VALUES(`updated_by`), `updated_at` = VALUES(`updated_at`)');
        $stmt->execute([
            ':active_provider' => $config['active_provider'],
            ':openai_api_key_encrypted' => $config['openai_api_key_encrypted'],
            ':gemini_api_key_encrypted' => $config['gemini_api_key_encrypted'],
            ':provider_url' => $preset['provider_url'],
            ':model' => $preset['model'],
            ':api_key_encrypted' => $activeKey,
            ':api_key_hint' => $activePlaintext === '' ? '' : substr($activePlaintext, -4),
            ':timeout_seconds' => $config['timeout_seconds'],
            ':max_input_bytes' => $config['max_input_bytes'],
            ':max_output_tokens' => $config['max_output_tokens'],
            ':requests_per_minute' => $config['requests_per_minute'],
            ':updated_by' => $userId,
        ]);
        if ($ownsTransaction) $pdo->commit();
        jai_reset_provider_config_cache();
        return true;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}
