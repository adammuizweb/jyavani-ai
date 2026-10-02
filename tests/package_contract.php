<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$output = sys_get_temp_dir() . '/jyavani-ai-package-contract-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.zip';
$argv = [$root . '/tools/build-package.php', $output];
ob_start();
require $root . '/tools/build-package.php';
$builderOutput = trim((string)ob_get_clean());
$result = json_decode($builderOutput, true);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$check(is_array($result) && ($result['version'] ?? '') === '0.4.1', 'package builder reports the manifest version');
$check(is_file($output) && hash_file('sha256', $output) === ($result['sha256'] ?? ''), 'package checksum matches the published archive');

$zip = new ZipArchive();
$opened = $zip->open($output) === true;
$check($opened, 'package can be reopened');
$entries = [];
if ($opened) {
    for ($index = 0; $index < $zip->numFiles; $index++) $entries[] = (string)$zip->getNameIndex($index);
    $manifest = json_decode((string)$zip->getFromName('plugin.json'), true);
    $check(($manifest['name'] ?? '') === 'jyavani-ai' && ($manifest['version'] ?? '') === '0.4.1', 'archive contains the expected flat manifest');
    $check(in_array('admin/generate.php', $entries, true)
        && in_array('admin/save.php', $entries, true)
        && in_array('assets/icon-sidebar.svg', $entries, true)
        && in_array('assets/js/editor-assistant.js', $entries, true)
        && in_array('includes/settings.php', $entries, true)
        && in_array('migrations/0001-ai-usage.sql', $entries, true)
        && in_array('migrations/0002-provider-settings.sql', $entries, true)
        && in_array('migrations/0003-provider-presets.php', $entries, true),
        'archive contains endpoints, client, encrypted settings, and migration files');
    $check(!array_filter($entries, static fn(string $entry): bool => str_starts_with($entry, 'tests/') || str_starts_with($entry, 'tools/') || str_contains($entry, '.git')),
        'archive excludes tests, tooling, and repository metadata');
    $zip->close();
}
@unlink($output);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " package assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
