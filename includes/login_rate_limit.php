<?php

function loginRateLimitKeys(string $username): array
{
    $ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null)
        ? $_SERVER['REMOTE_ADDR']
        : 'unknown';
    $normalizedUsername = strtolower(trim($username));

    return [
        hash('sha256', 'ip:' . $ipAddress),
        hash('sha256', 'user-ip:' . $normalizedUsername . "\0" . $ipAddress),
    ];
}

function loginRateLimitIsBlocked(PDO $pdo, string $username): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM login_rate_limits
         WHERE scope_hash IN (?, ?)
           AND blocked_until > NOW()
         LIMIT 1'
    );
    $stmt->execute(loginRateLimitKeys($username));

    return $stmt->fetchColumn() !== false;
}

function loginRateLimitRecordFailure(PDO $pdo, string $username): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_rate_limits
            (scope_hash, attempts, window_started_at, blocked_until)
         VALUES (?, 1, NOW(), NULL)
         ON DUPLICATE KEY UPDATE
            blocked_until = CASE
                WHEN window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    THEN NULL
                WHEN attempts + 1 >= ?
                    THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                ELSE blocked_until
            END,
            attempts = CASE
                WHEN window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    THEN 1
                ELSE attempts + 1
            END,
            window_started_at = CASE
                WHEN window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    THEN NOW()
                ELSE window_started_at
            END'
    );

    foreach (loginRateLimitKeys($username) as $index => $key) {
        $threshold = $index === 0 ? 30 : 5;
        $stmt->execute([$key, $threshold]);
    }
}

function loginRateLimitClear(PDO $pdo, string $username): void
{
    $keys = loginRateLimitKeys($username);
    $stmt = $pdo->prepare(
        'DELETE FROM login_rate_limits WHERE scope_hash = ?'
    );
    $stmt->execute([$keys[1]]);
}
