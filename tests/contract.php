<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$coreRoot = rtrim((string)(getenv('JAI_CORE_ROOT') ?: dirname($pluginRoot, 2) . '/jyavani.lan'), DIRECTORY_SEPARATOR);
$fixture = sys_get_temp_dir() . '/jai-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
if (!is_file($coreRoot . '/plugins/index.php')) {
    fwrite(STDERR, "Canonical Core not found at {$coreRoot}. Set JAI_CORE_ROOT.\n");
    exit(1);
}

mkdir($fixture . '/cfg/var', 0775, true);
mkdir($fixture . '/public', 0775, true);
define('BACKEND_PATH', $fixture . '/cfg');
define('PUBLIC_PATH', $fixture . '/public');
require_once $coreRoot . '/cfg/helpers/hooks.php';
require_once $coreRoot . '/plugins/index.php';

$manifest = json_decode((string)file_get_contents($pluginRoot . '/plugin.json'), true);
$check(is_array($manifest) && json_last_error() === JSON_ERROR_NONE, 'plugin.json is valid JSON');
if (is_array($manifest)) {
    $check(plugin_manifest_contract_errors($manifest) === [], 'manifest satisfies canonical permission and route contracts');
    $check(plugin_route_collision_errors($manifest) === [], 'admin routes do not collide with canonical Core routes');
    $check(($manifest['requires']['jyavani'] ?? '') === '>=2.3.139', 'manifest requires the first Core release with plugin-owned navigation icons');
    $permissions = array_column($manifest['permissions'] ?? [], null, 'key');
    $check(isset($permissions['plugin.jyavani-ai.assistant.generate'])
        && ($permissions['plugin.jyavani-ai.assistant.generate']['default_roles'] ?? []) === ['author', 'editor', 'admin']
        && ($permissions['plugin.jyavani-ai.assistant.generate']['delegable'] ?? false) === true,
        'generation permission is explicitly delegable to Content Team roles');
    $check(count($permissions) === 1 && !isset($permissions['plugin.jyavani-ai.settings.manage']),
        'provider settings use the stronger Site Owner boundary instead of a role permission');
    $pages = array_column($manifest['admin']['pages'] ?? [], null, 'route');
    $check(isset($pages['admin/tools/jyavani-ai/generate'])
        && ($pages['admin/tools/jyavani-ai/generate']['hidden'] ?? false) === true
        && ($pages['admin/tools/jyavani-ai/generate']['permission'] ?? '') === 'plugin.jyavani-ai.assistant.generate',
        'generation endpoint is a hidden permission-guarded admin route');
    $check(isset($pages['admin/tools/jyavani-ai'], $pages['admin/tools/jyavani-ai/save'])
        && ($pages['admin/tools/jyavani-ai']['site_owner'] ?? false) === true
        && ($pages['admin/tools/jyavani-ai/save']['site_owner'] ?? false) === true
        && ($pages['admin/tools/jyavani-ai/save']['hidden'] ?? false) === true,
        'provider display and save routes are centrally Site Owner-only');

    $allFilesExist = true;
    foreach ($manifest['admin']['pages'] ?? [] as $page) {
        $allFilesExist = $allFilesExist && is_file($pluginRoot . '/' . (string)($page['file'] ?? ''));
    }
    foreach ($manifest['static']['copy'] ?? [] as $copy) {
        $allFilesExist = $allFilesExist && is_file($pluginRoot . '/' . (string)($copy['from'] ?? ''));
    }
    $check($allFilesExist, 'all declared route and static source files exist');
    $published = plugin_static_copy($pluginRoot, $manifest['static']['copy'] ?? []);
    $check(($published['failed'] ?? 1) === 0 && ($published['copied'] ?? 0) === 3, 'canonical static publication accepts the editor and sidebar icon assets');
}

$bootstrap = (string)file_get_contents($pluginRoot . '/plugin.php');
$endpoint = (string)file_get_contents($pluginRoot . '/admin/generate.php');
$settings = (string)file_get_contents($pluginRoot . '/admin/save.php');
$client = (string)file_get_contents($pluginRoot . '/assets/js/editor-assistant.js');
$packageIcon = (string)file_get_contents($pluginRoot . '/icon.svg');
$sidebarIcon = (string)file_get_contents($pluginRoot . '/assets/icon-sidebar.svg');
$check(str_contains($bootstrap, "add_action('admin_head', 'jai_editor_bootstrap')")
    && str_contains($bootstrap, "current_user_can(\$pdo, JAI_PERMISSION_GENERATE)")
    && str_contains($bootstrap, 'window.JyavaniAIConfig='),
    'editor configuration is emitted only for authorized content editor requests');
$check(str_contains($client, 'window.JyavaniEditor.ready()')
    && str_contains($client, "id: 'plugin.jyavani-ai.assist'")
    && str_contains($client, 'editor.insert(requestState.html')
    && str_contains($client, 'editor.setContent(requestState.html'),
    'client uses only the public editor contract for selection and document mutations');
$check(str_contains($packageIcon, 'aria-label="Jyavani AI"')
    && str_contains($packageIcon, 'jai-ring')
    && substr_count($packageIcon, '<circle') >= 6,
    'package artwork uses the provider-neutral Jyavani circuit monogram');
$check(str_contains($sidebarIcon, 'stroke="currentColor"')
    && (($manifest['admin']['nav'][0]['icon_asset'] ?? '') === 'static/plugins/jyavani-ai/icon-sidebar.svg')
    && (($manifest['admin']['nav'][0]['icon'] ?? '') === 'zap'),
    'sidebar declares its plugin-owned icon through Core with a supported fallback');
$check(str_contains($endpoint, "adiwira_require_permission(\$pdo, JAI_PERMISSION_GENERATE, true)")
    && str_contains($endpoint, 'jai_authorize_content_request')
    && str_contains($endpoint, 'jai_consume_rate_limit'),
    'endpoint layers plugin permission, resource authorization, and rate limiting');
$check(str_contains($settings, 'adiwira_require_site_owner($pdo, false)')
    && str_contains($settings, 'adiwira_csrf_validate')
    && !str_contains($settings, 'current_password')
    && str_contains($settings, 'authorization_audit'),
    'provider settings require Site Owner and CSRF while audit metadata excludes credentials');

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target) && !is_link($target)) $remove($target);
        else @unlink($target);
    }
    @rmdir($path);
};
$remove($fixture);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " contract assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
