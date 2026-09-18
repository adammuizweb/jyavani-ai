<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

define('JAI_SETTINGS_TABLE', 'jai_provider_settings');
require_once $root . '/includes/settings.php';
require_once $root . '/includes/config.php';

$oldSecret = getenv('JYAVANI_AI_SECRET_KEY');
$oldApiKey = getenv('JYAVANI_AI_API_KEY');
$oldOpenAiKey = getenv('JYAVANI_AI_OPENAI_API_KEY');
$oldGeminiKey = getenv('JYAVANI_AI_GEMINI_API_KEY');
$oldRate = getenv('JYAVANI_AI_REQUESTS_PER_MINUTE');
putenv('JYAVANI_AI_SECRET_KEY=' . str_repeat('contract-secret-', 4));
putenv('JYAVANI_AI_API_KEY');
putenv('JYAVANI_AI_OPENAI_API_KEY');
putenv('JYAVANI_AI_GEMINI_API_KEY');

$plain = 'sk-contract-secret-1234';
$encryptedOne = jai_encrypt_api_key($plain);
$encryptedTwo = jai_encrypt_api_key($plain);
$check(str_starts_with($encryptedOne, 'v1:') && !str_contains($encryptedOne, $plain), 'API keys use a versioned envelope without plaintext');
$check($encryptedOne !== $encryptedTwo, 'API key encryption uses a fresh nonce');
$check(jai_decrypt_api_key($encryptedOne) === $plain, 'encrypted API keys decrypt with the configured master secret');
$tampered = substr($encryptedOne, 0, -1) . (str_ends_with($encryptedOne, 'A') ? 'B' : 'A');
$check(jai_decrypt_api_key($tampered) === null, 'tampered API key ciphertext fails authentication');

$current = jai_default_provider_config();
$current['openai_api_key_encrypted'] = $encryptedOne;
$current['gemini_api_key_encrypted'] = jai_encrypt_api_key('gemini-existing-1234');
$input = [
    'active_provider' => 'gemini',
    'openai_api_key' => 'sk-replacement-9876',
    'gemini_api_key' => 'gemini-replacement-5678',
    'timeout_seconds' => '45',
    'max_input_bytes' => '120000',
    'max_output_tokens' => '2048',
    'requests_per_minute' => '8',
];
$configured = jai_provider_config_from_input($input, $current);
$check($configured['active_provider'] === 'gemini'
    && jai_decrypt_api_key($configured['openai_api_key_encrypted']) === 'sk-replacement-9876'
    && jai_decrypt_api_key($configured['gemini_api_key_encrypted']) === 'gemini-replacement-5678',
    'OpenAI and Gemini keys are encrypted and stored independently');
$retained = jai_provider_config_from_input(array_diff_key($input, ['openai_api_key' => true]), $current);
$check($retained['openai_api_key_encrypted'] === $encryptedOne, 'an omitted provider key retains its existing ciphertext');
$cleared = jai_provider_config_from_input(array_merge($input, ['openai_api_key' => '']), $current);
$check($cleared['openai_api_key_encrypted'] === '', 'an explicitly empty provider key removes that credential');
$presets = jai_provider_presets();
$check($presets['openai']['provider_url'] === 'https://api.openai.com/v1/chat/completions'
    && $presets['gemini']['provider_url'] === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
    'provider endpoints are fixed to the OpenAI and Gemini presets');

$GLOBALS['jai_provider_config_cache'] = $configured;
$check(jai_active_provider() === 'gemini'
    && jai_provider_model() === 'models/gemini-flash-lite-latest'
    && jai_provider_key_source() === 'dashboard'
    && jai_provider_api_key() === 'gemini-replacement-5678',
    'the selected preset supplies its fixed model and matching dashboard key');
putenv('JYAVANI_AI_GEMINI_API_KEY=gemini-environment-override');
$check(jai_provider_key_source() === 'environment' && jai_provider_api_key() === 'gemini-environment-override', 'provider-specific environment keys override dashboard storage');
putenv('JYAVANI_AI_GEMINI_API_KEY');
putenv('JYAVANI_AI_API_KEY=sk-environment-override');
$check(jai_provider_key_source() === 'environment' && jai_provider_api_key() === 'sk-environment-override', 'legacy environment API key retains explicit priority');
putenv('JYAVANI_AI_API_KEY');
putenv('JYAVANI_AI_REQUESTS_PER_MINUTE=invalid');
$check(in_array('JYAVANI_AI_REQUESTS_PER_MINUTE', jai_environment_override_errors(), true), 'malformed environment limits fail closed instead of silently acting as dashboard values');
if ($oldRate === false) putenv('JYAVANI_AI_REQUESTS_PER_MINUTE'); else putenv('JYAVANI_AI_REQUESTS_PER_MINUTE=' . $oldRate);

$migration = (string)file_get_contents($root . '/migrations/0002-provider-settings.sql');
$presetMigration = (string)file_get_contents($root . '/migrations/0003-provider-presets.php');
$save = (string)file_get_contents($root . '/admin/save.php');
$settingsPage = (string)file_get_contents($root . '/admin/index.php');
$settingsClient = (string)file_get_contents($root . '/assets/js/editor-assistant.js');
$settingsStyles = (string)file_get_contents($root . '/assets/css/editor-assistant.css');
$check(str_contains($migration, '`api_key_encrypted` TEXT') && !str_contains($migration, '`api_key` '), 'provider settings schema stores ciphertext rather than a plaintext key column');
$check(str_contains($presetMigration, 'openai_api_key_encrypted') && str_contains($presetMigration, 'gemini_api_key_encrypted'), 'append-only migration adds independent encrypted provider credentials');
$check(str_contains($settingsPage, 'value="<?= jai_h($storedKeys[\'openai\']) ?>"')
    && str_contains($settingsPage, 'value="<?= jai_h($storedKeys[\'gemini\']) ?>"'),
    'the Site Owner settings page renders both decrypted keys for editing');
$check(!str_contains($save, 'current_password') && !str_contains($save, 'jai_reauthenticate')
    && !str_contains($settingsPage, 'current_password'),
    'saving provider settings requires Site Owner access and CSRF but no password reauthentication');
$check(str_contains($settingsPage, 'data-jai-provider-key="openai"')
    && str_contains($settingsPage, 'data-jai-provider-key="gemini"')
    && str_contains($settingsClient, 'function syncProviderKey()')
    && str_contains($settingsClient, 'input.disabled = !active || !cryptoReady')
    && str_contains($settingsStyles, '.jai-field[hidden]{display:none}'),
    'the active provider controls which editable API key is visible and submitted');
$check(str_contains($settingsPage, "jai_t('Shared request settings')")
    && str_contains($settingsPage, "jai_t('These limits apply to both ChatGPT and Gemini.')"),
    'the settings page identifies request limits as shared by both providers');
$check(str_contains($save, "'configured_providers'") && !str_contains($save, "'api_key' =>"), 'settings audit records provider state without credential values');

if ($oldSecret === false) putenv('JYAVANI_AI_SECRET_KEY'); else putenv('JYAVANI_AI_SECRET_KEY=' . $oldSecret);
if ($oldApiKey === false) putenv('JYAVANI_AI_API_KEY'); else putenv('JYAVANI_AI_API_KEY=' . $oldApiKey);
if ($oldOpenAiKey === false) putenv('JYAVANI_AI_OPENAI_API_KEY'); else putenv('JYAVANI_AI_OPENAI_API_KEY=' . $oldOpenAiKey);
if ($oldGeminiKey === false) putenv('JYAVANI_AI_GEMINI_API_KEY'); else putenv('JYAVANI_AI_GEMINI_API_KEY=' . $oldGeminiKey);
unset($GLOBALS['jai_provider_config_cache']);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " settings assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
