<?php
// sms_alerts.php (PostgreSQL Version)
require_once 'config.php';
require_once 'textbee.php';

// Helper to keep the lease reminder message format consistent across generation, sync, and sending.
function buildLeaseExpiringMessage(string $deceasedName, string $leaseExpirationDate): string
{
    return "Reminder: The lease for {$deceasedName} at the cemetery is due on {$leaseExpirationDate}. Please contact the office.";
}

// Synchronize all queued (Pending / Failed) notifications with the live interments table.
// 1. Marks Pending/Failed rows as 'Skipped' if the interment was deactivated, soft-deleted,
//    or had its lease_expiration_date changed/renewed.
// 2. Updates recipient_name, recipient_phone_number, and message_body on remaining
//    Pending/Failed rows (only when values differ) so edits in interments immediately reflect.
// Sent rows are never modified so historical audit logs stay frozen.
function syncQueuedSmsAlerts(PDO $pdo): array
{
    // 1. Auto-skip queued lease alerts whose interment is no longer active or whose lease date changed.
    // PostgreSQL uses NOT EXISTS instead of MySQL's UPDATE ... LEFT JOIN.
    $skipStmt = $pdo->prepare("
        UPDATE sms_notifications s
           SET status = 'Skipped',
               updated_at = NOW(),
               error_message = CASE
                   WHEN s.error_message IS NULL OR s.error_message = ''
                       THEN 'Lease renewed or interment status changed before send'
                   ELSE CONCAT('Lease renewed or interment status changed before send | Original: ', s.error_message)
               END
         WHERE s.status IN ('Pending', 'Failed')
           AND s.notification_type = 'Lease Expiring'
           AND NOT EXISTS (
               SELECT 1
                 FROM interments i
                WHERE i.interment_id = s.interment_id
                  AND i.deleted_at IS NULL
                  AND i.status = 'Active'
                  AND i.lease_expiration_date IS NOT NULL
                  AND i.lease_expiration_date = s.lease_expiration_date_at_send
           )
    ");
    $skipStmt->execute();
    $autoSkipped = $skipStmt->rowCount();

    // 2. Sync latest contact name, phone number, and deceased name into remaining Pending/Failed rows.
    // PostgreSQL uses UPDATE ... FROM and checks for actual differences to avoid unnecessary MVCC writes.
    $syncStmt = $pdo->prepare("
        UPDATE sms_notifications s
           SET recipient_name = i.contact_person_name,
               recipient_phone_number = COALESCE(TRIM(i.contact_person_phone_number), ''),
               message_body = CASE
                   WHEN s.notification_type = 'Lease Expiring' THEN
                       CONCAT(
                           'Reminder: The lease for ',
                           i.deceased_name,
                           ' at the cemetery is due on ',
                           TO_CHAR(s.lease_expiration_date_at_send::date, 'YYYY-MM-DD'),
                           '. Please contact the office.'
                       )
                   ELSE s.message_body
               END,
               updated_at = NOW()
          FROM interments i
         WHERE s.interment_id = i.interment_id
           AND s.status IN ('Pending', 'Failed')
           AND i.deleted_at IS NULL
           AND i.status = 'Active'
           AND (
               COALESCE(s.recipient_name, '') <> COALESCE(i.contact_person_name, '')
               OR COALESCE(s.recipient_phone_number, '') <> COALESCE(TRIM(i.contact_person_phone_number), '')
               OR (
                   s.notification_type = 'Lease Expiring'
                   AND s.message_body <> CONCAT(
                       'Reminder: The lease for ',
                       i.deceased_name,
                       ' at the cemetery is due on ',
                       TO_CHAR(s.lease_expiration_date_at_send::date, 'YYYY-MM-DD'),
                       '. Please contact the office.'
                   )
               )
           )
    ");
    $syncStmt->execute();
    $synced = $syncStmt->rowCount();

    return [
        'auto_skipped' => $autoSkipped,
        'synced'       => $synced,
    ];
}

// Scan active interments and insert Pending rows for leases that
// match a reminder stage. Idempotent — safe to run as often as you want;
// the dedup check prevents duplicate rows for the same
// (interment, stage, lease-date) combo.
function generateSmsAlerts(PDO $pdo): array
{
    global $SMS_REMINDER_STAGES;

    // First, sync existing Pending/Failed rows with any recent changes in interments
    $syncStats = syncQueuedSmsAlerts($pdo);

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

            $phone   = trim((string)($lease['contact_person_phone_number'] ?? ''));
            $message = buildLeaseExpiringMessage(
                (string)$lease['deceased_name'],
                (string)$lease['lease_expiration_date']
            );

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
                $phone,
                $message,
            ]);
            $inserted++;
        }
    }

    return [
        'inserted'     => $inserted,
        'skipped'      => $skipped,
        'synced'       => $syncStats['synced'],
        'auto_skipped' => $syncStats['auto_skipped'],
    ];
}

// Drain the Pending/Failed queue, respecting the daily cap and the
// per-run batch cap. Updates status + attempt_count on each row.
//
// Before sending, syncs queued rows and re-reads the live interment row so that
// (a) renewed leases / deactivated interments are caught, and
// (b) a corrected contact name, phone number, or deceased name replaces the snapshot.
function processSmsAlerts(PDO $pdo): array
{
    // Sync queued rows right before processing so any last-second edits are applied
    $syncStats = syncQueuedSmsAlerts($pdo);

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
            'processed'            => 0,
            'sent'                 => 0,
            'failed'               => 0,
            'skipped'              => 0,
            'recipients_refreshed' => $syncStats['synced'],
            'reason'               => 'Daily send cap reached',
            'sent_today'           => $sentToday,
        ];
    }

    $effectiveBatch = min($batchLimit, $remainingToday);

    // Priority: closest to 0 (most urgent), then oldest.
    // PostgreSQL: DATEDIFF(a, b) → (a::date - CURRENT_DATE) integer days.
    // CAST(... AS BIGINT) keeps the LIMIT operand type-safe.
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

    $stats = [
        'processed'            => 0,
        'sent'                 => 0,
        'failed'               => 0,
        'skipped'              => 0,
        'recipients_refreshed' => $syncStats['synced'],
    ];

    foreach ($candidates as $row) {
        $stats['processed']++;

        // Fetch live interment data right before sending to guarantee fresh contact info
        // and verify that the lease hasn't been renewed or deactivated.
        $checkStmt = $pdo->prepare("
            SELECT deceased_name,
                   contact_person_name,
                   contact_person_phone_number,
                   lease_expiration_date
              FROM interments
             WHERE interment_id = CAST(:iid AS BIGINT)
               AND lease_expiration_date = :led
               AND status = 'Active'
               AND deleted_at IS NULL
             LIMIT 1
        ");
        $checkStmt->execute([
            ':iid' => $row['interment_id'],
            ':led' => $row['lease_expiration_date_at_send'],
        ]);
        $liveInterment = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$liveInterment) {
            $pdo->prepare("
                UPDATE sms_notifications
                   SET status = 'Skipped',
                       updated_at = NOW(),
                       error_message = 'Lease renewed or interment status changed before send'
                 WHERE notification_id = CAST(? AS BIGINT)
            ")->execute([$row['notification_id']]);
            $stats['skipped']++;
            continue;
        }

        // Pull latest live values from the interment record
        $liveRecipientName = $liveInterment['contact_person_name'];
        $livePhone         = trim((string)($liveInterment['contact_person_phone_number'] ?? ''));
        $liveMessage       = ($row['notification_type'] === 'Lease Expiring')
            ? buildLeaseExpiringMessage(
                (string)$liveInterment['deceased_name'],
                (string)$liveInterment['lease_expiration_date']
            )
            : (string)$row['message_body'];

        // Guard against missing/blank phone number before calling TextBee
        if ($livePhone === '') {
            $pdo->prepare("
                UPDATE sms_notifications
                   SET recipient_name = :rname,
                       recipient_phone_number = '',
                       message_body = :msg,
                       status = 'Failed',
                       attempt_count = attempt_count + 1,
                       error_message = 'Missing contact phone number in interment record',
                       updated_at = NOW()
                 WHERE notification_id = CAST(:nid AS BIGINT)
            ")->execute([
                ':rname' => $liveRecipientName,
                ':msg'   => $liveMessage,
                ':nid'   => $row['notification_id'],
            ]);
            $stats['failed']++;
            continue;
        }

        // include_cemetery_name = TRUE → function appends the suffix
        $result = sendSmsViaTextBee(
            $livePhone,
            $liveMessage,
            true,
            NULL
        );

        if (!empty($result['success'])) {
            $pdo->prepare("
                UPDATE sms_notifications
                   SET recipient_name = :rname,
                       recipient_phone_number = :rphone,
                       message_body = :msg,
                       status = 'Sent',
                       sent_at = NOW(),
                       attempt_count = attempt_count + 1,
                       error_message = NULL,
                       updated_at = NOW()
                 WHERE notification_id = CAST(:nid AS BIGINT)
            ")->execute([
                ':rname'  => $liveRecipientName,
                ':rphone' => $livePhone,
                ':msg'    => $liveMessage,
                ':nid'    => $row['notification_id'],
            ]);
            $stats['sent']++;
        } else {
            $pdo->prepare("
                UPDATE sms_notifications
                   SET recipient_name = :rname,
                       recipient_phone_number = :rphone,
                       message_body = :msg,
                       status = 'Failed',
                       attempt_count = attempt_count + 1,
                       error_message = :err,
                       updated_at = NOW()
                 WHERE notification_id = CAST(:nid AS BIGINT)
            ")->execute([
                ':rname'  => $liveRecipientName,
                ':rphone' => $livePhone,
                ':msg'    => $liveMessage,
                ':nid'    => $row['notification_id'],
                ':err'    => $result['error'] ?? 'Unknown error',
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
    // Sync Pending/Failed rows first so the UI always displays up-to-date
    // phone numbers, names, and statuses even when auto-send is turned off.
    syncQueuedSmsAlerts($pdo);

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
            'by_status'         => $byStatus,
            'by_stage'          => $byStage,
            'sent_today'        => $sentToday,
            'daily_cap'         => (int)SMS_MAX_DAILY_SENDS,
            'remaining_today'   => max(0, (int)SMS_MAX_DAILY_SENDS - $sentToday),
            'auto_send_enabled' => isAutoSendSmsEnabled($pdo),
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

// ---- Recipient & Message Refresh Helpers ----------------------------------

function syncNotificationRecipient(PDO $pdo, int $notificationId): bool
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications s
           SET recipient_name         = i.contact_person_name,
               recipient_phone_number = COALESCE(TRIM(i.contact_person_phone_number), ''),
               message_body           = CASE
                   WHEN s.notification_type = 'Lease Expiring' THEN
                       CONCAT(
                           'Reminder: The lease for ',
                           i.deceased_name,
                           ' at the cemetery is due on ',
                           TO_CHAR(s.lease_expiration_date_at_send::date, 'YYYY-MM-DD'),
                           '. Please contact the office.'
                       )
                   ELSE s.message_body
               END,
               updated_at             = NOW()
          FROM interments i
         WHERE s.notification_id = CAST(:nid AS BIGINT)
           AND s.interment_id    = i.interment_id
           AND i.deleted_at IS NULL
    ");
    $stmt->execute([':nid' => $notificationId]);
    return $stmt->rowCount() > 0;
}

function syncRetryableRecipients(PDO $pdo, array $statuses): int
{
    if (empty($statuses)) {
        return 0;
    }

    $placeholders = [];
    $params       = [];
    foreach (array_values($statuses) as $i => $s) {
        $ph             = ':st_' . $i;
        $placeholders[] = $ph;
        $params[$ph]    = $s;
    }
    $inList = implode(', ', $placeholders);

    $stmt = $pdo->prepare("
        UPDATE sms_notifications s
           SET recipient_name         = i.contact_person_name,
               recipient_phone_number = COALESCE(TRIM(i.contact_person_phone_number), ''),
               message_body           = CASE
                   WHEN s.notification_type = 'Lease Expiring' THEN
                       CONCAT(
                           'Reminder: The lease for ',
                           i.deceased_name,
                           ' at the cemetery is due on ',
                           TO_CHAR(s.lease_expiration_date_at_send::date, 'YYYY-MM-DD'),
                           '. Please contact the office.'
                       )
                   ELSE s.message_body
               END,
               updated_at             = NOW()
          FROM interments i
         WHERE s.interment_id = i.interment_id
           AND i.deleted_at IS NULL
           AND s.status IN ({$inList})
           AND (
                 COALESCE(s.recipient_phone_number, '') <> COALESCE(TRIM(i.contact_person_phone_number), '')
              OR COALESCE(s.recipient_name, '')         <> COALESCE(i.contact_person_name, '')
              OR (
                  s.notification_type = 'Lease Expiring'
                  AND s.message_body <> CONCAT(
                      'Reminder: The lease for ',
                      i.deceased_name,
                      ' at the cemetery is due on ',
                      TO_CHAR(s.lease_expiration_date_at_send::date, 'YYYY-MM-DD'),
                      '. Please contact the office.'
                  )
              )
           )
    ");
    $stmt->execute($params);
    return $stmt->rowCount();
}

// Flip a Failed (or Skipped) row back to Pending, reset the attempt counter,
// and immediately sync latest contact details and lease validity from interments.
function retrySmsAlert(PDO $pdo, int $notificationId): array
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status        = 'Pending',
               attempt_count = 0,
               error_message = NULL,
               updated_at    = NOW()
         WHERE notification_id = CAST(:id AS BIGINT)
           AND status IN ('Failed', 'Skipped')
    ");
    $stmt->execute([':id' => $notificationId]);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'error' => 'Row not retryable (not Failed or Skipped, or not found).'];
    }

    syncQueuedSmsAlerts($pdo);

    return ['success' => true, 'notification_id' => $notificationId];
}

// Cancel a single Pending row (mark Skipped). Sent rows can't be cancelled.
function cancelSmsAlert(PDO $pdo, int $notificationId, int $userId): array
{
    // Preserve the original error if this was a Failed row — the user
    // needs the failure reason to make an informed decision later.
    //
    // PostgreSQL: IF() is MySQL-only; CASE WHEN ... END is the portable
    // equivalent.
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status = 'Skipped',
               updated_at = NOW(),
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
               error_message = :reason,
               updated_at    = NOW()
         WHERE status = 'Pending'
    ");
    $stmt->execute([
        ':reason' => 'Bulk-cancelled by user #' . $userId . ' at ' . date('Y-m-d H:i:s'),
    ]);
    return ['success' => true, 'cancelled' => $stmt->rowCount()];
}

// Bulk retry: every Failed row → Pending with attempt_count reset and live data synced
function retryAllFailedSmsAlerts(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status        = 'Pending',
               attempt_count = 0,
               error_message = NULL,
               updated_at    = NOW()
         WHERE status = 'Failed'
    ");
    $stmt->execute();
    $retried = $stmt->rowCount();

    $refreshed = 0;
    if ($retried > 0) {
        $syncStats = syncQueuedSmsAlerts($pdo);
        $refreshed = $syncStats['synced'];
    }

    return [
        'success'              => true,
        'retried'              => $retried,
        'recipients_refreshed' => $refreshed,
    ];
}

// Bulk retry: every Skipped row → Pending with attempt_count reset and live data synced
function retryAllSkippedSmsAlerts(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        UPDATE sms_notifications
           SET status        = 'Pending',
               attempt_count = 0,
               error_message = NULL,
               updated_at    = NOW()
         WHERE status = 'Skipped'
    ");
    $stmt->execute();
    $retried = $stmt->rowCount();

    $refreshed = 0;
    if ($retried > 0) {
        $syncStats = syncQueuedSmsAlerts($pdo);
        $refreshed = $syncStats['synced'];
    }

    return [
        'success'              => true,
        'retried'              => $retried,
        'recipients_refreshed' => $refreshed,
    ];
}
