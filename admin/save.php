<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    http_response_code(404);
    exit;
}
[$uid] = adiwira_require_site_owner($pdo, false);

$returnTo = rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/tools/jyavani-ai';
if (!function_exists('adiwira_redirect_with_flash') && defined('DASH_PATH')) {
    require_once rtrim((string)DASH_PATH, DIRECTORY_SEPARATOR) . '/admin/_notify.php';
}
$redirect = static function (string $type, string $message) use ($returnTo): never {
    if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($returnTo, $type, $message);
    header('Location: ' . $returnTo, true, 303);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(404);
    exit;
}
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
    $redirect('error', jai_t('Invalid CSRF token.'));
}

try {
    $current = jai_load_provider_config($pdo);
    $config = jai_provider_config_from_input($_POST, $current);
    if (!jai_save_provider_config($pdo, $config, $uid)) throw new RuntimeException('Provider settings write failed.');

    if (function_exists('authorization_audit')) {
        $configuredProviders = [];
        foreach (['openai', 'gemini'] as $provider) {
            if ((string)$config[$provider . '_api_key_encrypted'] !== '') $configuredProviders[] = $provider;
        }
        authorization_audit($pdo, 'jyavani_ai.settings.updated', $uid, null, 'jyavani-ai', null, [
            'active_provider' => $config['active_provider'],
            'provider_host' => jai_provider_host(),
            'model' => jai_provider_model(),
            'configured_providers' => $configuredProviders,
            'environment_overrides' => array_values(jai_environment_overrides()),
        ]);
    }
    $redirect('success', jai_t('Jyavani AI provider settings saved.'));
} catch (DomainException $error) {
    $redirect('error', $error->getMessage());
} catch (Throwable $error) {
    $redirect('error', jai_t('Jyavani AI provider settings could not be saved.'));
}
