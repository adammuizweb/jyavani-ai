<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$GLOBALS['jai_test_permissions'] = [];
$GLOBALS['jai_test_status'] = null;
function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool
{
    return ($GLOBALS['jai_test_permissions'][$permission] ?? false) === true;
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return $GLOBALS['jai_test_status'] ?? $value;
}
function content_schedule_editor_status(string $status, array $content): string
{
    return $status === 'draft' && !empty($content['publish_at_utc']) ? 'scheduled' : $status;
}
require_once $pluginRoot . '/includes/authorization.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, type TEXT, created_by INTEGER, status TEXT, publish_at_utc TEXT NULL, is_deleted INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO posts (id, type, created_by, status, publish_at_utc, is_deleted) VALUES
    (1, 'article', 7, 'draft', NULL, 0),
    (2, 'article', 7, 'draft', '2026-12-01 00:00:00', 0),
    (3, 'page', 8, 'published', NULL, 0),
    (4, 'page', 8, 'draft', NULL, 1)");

$GLOBALS['jai_test_permissions'] = ['core.posts.create' => true];
$add = jai_authorize_content_request($pdo, 7, 'article', 'add', null);
$check($add['owner_id'] === 7 && $add['status'] === 'draft', 'article creation requires the matching Core create permission');

$GLOBALS['jai_test_permissions'] = ['core.posts.update' => true, 'core.posts.publish' => false];
$draft = jai_authorize_content_request($pdo, 7, 'article', 'edit', 1);
$check($draft['owner_id'] === 7 && $draft['status'] === 'draft', 'editable draft passes owner-aware update authorization');
try {
    jai_authorize_content_request($pdo, 7, 'article', 'edit', 2);
    $scheduledDenied = false;
} catch (DomainException) {
    $scheduledDenied = true;
}
$check($scheduledDenied, 'scheduled draft also requires the Core publish permission');

$GLOBALS['jai_test_permissions'] = ['core.pages.update' => true, 'core.pages.publish' => false];
$GLOBALS['jai_test_status'] = 'private';
try {
    jai_authorize_content_request($pdo, 8, 'page', 'edit', 3);
    $workflowDenied = false;
} catch (DomainException) {
    $workflowDenied = true;
}
$check($workflowDenied, 'workflow-filtered non-draft content requires publish permission');

$GLOBALS['jai_test_status'] = null;
$GLOBALS['jai_test_permissions']['core.pages.publish'] = true;
$published = jai_authorize_content_request($pdo, 8, 'page', 'edit', 3);
$check($published['status'] === 'published', 'authorized publisher may use AI on published Page content');
try {
    jai_authorize_content_request($pdo, 8, 'page', 'edit', 4);
    $deletedDenied = false;
} catch (DomainException) {
    $deletedDenied = true;
}
$check($deletedDenied, 'deleted resources fail closed');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " authorization assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
