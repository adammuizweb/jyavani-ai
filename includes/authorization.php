<?php
declare(strict_types=1);

function jai_authorize_content_request(PDO $pdo, int $userId, string $resourceType, string $operation, ?int $resourceId): array
{
    $permissions = $resourceType === 'article'
        ? ['create' => 'core.posts.create', 'update' => 'core.posts.update', 'publish' => 'core.posts.publish']
        : ['create' => 'core.pages.create', 'update' => 'core.pages.update', 'publish' => 'core.pages.publish'];

    if ($operation === 'add') {
        if ($resourceId !== null || !user_can($pdo, $userId, $permissions['create'], ['owner_id' => $userId])) {
            throw new DomainException('Content access denied.');
        }
        return ['owner_id' => $userId, 'status' => 'draft'];
    }

    if ($operation !== 'edit' || $resourceId === null || $resourceId <= 0) {
        throw new DomainException('Invalid content context.');
    }
    $type = $resourceType === 'article' ? 'article' : 'page';
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id AND type = :type AND is_deleted = 0 LIMIT 1');
    $stmt->execute([':id' => $resourceId, ':type' => $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new DomainException('Content access denied.');
    $statusHook = $resourceType === 'article' ? 'admin_post_editor_status' : 'admin_page_editor_status';
    $editorStatus = apply_filters($statusHook, (string)($row['status'] ?? 'draft'), $row, $pdo);
    if (!is_string($editorStatus) || !in_array($editorStatus, ['draft', 'published', 'private'], true)) {
        throw new DomainException('Content access denied.');
    }
    $row['status'] = function_exists('content_schedule_editor_status')
        ? content_schedule_editor_status($editorStatus, $row)
        : $editorStatus;
    $ownerId = (int)($row['created_by'] ?? 0);
    $context = ['owner_id' => $ownerId];
    if (!user_can($pdo, $userId, $permissions['update'], $context)) throw new DomainException('Content access denied.');
    if ((string)$row['status'] !== 'draft' && !user_can($pdo, $userId, $permissions['publish'], $context)) {
        throw new DomainException('Content access denied.');
    }
    return ['owner_id' => $ownerId, 'status' => (string)($row['status'] ?? 'draft')];
}
