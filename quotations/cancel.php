<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'quotations.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('quotations/index.php');
}

$branchId = current_branch_id();
$quotationId = (int)($_POST['id'] ?? 0);

try {
    $stmt = $pdo->prepare('
        UPDATE quotations
        SET status = "cancelled"
        WHERE id = ? AND branch_id = ? AND status IN ("draft", "issued")
    ');
    $stmt->execute([$quotationId, $branchId]);

    if ($stmt->rowCount() === 1) {
        log_activity($pdo, 'cancel_quotation', 'quotations', 'Cancelled quotation ID ' . $quotationId);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quotation cancelled successfully.'];
    } else {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Quotation could not be cancelled. It may already be converted or cancelled.'];
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to cancel quotation. Please try again.'];
}

redirect_to('quotations/view.php?id=' . $quotationId);
