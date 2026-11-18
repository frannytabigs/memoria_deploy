<?php
// sms_alerts.php
require_once 'config.php';
require_once 'textbee.php';

// Scan active interments and insert Pending rows for leases that
// match a reminder stage. Idempotent — safe to run as often as you
// the dedup check prevents duplicate rows for the same
// (interment, stage, lease-date) combo
function generateSmsAlerts(PDO $pdo): array
{
    global $SMS_REMINDER_STAGES;

    $inserted = 0;
    $skipped  = 0;

    foreach ($SMS_REMINDER_STAGES as $stageDays => $stageName) {
        // Stage -1 catches any NEGATIVE day (already expired), but
        // strictly < 0 so it does NOT collide with stage 0 on the
        // day of expiry (that would send TWO SMS for one event).
        //
        // PostgreSQL: DATEDIFF() doesn't exist. `date - date` yields
        // an integer number of days, so we cast to ::date and subtract
        // CURRENT_DATE. CAST(... AS INTEGER) on the parameter sidesteps
        // "integer = text" errors when PDO sends the bound value as text.
        if ($stageDays === -1) {
            $whereDiff = "(lease_expiration_date::date - CURRENT_DATE) < 0";
            $params    = [];
        } else {
            $whereDiff = "(lease_expiration_date::date - CURRENT_DATE) = CAST(:stage_days AS INTEGER)";
            $params    = [':stage_days' => $stageDays];
        }

        $sql = "
            SELECT interment_id, deceased_name, contact_person_name,
                   contact_person_phone_number, lease_expiration_date
              FROM interments
             WHERE status = 'Active'
               AND deleted_at IS NULL
               AND lease_expiration_date IS NOT NULL
               AND {$whereDiff}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leases as $lease) {
            // Dedup: has ANY row already been created for this combo?
            // Sent, Failed, Pending, Skipped — all count. Retries happen
            // inside that single row via attempt_count, so the generator
            // must never create a second one.
            $dedupStmt = $pdo->prepare("
                SELECT 1 FROM sms_notifications
                 WHERE interment_id = CAST(? AS BIGINT)
                   AND notification_type = 'Lease Expiring'
                   AND lease_expiration_date_at_send = ?
                   AND days_before_expiry = CAST(? AS INTEGER)
                 LIMIT 1
            ");
            $dedupStmt->execute([
                $lease['interment_id'],
                $lease['lease_expiration_date'],
                $stageDays,
            ]);

            if ($dedupStmt->fetchColumn()) {
                $skipped++;
                continue;
            }

            $message = "Reminder: The lease for {$lease['deceased_name']} at the cemetery is due on {$lease['lease_expiration_date']}. Please contact the office.";

            $pdo->prepare("
                INSERT INTO sms_notifications
                    (interment_id, notification_type, lease_expiration_date_at_send,
                     days_before_expiry, recipient_name, recipient_phone_number,
                     message_body, status)
                VALUES (CAST(? AS BIGINT), 'Lease Expiring', ?, CAST(? AS INTEGER), ?, ?, ?, 'Pending')
            ")->execute([
                $lease['interment_id'],
                $lease['lease_expiration_date'],
                $stageDays,
                $lease['contact_person_name'],
                $lease['contact_person_phone_number'],
                $message,
            ]);
            $inserted++;
        }
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

// Drain the Pending/Failed queue, respecting the daily cap and the
// per-run batch cap. Updates status + attempt_count on each row.
function processSmsAlerts(PDO $pdo): array
{
    $dailyLimit = SMS_MAX_DAILY_SENDS;
    $batchLimit = SMS_MAX_BATCH_PER_RUN;
    $maxRetries = SMS_MAX_RETRY_ATTEMPTS;

    // How many did we successfully send TODAY?
    // PostgreSQL: DATE(x) → x::date, CURDATE() → CURRENT_DATE
    $sentToday = (int)$pdo->query("
        SELECT COUNT(*) FROM sms_notifications
         WHERE status = 'Sent' AND sent_at::date = CURRENT_DATE
    ")->fetchColumn();

    $remainingToday = max(0, $dailyLimit - $sentToday);
    if ($remainingToday === 0) {
        return [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'reason' => 'Daily send cap reached',
            'sent_today' => $sentToday,
        ];
    }

    $effectiveBatch = min($batchLimit, $remainingToday);

    // Priority: closest to 0 (most urgent), then oldest.
    // PostgreSQL: DATEDIFF(a, b) → (a::date - CURRENT_DATE) integer days.
    // CAST(... AS BIGINT) keeps the LIMIT operand type-safe regardless of
    // PDO/php version quirks around LIMIT placeholders.
    $stmt = $pdo->prepare("
        SELECT *
          FROM sms_notifications
         WHERE status IN ('Pending', 'Failed')
           AND attempt_count < CAST(:max_retries AS INTEGER)
         ORDER BY ABS(lease_expiration_date_at_send::date - CURRENT_DATE) ASC,
                  created_at ASC
         LIMIT CAST(:batch_limit AS BIGINT)
    ");
    $stmt->bindValue(':max_retries', $maxRetries, PDO::PARAM_INT);
    $stmt->bindValue(':batch_limit', $effectiveBatch, PDO::PARAM_INT);
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($candidates as $row) {
        $stats['processed']++;

        // Safety: has the lease been renewed or the interment deactivated
        // between generation and now?
        $checkStmt = $pdo->prepare("
            SELECT 1 FROM interments
             WHERE interment_id = CAST(:iid AS BIGINT)
               AND lease_expiration_date = :led
               AND status = 'Active'
               AND deleted_at IS NULL
        ");
        $checkStmt->execute([
            ':iid' => $row['interment_id'],
            ':led' => $row['lease_expiration_date_at_send'],
        ]);

        if (!$checkStmt->fetchColumn()) {
            $pdo->prepare("
                UPDATE sms_notifications
                   SET status = 'Skipped',
                       error_message = 'Lease renewed or interment status changed before send'
                 WHERE notification_id = CAST(? AS BIGINT)
            ")->execute([$row['notification_id']]);
            $stats['skipped']++;
            continue;
        }

        // include_cemetery_name = TRUE → function appends the suffix
        $result = sendSmsViaTextBee(
            $row['recipient_phone_number'],
            $row['message_body'],
            true,
            NULL
        );

        if (!empty($result['success'])) {
            $pdo->prepare("
                UPDATE sms_notifications
                   SET status = 'Sent',
                       sent_at = NOW(),
                       attempt_count = attempt_count + 1,
                       error_message = NULL
                 WHERE notification_id = CAST(:nid AS BIGINT)
            ")->execute([':nid' => $row['notification_id']]);
            $stats['sent']++;
        } else {
            $pdo->prepare("
                UPDATE sms_notifications
                   SET status = 'Failed',
                       attempt_count = attempt_count + 1,
                       error_message = :err
                 WHERE notification_id = CAST(:nid AS BIGINT)
            ")->execute([
                ':nid' => $row['notification_id'],
                ':err' => $result['error'] ?? 'Unknown error',
            ]);
            $stats['failed']++;
        }
    }

    return $stats;
}

// Generate and send SMS alerts in one call. It is safe to run every tick because
// generation does not create duplicate rows. Sending only happens when the
// auto_send_sms_expiring setting is enabled, but generation always runs so the
// queue stays updated. If sending is off, pending alerts will wait until it is turned on.
function runSmsAlerts(PDO $pdo, ?bool $sendEnabled = null): array
{
    // If caller didn't specify, look up the flag
    if ($sendEnabled === null) {
        $sendEnabled = isAutoSendSmsEnabled($pdo);
    }

    $result = [
        'generation'        => generateSmsAlerts($pdo),
        'auto_send_enabled' => $sendEnabled,
    ];

    if ($sendEnabled) {
        $result['sending'] = processSmsAlerts($pdo);
    } else {
        $result['sending'] = [
            'skipped' => true,
            'reason'  => 'auto_send_sms_expiring is disabled',
        ];
    }

    return $result;
}

// Check if automated SMS sending is enabled. It reads the auto_send_sms_expiring setting.
// If the setting is missing, empty, or unknown, sending is enabled by default.
// If the database has an error, it also stays enabled so SMS does not stop working.
function isAutoSendSmsEnabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT setting_value
              FROM settings
             WHERE setting_key = 'auto_send_sms_expiring'
               AND deleted_at IS NULL
             LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('isAutoSendSmsEnabled: DB check failed — assuming enabled: ' . $e->getMessage());
        return true;
    }

    // Row doesn't exist → default true
    if ($row === false) {
        return true;
    }

    $raw        = (string)($row['setting_value'] ?? '');
    $normalized = strtolower(trim($raw));

    // Empty value → treat as "not configured" → true
    if ($normalized === '') {
        return true;
    }

    $falsy  = ['0', 'false', 'no', 'off', 'disabled'];
    $truthy = ['1', 'true', 'yes', 'on', 'enabled'];

    if (in_array($normalized, $falsy, true)) {
        return false;
    }
    if (in_array($normalized, $truthy, true)) {
        return true;
    }

    // Unknown value → fail open
    return true;
}

// Get the current SMS notification queue for the UI.
// It returns summary counts, a paginated list of logs, pagination info, and the filters that were used.
// Optional options:
//   status   - Filter by status: 'Pending', 'Sent', 'Failed', or 'Skipped'
//   stage    - Filter by reminder stage: 30, 7, 0, -1, or 'manual'
//   search   - Search by recipient name, phone number, or deceased name
//   type     - Notification type, default is 'Lease Expiring'
//   page     - Page number, default is 1
//   per_page - Number of rows per page, default is 25 and maximum is 100
function statusesSmsAlerts(PDO $pdo, array $options = []): array
{
    $type     = $options['type']     ?? 'Lease Expiring';
    $status   = $options['status']   ?? null;
    $stage    = $options['stage']    ?? null;
    $search   = $options['search']   ?? null;
    $page     = max(1, (int)($options['page']     ?? 1));
    $perPage  = min(100, max(1, (int)($options['per_page'] ?? 25)));
    $offset   = ($page - 1) * $perPage;

    // Summary: counts by status
    $sumStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS count
          FROM sms_notifications
         WHERE notification_type = :type
         GROUP BY status
    ");
    $sumStmt->execute([':type' => $type]);
    $byStatus = ['Pending' => 0, 'Sent' => 0, 'Failed' => 0, 'Skipped' => 0];
    while ($r = $sumStmt->fetch(PDO::FETCH_ASSOC)) {
        $byStatus[$r['status']] = (int)$r['count'];
    }

    // Sent today vs daily cap
    // PostgreSQL: DATE(x) → x::date, CURDATE() → CURRENT_DATE
    $sentTodayStmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM sms_notifications
         WHERE status = 'Sent'
           AND sent_at::date = CURRENT_DATE
           AND notification_type = :type
    ");
    $sentTodayStmt->execute([':type' => $type]);
    $sentToday = (int)$sentTodayStmt->fetchColumn();

    // Stage breakdown of queued work (Pending + Failed)
    $stageStmt = $pdo->prepare("
        SELECT days_before_expiry AS stage, COUNT(*) AS count
          FROM sms_notifications
         WHERE status IN ('Pending', 'Failed')
           AND notification_type = :type
         GROUP BY days_before_expiry
         ORDER BY days_before_expiry DESC
    ");
    $stageStmt->execute([':type' => $type]);
    $byStage = [];
    while ($r = $stageStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $r['stage'] === null ? 'manual' : (string)(int)$r['stage'];
        $byStage[$key] = (int)$r['count'];
    }

    // Build filters
    $where  = ['s.notification_type = :type'];
    $params = [':type' => $type];

    if ($status && in_array($status, ['Pending', 'Sent', 'Failed', 'Skipped'], true)) {
        $where[] = 's.status = :status';
        $params[':status'] = $status;
    }
    if ($stage !== null && $stage !== '') {
        if ($stage === 'manual') {
            $where[] = 's.days_before_expiry IS NULL';
        } else {
            // CAST avoids "integer = text" errors when PDO sends the
            // bound value as text.
            $where[] = 's.days_before_expiry = CAST(:stage AS INTEGER)';
            $params[':stage'] = (int)$stage;
        }
    }
    if ($search) {
        // MySQL LIKE is case-insensitive by default; PostgreSQL's LIKE is
        // not. ILIKE preserves the original (case-insensitive) behavior.
        $where[] = '(s.recipient_name ILIKE :search1
              OR s.recipient_phone_number ILIKE :search2
              OR i.deceased_name ILIKE :search3)';
        $searchVal = '%' . $search . '%';
        $params[':search1'] = $searchVal;
        $params[':search2'] = $searchVal;
        $params[':search3'] = $searchVal;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Total count for pagination
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM sms_notifications s
          LEFT JOIN interments i ON s.interment_id = i.interment_id
        {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Paginated rows
    // CAST on LIMIT/OFFSET so PG never complains about placeholder typing.
    $rowsStmt = $pdo->prepare("
    SELECT
        s.notification_id,
        s.interment_id,
        s.notification_type,
        s.lease_expiration_date_at_send,
        i.lease_expiration_date AS current_lease_expiration_date,
        s.days_before_expiry,
        s.recipient_name,
        s.recipient_phone_number,
        s.message_body,
        s.status,
        s.attempt_count,
        s.sent_at,
        s.provider_message_id,
        s.error_message,
        s.created_at,
        i.deceased_name,
        s.updated_at
      FROM sms_notifications s
      LEFT JOIN interments i ON s.interment_id = i.interment_id
    {$whereSql}
    ORDER BY s.created_at DESC
    LIMIT CAST(:lim AS BIGINT) OFFSET CAST(:off AS BIGINT)
    ");
    foreach ($params as $k => $v) {
        $rowsStmt->bindValue($k, $v);
    }
    $rowsStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $rowsStmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $rowsStmt->execute();
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'summary' => [
            'by_status'       => $byStatus,
            'by_stage'        => $byStage,
            'sent_today'      => $sentToday,
            'daily_cap'       => (int)SMS_MAX_DAILY_SENDS,
            'remaining_today' => max(0, (int)SMS_MAX_DAILY_SENDS - $sentToday),
            'auto_send_enabled' => isAutoSendSmsEnabled($pdo)
        ],
        'rows'       => $rows,
        'pagination' => [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => (int)ceil($total / max(1, $perPage)),
        ],
        'filters' => [
            'type'   => $type,
            'status' => $status,
            'stage'  => $stage,
            'search' => $search,
        ],
    ];
}

// Flip a Failed (or Skipped) row back to Pending and reset the attempt counter.
function retrySmsAlert(PDO $pdo, int $notificationId): array
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status        = 'Pending',
               attempt_count = 0,
               error_message = NULL
         WHERE notification_id = CAST(:id AS BIGINT)
           AND status IN ('Failed', 'Skipped')
    ");
    $stmt->execute([':id' => $notificationId]);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'error' => 'Row not retryable (not Failed or Skipped, or not found).'];
    }
    return ['success' => true, 'notification_id' => $notificationId];
}

// Cancel a single Pending row (mark Skipped). Sent rows can't be cancelled
function cancelSmsAlert(PDO $pdo, int $notificationId, int $userId): array
{
    // Preserve the original error if this was a Failed row — the user
    // needs the failure reason to make an informed decision later.
    //
    // PostgreSQL: IF() is MySQL-only; CASE WHEN ... END is the portable
    // equivalent. CONCAT() already ignores NULLs in PG, but the CASE keeps
    // the "append only when there was a prior error" logic intact.
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status = 'Skipped',
               error_message = CONCAT(
                   'Cancelled by user #', CAST(:uid AS TEXT), ' at ', CAST(:ts AS TEXT),
                   CASE
                       WHEN error_message IS NULL OR error_message = ''
                           THEN ''
                       ELSE CONCAT(' | Original: ', error_message)
                   END
               )
         WHERE notification_id = CAST(:id AS BIGINT)
           AND status IN ('Pending', 'Failed')
    ");
    $stmt->execute([
        ':id'  => $notificationId,
        ':uid' => $userId,
        ':ts'  => date('Y-m-d H:i:s'),
    ]);

    if ($stmt->rowCount() === 0) {
        return [
            'success' => false,
            'error'   => 'Row not cancellable (must be Pending or Failed, and not already Sent/Skipped).',
        ];
    }
    return ['success' => true, 'notification_id' => $notificationId];
}

// Bulk abort: every Pending row → Skipped
function cancelAllPendingSmsAlerts(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status        = 'Skipped',
               error_message = :reason
         WHERE status = 'Pending'
    ");
    $stmt->execute([
        ':reason' => 'Bulk-cancelled by user #' . $userId . ' at ' . date('Y-m-d H:i:s'),
    ]);
    return ['success' => true, 'cancelled' => $stmt->rowCount()];
}

// Bulk retry: every Failed row under the retry cap → Pending with attempt_count reset
function retryAllFailedSmsAlerts(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status        = 'Pending',
               attempt_count = 0,
               error_message = NULL
         WHERE status = 'Failed'
    ");
    $stmt->execute();
    return ['success' => true, 'retried' => $stmt->rowCount()];
}

// Bulk retry: every Skipped row → Pending with attempt_count reset
function retryAllSkippedSmsAlerts(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status        = 'Pending',
               attempt_count = 0,
               error_message = NULL
         WHERE status = 'Skipped'
    ");
    $stmt->execute();
    return ['success' => true, 'retried' => $stmt->rowCount()];
}
