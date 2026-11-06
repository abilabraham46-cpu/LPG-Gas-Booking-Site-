<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

$auth = new Auth();

// Require admin login
if (!$auth->isLoggedIn() || ($auth->getCurrentUser()['role'] ?? '') !== 'admin') {
    header('Location: admin-portal.php?error=login_required');
    exit;
}

$current_user = $auth->getCurrentUser();
$error = '';
$message = '';

$completed_orders = [];
$completed_histories = [];

try {
  $completed_sql = "SELECT o.*, 
                           u.name AS customer_name,
                           u.email AS customer_email,
                           u.phone AS customer_phone,
                           u.address AS customer_address,
                           COALESCE(u.address, o.delivery_address, 'Address not specified') AS full_address,
                           d.delivery_time AS delivered_at,
                           d.notes AS delivery_notes,
                           d.delivery_person_id,
                           dp.name AS delivery_person_name
                    FROM orders o
                    LEFT JOIN users u ON o.customer_id = u.id
                    LEFT JOIN deliveries d ON o.id = d.order_id
                    LEFT JOIN users dp ON d.delivery_person_id = dp.id
                    WHERE o.status = 'delivered'
                    ORDER BY COALESCE(d.delivery_time, o.updated_at, o.created_at) DESC
                    LIMIT 6";
    $completed_orders = fetchAll($completed_sql);
} catch (Exception $completed_exception) {
    error_log('Failed to load completed orders: ' . $completed_exception->getMessage());
    $error = 'Failed to load completed orders.';
    $completed_orders = [];
}

if (!empty($completed_orders)) {
    $completedIds = array_column($completed_orders, 'id');
    $completedPlaceholders = implode(',', array_fill(0, count($completedIds), '?'));

    try {
        $completed_history_sql = "SELECT oh.order_id, oh.status, oh.notes, oh.created_at, oh.changed_by, u.name AS changed_by_name
                                  FROM order_history oh
                                  LEFT JOIN users u ON oh.changed_by = u.id
                                  WHERE oh.order_id IN ($completedPlaceholders)
                                  ORDER BY oh.created_at";

        $completed_history_stmt = executeQuery($completed_history_sql, $completedIds);
        $completed_history_rows = $completed_history_stmt->fetchAll();

        foreach ($completed_history_rows as $row) {
            $completed_histories[$row['order_id']][] = $row;
        }
    } catch (Exception $completed_history_exception) {
        error_log('Failed to load completed order histories: ' . $completed_history_exception->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Completed Orders | Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --flame-orange: #FF6B35;
      --flame-red: #F7931E;
      --deep-navy: #1a1f36;
      --glass: rgba(255, 255, 255, 0.12);
      --glass-dark: rgba(26, 31, 54, 0.65);
      --pure-white: #ffffff;
      --success-green: #48bb78;
      --danger-red: #f56565;
      --muted-white: rgba(255, 255, 255, 0.75);
    }

    * {
      box-sizing: border-box;
      font-family: 'Inter', system-ui, sans-serif;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      color: var(--pure-white);
    }

    .page-container {
      max-width: 1280px;
      margin: 2rem auto;
      padding: 0 1.5rem 3rem;
    }

    .page-actions {
      display: flex;
      gap: 1rem;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.65rem 1.2rem;
      border-radius: 14px;
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: var(--pure-white);
      text-decoration: none;
      font-weight: 700;
      backdrop-filter: blur(12px);
      transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.25);
    }

    .btn-back {
      background: rgba(0, 0, 0, 0.28);
    }

    .page-card {
      background: rgba(255, 255, 255, 0.12);
      border-radius: 28px;
      padding: 2.75rem;
      border: 1px solid rgba(255, 255, 255, 0.18);
      backdrop-filter: blur(28px);
      box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
    }

    .page-header {
      margin-bottom: 2rem;
    }

    .page-title {
      margin: 0;
      font-size: 2.4rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      text-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }

    .page-subtitle {
      margin: 0.6rem 0 0;
      color: var(--muted-white);
      font-size: 1rem;
    }

    .history-section {
      margin-bottom: 2.75rem;
    }

    .section-title {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-size: 1.6rem;
      font-weight: 700;
      margin: 0 0 1.5rem;
      color: var(--pure-white);
      text-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
    }

    .section-title i {
      color: var(--success-green);
    }

    .alert {
      padding: 1rem 1.3rem;
      border-radius: 16px;
      margin-bottom: 1.2rem;
      font-weight: 600;
      border: 1px solid rgba(255, 255, 255, 0.25);
      backdrop-filter: blur(18px);
    }

    .alert-err {
      background: rgba(245, 101, 101, 0.18);
      color: #ffd6d6;
    }

    .alert-ok {
      background: rgba(72, 187, 120, 0.18);
      color: #c7ffdc;
    }

    .history-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 2rem;
    }

    .history-card {
      position: relative;
      background: var(--glass-dark);
      border-radius: 24px;
      padding: 2rem;
      border: 1px solid rgba(255, 255, 255, 0.16);
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.3);
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }

    .history-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(135deg, var(--success-green), #38a169);
    }

    .history-card-header {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 1rem;
    }

    .history-card-title {
      font-size: 1.2rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }

    .history-card-time {
      font-size: 0.95rem;
      color: rgba(255, 255, 255, 0.75);
      display: flex;
      align-items: center;
      gap: 0.4rem;
    }

    .history-summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem;
    }

    .history-summary-item {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 14px;
      padding: 0.95rem;
      border: 1px solid rgba(255, 255, 255, 0.16);
    }

    .history-summary-item--full {
      grid-column: 1 / -1;
    }

    .history-summary-label {
      display: block;
      font-size: 0.7rem;
      letter-spacing: 0.45px;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.6);
      margin-bottom: 0.35rem;
      font-weight: 600;
    }

    .history-summary-value {
      font-size: 0.95rem;
      color: rgba(255, 255, 255, 0.9);
      display: flex;
      align-items: center;
      gap: 0.45rem;
      font-weight: 600;
    }

    .history-summary-value i {
      color: var(--success-green);
    }

    .timeline-notes {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.14);
      border-radius: 14px;
      padding: 1rem;
    }

    .timeline-notes strong {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.95rem;
    }

    .timeline-notes p {
      margin: 0.5rem 0 0;
      font-size: 0.9rem;
      color: rgba(255, 255, 255, 0.85);
      white-space: pre-wrap;
    }

    .history-timeline {
      position: relative;
      margin: 0;
      padding: 0 0 0 1.6rem;
      list-style: none;
    }

    .history-timeline::before {
      content: '';
      position: absolute;
      top: 0.5rem;
      bottom: 0.5rem;
      left: 0.45rem;
      width: 2px;
      background: rgba(255, 255, 255, 0.25);
    }

    .history-timeline-item {
      position: relative;
      margin-bottom: 1.4rem;
      padding-left: 1rem;
      color: rgba(255, 255, 255, 0.85);
    }

    .history-timeline-item:last-child {
      margin-bottom: 0;
    }

    .history-timeline-item::before {
      content: '';
      position: absolute;
      left: -1.2rem;
      top: 0.35rem;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--success-green), #38a169);
      box-shadow: 0 0 0 4px rgba(72, 187, 120, 0.25);
    }

    .history-timeline-status {
      font-size: 0.95rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .history-timeline-time {
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.7);
      margin-top: 0.2rem;
    }

    .history-timeline-meta {
      font-size: 0.78rem;
      color: rgba(255, 255, 255, 0.65);
      margin-top: 0.25rem;
    }

    .history-timeline-notes {
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.77);
      margin-top: 0.45rem;
      background: rgba(255, 255, 255, 0.08);
      border-radius: 10px;
      padding: 0.6rem 0.8rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
    }

    .empty-state {
      text-align: center;
      padding: 3rem 1rem 2rem;
      color: rgba(255, 255, 255, 0.78);
    }

    .empty-state i {
      display: block;
      font-size: 3rem;
      margin-bottom: 1rem;
      opacity: 0.55;
    }

    @media (max-width: 768px) {
      .page-container {
        padding: 0 1rem 2.5rem;
      }

      .page-card {
        padding: 1.75rem;
      }

      .page-title {
        font-size: 1.8rem;
      }

      .history-grid {
        grid-template-columns: 1fr;
      }

      .history-card {
        padding: 1.5rem;
      }
    }
  </style>
<?php if (!function_exists('formatOrderStatusLabel')): ?>
<?php
function formatOrderStatusLabel($status) {
    $normalized = strtolower(trim((string) $status));

    $mapping = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'assigned' => 'Assigned to Delivery',
        'out_for_delivery' => 'Out for Delivery',
        'in_transit' => 'In Transit',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'failed' => 'Delivery Failed'
    ];

    if (isset($mapping[$normalized])) {
        return $mapping[$normalized];
    }

    $cleaned = str_replace(['_', '-'], ' ', $normalized);
    return ucwords($cleaned ?: 'Status Update');
}

function formatTimelineTime($timestamp) {
    if (empty($timestamp)) {
        return '';
    }

    $parsed = strtotime($timestamp);
    if ($parsed === false) {
        return '';
    }

    return date('M d, Y h:i A', $parsed);
}
?>
<?php endif; ?>
  </head>
<body>
  <div class="page-container">
    <div class="page-actions">
      <a class="btn btn-back" href="admin-portal.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
      <a class="btn" href="cancelled-orders.php"><i class="fas fa-ban"></i> View Cancelled Orders</a>
    </div>

    <div class="page-card">
      <div class="page-header">
        <h1 class="page-title"><i class="fas fa-check-circle"></i> Completed Orders</h1>
        <p class="page-subtitle">Audit trail of recently delivered orders with delivery team notes.</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-err"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($message): ?>
        <div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>

      <div class="history-section">
        <h2 class="section-title"><i class="fas fa-check-circle"></i> Completed Orders</h2>
        <?php if (empty($completed_orders)): ?>
          <div class="empty-state">
            <i class="fas fa-truck"></i>
            <p>No completed deliveries recorded yet.</p>
            <p style="margin-top: 0.5rem; font-size: 0.9rem;">Delivered orders will appear here with their full status timeline for auditing.</p>
          </div>
        <?php else: ?>
          <div class="history-grid">
            <?php foreach ($completed_orders as $completed): ?>
              <?php
                $completedId = (int)($completed['id'] ?? 0);
                $timelineEntries = [];
                $seenStatuses = [];
                $historyEntries = $completed_histories[$completedId] ?? [];

                if (!empty($completed['created_at'])) {
                    $placedMeta = !empty($completed['customer_name'])
                        ? 'Placed by ' . $completed['customer_name']
                        : 'Placed by customer';

                    $initialNotes = trim((string)($completed['special_instructions'] ?? ''));

                    $timelineEntries[] = [
                        'label' => 'Order Placed',
                        'time' => formatTimelineTime($completed['created_at']),
                        'meta' => $placedMeta,
                        'notes' => $initialNotes !== '' ? 'Instructions: ' . $initialNotes : '',
                        'statusKey' => 'placed'
                    ];
                }

                foreach ($historyEntries as $historyEntry) {
                    $statusKey = strtolower(trim((string)($historyEntry['status'] ?? '')));
                    if ($statusKey !== '') {
                        $seenStatuses[$statusKey] = true;
                    }

                    $metaInfo = '';
                    if (!empty($historyEntry['changed_by_name'])) {
                        $metaInfo = 'Updated by ' . $historyEntry['changed_by_name'];
                    }

                    $notesInfo = trim((string)($historyEntry['notes'] ?? ''));

                    $timelineEntries[] = [
                        'label' => formatOrderStatusLabel($historyEntry['status'] ?? ''),
                        'time' => formatTimelineTime($historyEntry['created_at'] ?? ''),
                        'meta' => $metaInfo,
                        'notes' => $notesInfo,
                        'statusKey' => $statusKey
                    ];
                }

                $finalRawTimestamp = $completed['delivered_at'] ?: ($completed['updated_at'] ?? null);
                if ($finalRawTimestamp && empty($seenStatuses['delivered'])) {
                    $completedBy = !empty($completed['delivery_person_name'])
                        ? 'Completed by ' . $completed['delivery_person_name']
                        : 'Completed by delivery team';

                    $timelineEntries[] = [
                        'label' => 'Delivered',
                        'time' => formatTimelineTime($finalRawTimestamp),
                        'meta' => $completedBy,
                        'notes' => trim((string)($completed['delivery_notes'] ?? '')),
                        'statusKey' => 'delivered'
                    ];
                } elseif (!empty($completed['delivery_notes'])) {
                    $lastIndex = count($timelineEntries) - 1;
                    if ($lastIndex >= 0 && empty($timelineEntries[$lastIndex]['notes'])) {
                        $timelineEntries[$lastIndex]['notes'] = trim((string)$completed['delivery_notes']);
                    }
                }

                foreach ($timelineEntries as &$entry) {
                    if (isset($entry['statusKey'])) {
                        unset($entry['statusKey']);
                    }
                }
                unset($entry);

                $customerName = $completed['customer_name'] ?? 'Unknown Customer';
                $customerPhone = $completed['customer_phone'] ?? 'N/A';
                $customerEmail = $completed['customer_email'] ?? '';
                $productType = $completed['cylinder_type'] ?? 'Cylinder';
                $quantity = (int)($completed['quantity'] ?? 0);
                $totalAmount = (float)($completed['total_amount'] ?? 0);
                $totalDisplay = function_exists('formatCurrency') ? formatCurrency($totalAmount) : number_format($totalAmount, 2);
                $deliveredDisplay = formatTimelineTime($completed['delivered_at'] ?: ($completed['updated_at'] ?? $completed['created_at'] ?? ''));
                $address = $completed['full_address'] ?? $completed['delivery_address'] ?? 'Address not specified';
                $deliveryPersonDisplay = !empty($completed['delivery_person_name']) ? $completed['delivery_person_name'] : 'Delivery Team';

                $requestedSlot = '';
                if (!empty($completed['delivery_date']) && !empty($completed['delivery_time'])) {
                    $requestedDateTs = strtotime($completed['delivery_date']);
                    $requestedTimeTs = strtotime($completed['delivery_time']);
                    if ($requestedDateTs !== false && $requestedTimeTs !== false) {
                        $requestedSlot = date('M d, Y', $requestedDateTs) . ' at ' . date('h:i A', $requestedTimeTs);
                    }
                }

                $deliveryNotes = trim((string)($completed['delivery_notes'] ?? ''));
              ?>
              <div class="history-card">
                <div class="history-card-header">
                  <div class="history-card-title">
                    <i class="fas fa-hashtag"></i> Order #<?php echo htmlspecialchars($completedId); ?>
                  </div>
                  <?php if (!empty($deliveredDisplay)): ?>
                    <div class="history-card-time">
                      <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($deliveredDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="history-summary">
                  <div class="history-summary-item">
                    <span class="history-summary-label">Customer</span>
                    <span class="history-summary-value"><i class="fas fa-user"></i> <?php echo htmlspecialchars($customerName); ?></span>
                  </div>
                  <div class="history-summary-item">
                    <span class="history-summary-label">Contact</span>
                    <span class="history-summary-value"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($customerPhone ?: 'N/A'); ?></span>
                  </div>
                  <?php if (!empty($customerEmail)): ?>
                    <div class="history-summary-item">
                      <span class="history-summary-label">Email</span>
                      <span class="history-summary-value"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($customerEmail); ?></span>
                    </div>
                  <?php endif; ?>
                  <div class="history-summary-item">
                    <span class="history-summary-label">Cylinder</span>
                    <span class="history-summary-value"><i class="fas fa-gas-pump"></i> <?php echo htmlspecialchars($productType); ?> (<?php echo htmlspecialchars($quantity); ?>)</span>
                  </div>
                  <div class="history-summary-item">
                    <span class="history-summary-label">Order Total</span>
                    <span class="history-summary-value"><i class="fas fa-receipt"></i> <?php echo htmlspecialchars($totalDisplay); ?></span>
                  </div>
                  <?php if (!empty($deliveredDisplay)): ?>
                    <div class="history-summary-item">
                      <span class="history-summary-label">Delivered On</span>
                      <span class="history-summary-value"><i class="fas fa-calendar-check"></i> <?php echo htmlspecialchars($deliveredDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                  <?php endif; ?>
                  <div class="history-summary-item">
                    <span class="history-summary-label">Delivered By</span>
                    <span class="history-summary-value"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($deliveryPersonDisplay); ?></span>
                  </div>
                  <?php if (!empty($requestedSlot)): ?>
                    <div class="history-summary-item">
                      <span class="history-summary-label">Requested Slot</span>
                      <span class="history-summary-value"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($requestedSlot, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                  <?php endif; ?>
                  <div class="history-summary-item history-summary-item--full">
                    <span class="history-summary-label">Delivery Address</span>
                    <span class="history-summary-value"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($address); ?></span>
                  </div>
                </div>

                <?php if ($deliveryNotes !== ''): ?>
                  <div class="timeline-notes">
                    <strong><i class="fas fa-comment-dots"></i> Delivery Notes</strong>
                    <p><?php echo nl2br(htmlspecialchars($deliveryNotes)); ?></p>
                  </div>
                <?php endif; ?>

                <?php if (!empty($timelineEntries)): ?>
                  <ul class="history-timeline">
                    <?php foreach ($timelineEntries as $entry): ?>
                      <?php if (empty($entry['label']) && empty($entry['time']) && empty($entry['meta']) && empty($entry['notes'])) { continue; } ?>
                      <li class="history-timeline-item">
                        <?php if (!empty($entry['label'])): ?>
                          <div class="history-timeline-status"><?php echo htmlspecialchars($entry['label']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($entry['time'])): ?>
                          <div class="history-timeline-time"><?php echo htmlspecialchars($entry['time'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($entry['meta'])): ?>
                          <div class="history-timeline-meta"><?php echo htmlspecialchars($entry['meta']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($entry['notes'])): ?>
                          <div class="history-timeline-notes"><?php echo nl2br(htmlspecialchars($entry['notes'])); ?></div>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
