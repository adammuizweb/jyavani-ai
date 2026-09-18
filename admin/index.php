<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    http_response_code(404);
    exit;
}
[$uid] = adiwira_require_site_owner($pdo, false);

$stored = jai_load_provider_config($pdo);
$presets = jai_provider_presets();
$activeProvider = jai_active_provider();
$activePreset = $presets[$activeProvider];
$storedKeys = [];
foreach (array_keys($presets) as $provider) {
    $encrypted = (string)($stored[$provider . '_api_key_encrypted'] ?? '');
    $storedKeys[$provider] = $encrypted === '' ? '' : (jai_decrypt_api_key($encrypted) ?? '');
}
$overrides = jai_environment_overrides();
$keySource = jai_provider_key_source();
$urlParts = parse_url(jai_provider_url());
$providerHost = is_array($urlParts) ? (string)($urlParts['host'] ?? '') : '';
$usage = ['requests' => 0, 'successes' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
try {
    $stmt = $pdo->query('SELECT COUNT(*) AS requests, SUM(`status` = \'success\') AS successes, COALESCE(SUM(`input_tokens`), 0) AS input_tokens, COALESCE(SUM(`output_tokens`), 0) AS output_tokens FROM `' . JAI_USAGE_TABLE . '` WHERE `created_at` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        foreach ($usage as $key => $value) $usage[$key] = max(0, (int)($row[$key] ?? 0));
    }
} catch (Throwable $error) {
    $usageUnavailable = true;
}

$keyLabel = match ($keySource) {
    'environment' => jai_t('Configured in environment'),
    'dashboard' => jai_t('Configured in dashboard'),
    'unavailable' => jai_t('Stored key cannot be decrypted. Re-enter it.'),
    default => jai_t('Missing'),
};
$base = rtrim((string)ADMIN_BASE_PATH, '/');
?>
<section class="adam-card jai-settings">
  <div class="jai-settings__heading">
    <div>
      <p class="jai-settings__eyebrow"><?= jai_h(jai_t('Editor integration')) ?></p>
      <h2><?= jai_h(jai_t('Jyavani AI')) ?></h2>
      <p><?= jai_h(jai_t('AI-assisted drafting for Article and Page editors. Generated content is never saved automatically.')) ?></p>
    </div>
    <span class="jai-status <?= jai_provider_is_ready() ? 'jai-status--ready' : 'jai-status--missing' ?>">
      <?= jai_h(jai_provider_is_ready() ? jai_t('Provider ready') : jai_t('Provider not configured')) ?>
    </span>
  </div>

  <div class="jai-settings__grid">
    <article class="jai-settings__panel">
      <h3><?= jai_h(jai_t('Effective provider configuration')) ?></h3>
      <dl class="jai-definition-list">
        <div><dt><?= jai_h(jai_t('Active provider')) ?></dt><dd><?= jai_h($activePreset['label']) ?></dd></div>
        <div><dt><?= jai_h(jai_t('API key')) ?></dt><dd><?= jai_h($keyLabel) ?></dd></div>
        <div><dt><?= jai_h(jai_t('Provider host')) ?></dt><dd><code><?= jai_h($providerHost !== '' ? $providerHost : jai_t('Invalid')) ?></code></dd></div>
        <div><dt><?= jai_h(jai_t('Model')) ?></dt><dd><code><?= jai_h(jai_provider_model()) ?></code></dd></div>
        <div><dt><?= jai_h(jai_t('Timeout')) ?></dt><dd><?= jai_h(jai_t('%d seconds', jai_provider_timeout())) ?></dd></div>
        <div><dt><?= jai_h(jai_t('Input limit')) ?></dt><dd><?= number_format(jai_max_input_bytes()) ?> bytes</dd></div>
        <div><dt><?= jai_h(jai_t('Output token limit')) ?></dt><dd><?= number_format(jai_max_output_tokens()) ?></dd></div>
        <div><dt><?= jai_h(jai_t('Rate limit')) ?></dt><dd><?= jai_h(jai_t('%d requests per minute per user', jai_requests_per_minute())) ?></dd></div>
      </dl>
      <?php if ($overrides !== []): ?>
        <p class="jai-settings__note"><?= jai_h(jai_t('Environment values override matching dashboard settings: %s', implode(', ', $overrides))) ?></p>
      <?php endif; ?>
      <?php if (jai_environment_override_errors() !== []): ?>
        <p class="adam-notice adam-notice--warning"><?= jai_h(jai_t('Invalid environment overrides disable provider requests: %s', implode(', ', jai_environment_override_errors()))) ?></p>
      <?php endif; ?>
    </article>

    <article class="jai-settings__panel">
      <h3><?= jai_h(jai_t('Last 24 hours')) ?></h3>
      <?php if (!empty($usageUnavailable)): ?>
        <p class="adam-notice adam-notice--warning"><?= jai_h(jai_t('Usage storage is unavailable. Reactivate the plugin to run its migrations.')) ?></p>
      <?php else: ?>
        <div class="jai-metrics">
          <div><strong><?= number_format($usage['requests']) ?></strong><span><?= jai_h(jai_t('Requests')) ?></span></div>
          <div><strong><?= number_format($usage['successes']) ?></strong><span><?= jai_h(jai_t('Successful')) ?></span></div>
          <div><strong><?= number_format($usage['input_tokens']) ?></strong><span><?= jai_h(jai_t('Input tokens')) ?></span></div>
          <div><strong><?= number_format($usage['output_tokens']) ?></strong><span><?= jai_h(jai_t('Output tokens')) ?></span></div>
        </div>
      <?php endif; ?>
    </article>
  </div>

  <form class="jai-settings__form" method="post" action="<?= jai_h($base . '/?page=admin/tools/jyavani-ai/save') ?>" data-jai-provider-settings data-jai-crypto-ready="<?= jai_crypto_available() ? '1' : '0' ?>" data-unsaved-guard>
    <input type="hidden" name="csrf_token" value="<?= jai_h(csrf_token()) ?>">
    <article class="jai-settings__panel">
      <h3><?= jai_h(jai_t('Dashboard provider settings')) ?></h3>
      <p class="jai-settings__note"><?= jai_h(jai_t('Choose the active provider and save each API key once. Stored keys remain encrypted in the database and are visible here only to the Site Owner.')) ?></p>
      <?php if (!jai_crypto_available()): ?>
        <p class="adam-notice adam-notice--warning"><?= jai_h(jai_t('Credential encryption is unavailable. Configure JYAVANI_AI_SECRET_KEY, APP_KEY, or SESSION_SECRET with at least 32 characters.')) ?></p>
      <?php endif; ?>
      <div class="jai-form-grid">
        <label class="jai-field jai-field--wide"><span><?= jai_h(jai_t('Active provider')) ?></span><select name="active_provider" required>
          <?php foreach ($presets as $provider => $preset): ?>
            <option value="<?= jai_h($provider) ?>" <?= $provider === $activeProvider ? 'selected' : '' ?>><?= jai_h($preset['label']) ?> - <?= jai_h($preset['model']) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label class="jai-field jai-field--wide" data-jai-provider-key="openai" <?= $activeProvider === 'openai' ? '' : 'hidden' ?>><span><?= jai_h(jai_t('OpenAI API key')) ?></span><input type="text" name="openai_api_key" maxlength="4096" autocomplete="off" spellcheck="false" value="<?= jai_h($storedKeys['openai']) ?>" <?= jai_crypto_available() ? '' : 'disabled' ?>></label>
        <label class="jai-field jai-field--wide" data-jai-provider-key="gemini" <?= $activeProvider === 'gemini' ? '' : 'hidden' ?>><span><?= jai_h(jai_t('Gemini API key')) ?></span><input type="text" name="gemini_api_key" maxlength="4096" autocomplete="off" spellcheck="false" value="<?= jai_h($storedKeys['gemini']) ?>" <?= jai_crypto_available() ? '' : 'disabled' ?>></label>
        <div class="jai-settings__shared jai-field--wide">
          <strong><?= jai_h(jai_t('Shared request settings')) ?></strong>
          <span><?= jai_h(jai_t('These limits apply to both ChatGPT and Gemini.')) ?></span>
        </div>
        <label class="jai-field"><span><?= jai_h(jai_t('Timeout (seconds)')) ?></span><input type="number" name="timeout_seconds" min="10" max="120" required value="<?= (int)$stored['timeout_seconds'] ?>"></label>
        <label class="jai-field"><span><?= jai_h(jai_t('Input limit (bytes)')) ?></span><input type="number" name="max_input_bytes" min="1000" max="524288" required value="<?= (int)$stored['max_input_bytes'] ?>"></label>
        <label class="jai-field"><span><?= jai_h(jai_t('Output token limit')) ?></span><input type="number" name="max_output_tokens" min="256" max="16384" required value="<?= (int)$stored['max_output_tokens'] ?>"></label>
        <label class="jai-field"><span><?= jai_h(jai_t('Requests per minute per user')) ?></span><input type="number" name="requests_per_minute" min="1" max="60" required value="<?= (int)$stored['requests_per_minute'] ?>"></label>
      </div>
      <button class="btn btn-primary" type="submit"><?= jai_h(jai_t('Save provider settings')) ?></button>
    </article>
  </form>

  <article class="jai-settings__panel jai-settings__setup">
    <h3><?= jai_h(jai_t('Managed deployment overrides')) ?></h3>
    <p><?= jai_h(jai_t('Environment variables remain available for managed deployments and take priority over dashboard values.')) ?></p>
    <pre><code>JYAVANI_AI_OPENAI_API_KEY=...
JYAVANI_AI_GEMINI_API_KEY=...
JYAVANI_AI_TIMEOUT_SECONDS=60
JYAVANI_AI_MAX_INPUT_BYTES=200000
JYAVANI_AI_MAX_OUTPUT_TOKENS=4000
JYAVANI_AI_REQUESTS_PER_MINUTE=10</code></pre>
  </article>
</section>
