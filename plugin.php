<?php
declare(strict_types=1);

if (!defined('PLUGIN_SYSTEM_LOADED')) return;

const JAI_VERSION = '0.4.1';
const JAI_PERMISSION_GENERATE = 'plugin.jyavani-ai.assistant.generate';
const JAI_RATE_LIMIT_TABLE = 'jai_rate_limits';
const JAI_USAGE_TABLE = 'jai_usage_events';
const JAI_SETTINGS_TABLE = 'jai_provider_settings';

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/provider.php';
require_once __DIR__ . '/includes/usage.php';
require_once __DIR__ . '/includes/authorization.php';

function jai_is_content_editor_request(): bool
{
    $page = trim((string)($_GET['page'] ?? ''), '/');
    return in_array($page, [
        'admin/posts/add',
        'admin/posts/edit',
        'admin/pages/add',
        'admin/pages/edit',
    ], true);
}

function jai_current_user_is_site_owner(PDO $pdo): bool
{
    if (!function_exists('authorization_actor')) return false;
    $actor = authorization_actor($pdo);
    return is_array($actor) && ($actor['is_site_owner'] ?? false) === true;
}

function jai_editor_bootstrap(): void
{
    if (!jai_is_content_editor_request()) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || !function_exists('current_user_can') || !current_user_can($pdo, JAI_PERMISSION_GENERATE)) return;

    $canManage = jai_current_user_is_site_owner($pdo);
    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/dashboard';
    $config = [
        'version' => JAI_VERSION,
        'endpoint' => $base . '/?page=admin/tools/jyavani-ai/generate&action=generate',
        'settingsUrl' => $canManage ? $base . '/?page=admin/tools/jyavani-ai' : '',
        'providerReady' => jai_provider_is_ready(),
        'providerHost' => jai_provider_host(),
        'requestTimeoutMs' => (jai_provider_timeout() + 10) * 1000,
        'i18n' => jai_editor_i18n(),
    ];
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    echo '<script>window.JyavaniAIConfig=' . json_encode($config, $flags | JSON_THROW_ON_ERROR) . ';</script>' . PHP_EOL;
}

function jai_uninstall(string $name): void
{
    if ($name !== 'jyavani-ai') return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $pdo->exec('DROP TABLE IF EXISTS `' . JAI_SETTINGS_TABLE . '`');
    $pdo->exec('DROP TABLE IF EXISTS `' . JAI_USAGE_TABLE . '`');
    $pdo->exec('DROP TABLE IF EXISTS `' . JAI_RATE_LIMIT_TABLE . '`');
}

add_action('admin_head', 'jai_editor_bootstrap');
add_action('plugin_uninstall', 'jai_uninstall');
