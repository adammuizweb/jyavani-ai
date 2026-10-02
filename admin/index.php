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
<section class="jai-settings">
  <header class="jai-settings__hero">
    <div class="jai-settings__heading">
      <span class="jai-settings__mark" aria-hidden="true">
        <svg viewBox="0 0 32 32" fill="none"><path d="M16 3.5l2.4 7.1L25.5 13l-7.1 2.4-2.4 7.1-2.4-7.1L6.5 13l7.1-2.4L16 3.5Z"/><path d="m24.5 20.5 1.1 3.4 3.4 1.1-3.4 1.1-1.1 3.4-1.1-3.4L20 25l3.4-1.1 1.1-3.4Z"/></svg>
      </span>
      <div>
        <p class="jai-settings__eyebrow"><?= jai_h(jai_t('Editor integration')) ?></p>
        <h2><?= jai_h(jai_t('Jyavani AI')) ?></h2>
        <p><?= jai_h(jai_t('AI-assisted drafting for Article and Page editors. Generated content is never saved automatically.')) ?></p>
      </div>
    </div>
    <span class="jai-status <?= jai_provider_is_ready() ? 'jai-status--ready' : 'jai-status--missing' ?>">
      <span class="jai-status__dot" aria-hidden="true"></span>
      <?= jai_h(jai_provider_is_ready() ? jai_t('Provider ready') : jai_t('Provider not configured')) ?>
    </span>
  </header>

  <div class="jai-settings__grid">
    <article class="jai-settings__panel jai-settings__panel--config">
      <div class="jai-settings__panel-head">
        <span class="jai-settings__panel-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2.75a3.25 3.25 0 0 0-3.25 3.25v1H7a3.25 3.25 0 0 0-3.25 3.25v3.5A3.25 3.25 0 0 0 7 17h1.75v1A3.25 3.25 0 0 0 12 21.25 3.25 3.25 0 0 0 15.25 18v-1H17a3.25 3.25 0 0 0 3.25-3.25v-3.5A3.25 3.25 0 0 0 17 7h-1.75V6A3.25 3.25 0 0 0 12 2.75Z"/><path d="M9 12h6M12 9v6"/></svg></span>
        <h3><?= jai_h(jai_t('Effective provider configuration')) ?></h3>
      </div>
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

    <article class="jai-settings__panel jai-settings__panel--usage">
      <div class="jai-settings__panel-head">
        <span class="jai-settings__panel-icon jai-settings__panel-icon--usage" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 19.25V13m5.33 6.25V8.5m5.34 10.75V11m5.33 8.25V4.75"/></svg></span>
        <h3><?= jai_h(jai_t('Last 24 hours')) ?></h3>
      </div>
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
    <article class="jai-settings__panel jai-settings__panel--form">
      <div class="jai-settings__form-head">
        <span class="jai-settings__step" aria-hidden="true">01</span>
        <div>
          <h3><?= jai_h(jai_t('Dashboard provider settings')) ?></h3>
          <p class="jai-settings__note"><?= jai_h(jai_t('Choose the active provider and save each API key once. Stored keys remain encrypted in the database and are visible here only to the Site Owner.')) ?></p>
        </div>
      </div>
      <?php if (!jai_crypto_available()): ?>
        <p class="adam-notice adam-notice--warning"><?= jai_h(jai_t('Credential encryption is unavailable. Configure JYAVANI_AI_SECRET_KEY, APP_KEY, or SESSION_SECRET with at least 32 characters.')) ?></p>
      <?php endif; ?>
      <div class="jai-form-grid">
        <div class="jai-form-section jai-field--wide">
          <label class="jai-field jai-field--wide"><span><?= jai_h(jai_t('Active provider')) ?></span><select name="active_provider" required>
            <?php foreach ($presets as $provider => $preset): ?>
              <option value="<?= jai_h($provider) ?>" <?= $provider === $activeProvider ? 'selected' : '' ?>><?= jai_h($preset['label']) ?> - <?= jai_h($preset['model']) ?></option>
            <?php endforeach; ?>
          </select></label>
          <label class="jai-field jai-field--wide" data-jai-provider-key="openai" <?= $activeProvider === 'openai' ? '' : 'hidden' ?>><span><?= jai_h(jai_t('OpenAI API key')) ?></span><input type="text" name="openai_api_key" maxlength="4096" autocomplete="off" spellcheck="false" value="<?= jai_h($storedKeys['openai']) ?>" <?= jai_crypto_available() ? '' : 'disabled' ?>></label>
          <label class="jai-field jai-field--wide" data-jai-provider-key="gemini" <?= $activeProvider === 'gemini' ? '' : 'hidden' ?>><span><?= jai_h(jai_t('Gemini API key')) ?></span><input type="text" name="gemini_api_key" maxlength="4096" autocomplete="off" spellcheck="false" value="<?= jai_h($storedKeys['gemini']) ?>" <?= jai_crypto_available() ? '' : 'disabled' ?>></label>
        </div>
        <div class="jai-settings__shared jai-field--wide">
          <strong><?= jai_h(jai_t('Shared request settings')) ?></strong>
          <span><?= jai_h(jai_t('These limits apply to both ChatGPT and Gemini.')) ?></span>
        </div>
        <div class="jai-form-section jai-form-section--limits jai-field--wide">
          <label class="jai-field"><span><?= jai_h(jai_t('Timeout (seconds)')) ?></span><input type="number" name="timeout_seconds" min="10" max="120" required value="<?= (int)$stored['timeout_seconds'] ?>"></label>
          <label class="jai-field"><span><?= jai_h(jai_t('Input limit (bytes)')) ?></span><input type="number" name="max_input_bytes" min="1000" max="524288" required value="<?= (int)$stored['max_input_bytes'] ?>"></label>
          <label class="jai-field"><span><?= jai_h(jai_t('Output token limit')) ?></span><input type="number" name="max_output_tokens" min="256" max="16384" required value="<?= (int)$stored['max_output_tokens'] ?>"></label>
          <label class="jai-field"><span><?= jai_h(jai_t('Requests per minute per user')) ?></span><input type="number" name="requests_per_minute" min="1" max="60" required value="<?= (int)$stored['requests_per_minute'] ?>"></label>
        </div>
      </div>
      <div class="jai-settings__actions"><button class="btn btn-primary jai-settings__submit" type="submit"><?= jai_h(jai_t('Save provider settings')) ?></button></div>
    </article>
  </form>

  <article class="jai-settings__panel jai-settings__setup">
    <div class="jai-settings__form-head">
      <span class="jai-settings__step" aria-hidden="true">02</span>
      <div>
        <h3><?= jai_h(jai_t('Managed deployment overrides')) ?></h3>
        <p><?= jai_h(jai_t('Environment variables remain available for managed deployments and take priority over dashboard values.')) ?></p>
      </div>
    </div>
    <pre><code>JYAVANI_AI_OPENAI_API_KEY=...
JYAVANI_AI_GEMINI_API_KEY=...
JYAVANI_AI_TIMEOUT_SECONDS=60
JYAVANI_AI_MAX_INPUT_BYTES=200000
JYAVANI_AI_MAX_OUTPUT_TOKENS=4000
JYAVANI_AI_REQUESTS_PER_MINUTE=10</code></pre>
  </article>
</section>
