<?php
declare(strict_types=1);

function jai_consume_rate_limit(PDO $pdo, int $userId): array
{
    $sql = 'INSERT INTO `' . JAI_RATE_LIMIT_TABLE . '` (`user_id`, `window_started_at`, `request_count`, `updated_at`)
        VALUES (:user_id, UTC_TIMESTAMP(6), LAST_INSERT_ID(1), UTC_TIMESTAMP(6))
        ON DUPLICATE KEY UPDATE
          `request_count` = LAST_INSERT_ID(IF(`window_started_at` < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), 1, `request_count` + 1)),
          `window_started_at` = IF(`window_started_at` < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), UTC_TIMESTAMP(6), `window_started_at`),
          `updated_at` = UTC_TIMESTAMP(6)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $count = (int)$pdo->lastInsertId();
    if ($count <= 0) throw new RuntimeException('Unable to verify AI request rate.');
    return [
        'allowed' => $count <= jai_requests_per_minute(),
        'count' => $count,
        'limit' => jai_requests_per_minute(),
    ];
}

function jai_record_usage(PDO $pdo, array $event): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO `' . JAI_USAGE_TABLE . '`
            (`user_id`, `resource_type`, `resource_id`, `operation`, `model`, `input_bytes`, `output_bytes`, `input_tokens`, `output_tokens`, `duration_ms`, `status`, `created_at`)
            VALUES (:user_id, :resource_type, :resource_id, :operation, :model, :input_bytes, :output_bytes, :input_tokens, :output_tokens, :duration_ms, :status, UTC_TIMESTAMP(6))');
        $stmt->execute([
            ':user_id' => (int)($event['user_id'] ?? 0),
            ':resource_type' => substr((string)($event['resource_type'] ?? ''), 0, 20),
            ':resource_id' => isset($event['resource_id']) ? (int)$event['resource_id'] : null,
            ':operation' => substr((string)($event['operation'] ?? ''), 0, 32),
            ':model' => substr((string)($event['model'] ?? ''), 0, 191),
            ':input_bytes' => max(0, (int)($event['input_bytes'] ?? 0)),
            ':output_bytes' => max(0, (int)($event['output_bytes'] ?? 0)),
            ':input_tokens' => max(0, (int)($event['input_tokens'] ?? 0)),
            ':output_tokens' => max(0, (int)($event['output_tokens'] ?? 0)),
            ':duration_ms' => max(0, (int)($event['duration_ms'] ?? 0)),
            ':status' => in_array(($event['status'] ?? ''), ['success', 'failed'], true) ? $event['status'] : 'failed',
        ]);
    } catch (Throwable $error) {
        error_log('[jyavani-ai] Unable to record redacted usage metadata.');
    }
}
