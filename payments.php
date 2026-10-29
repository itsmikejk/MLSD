<?php
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['payment_csrf'])) {
    $_SESSION['payment_csrf'] = bin2hex(random_bytes(32));
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['payment_csrf'], $token)
    ) {
        $message = 'Invalid request. Please refresh the page and try again.';
    } elseif (
        !$paymentId ||
        !in_array($action, ['approve', 'reject'], true)
    ) {
        $message = 'Invalid payment action.';
    } else {
        $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare(
                "SELECT order_id, status
                 FROM order_payments
                 WHERE id = ?
                 FOR UPDATE"
            );
            $stmt->bind_param("i", $paymentId);
            $stmt->execute();

            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$payment) {
                throw new RuntimeException('Payment record not found.');
            }

            if ($payment['status'] !== 'Pending') {
                throw new RuntimeException(
                    'This payment has already been reviewed.'
                );
            }

            $orderId = (int)$payment['order_id'];

            $stmt = $conn->prepare(
                "UPDATE order_payments
                 SET status = ?, admin_note = ?, reviewed_at = NOW()
                 WHERE id = ? AND status = 'Pending'"
            );
            $stmt->bind_param(
                "ssi",
                $newStatus,
                $adminNote,
                $paymentId
            );
            $stmt->execute();
            $stmt->close();

            if ($action === 'approve') {
                $orderStatus = 'Confirmed';
            } else {
                $orderStatus = 'Pending';
            }

            $stmt = $conn->prepare(
                "UPDATE orders SET status = ? WHERE id = ?"
            );
            $stmt->bind_param("si", $orderStatus, $orderId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $message = ($action === 'approve')
                ? 'Payment approved. The order is now confirmed.'
                : 'Payment rejected. The order remains pending.';
        } catch (Throwable $exception) {
            $conn->rollback();

            error_log('MLSD payment review error: ' . $exception->getMessage());

            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Unable to update the payment. Please check the database.';
        }
    }
}

$sql = "
    SELECT
        p.id AS payment_id,
        p.order_id,
        p.payment_method,
        p.amount,
        p.proof_file,
        p.status AS payment_status,
        p.admin_note,
        p.created_at,
        o.total_amount,
        o.status AS order_status,
        o.event_date,
        c.full_name,
        c.email,
        c.phone
    FROM order_payments p
    INNER JOIN orders o ON o.id = p.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    ORDER BY p.created_at DESC
";

$result = $conn->query($sql);

function paymentEscape($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification - MLSD Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #fff8f5;
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #8b4557;
        }

        .navbar a {
            color: white;
        }

        .payment-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
        }

        .receipt-image {
            max-width: 100%;
            max-height: 400px;
            object-fit: contain;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg mb-4">
    <div class="container">
        <a class="navbar-brand text-white fw-bold" href="dashboard.php">
            MLSD Admin
        </a>

        <div class="ms-auto d-flex gap-3">
            <a class="nav-link" href="orders.php">Orders</a>
            <a class="nav-link" href="payments.php">Payments</a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <h2 class="mb-4">Payment Verification</h2>

    <?php if ($message !== ''): ?>
        <div class="alert alert-info">
            <?= paymentEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($result && $result->num_rows > 0): ?>

        <?php while ($payment = $result->fetch_assoc()): ?>
            <div class="payment-card">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <h5>
                            Order #<?= (int)$payment['order_id'] ?>
                        </h5>

                        <p class="mb-1">
                            <strong>Customer:</strong>
                            <?= paymentEscape($payment['full_name']) ?>
                        </p>

                        <p class="mb-1">
                            <strong>Email:</strong>
                            <?= paymentEscape($payment['email']) ?>
                        </p>

                        <p class="mb-1">
                            <strong>Phone:</strong>
                            <?= paymentEscape($payment['phone']) ?>
                        </p>

                        <p class="mb-1">
                            <strong>Event date:</strong>
                            <?= paymentEscape($payment['event_date']) ?>
                        </p>

                        <hr>

                        <p class="mb-1">
                            <strong>Payment method:</strong>
                            <?= paymentEscape($payment['payment_method']) ?>
                        </p>

                        <p class="mb-1">
                            <strong>Downpayment submitted:</strong>
                            ₱<?= number_format((float)$payment['amount'], 2) ?>
                        </p>

                        <p class="mb-1">
                            <strong>Order total:</strong>
                            ₱<?= number_format((float)$payment['total_amount'], 2) ?>
                        </p>

                        <p class="mb-1">
                            <strong>Payment status:</strong>
                            <?= paymentEscape($payment['payment_status']) ?>
                        </p>

                        <p class="mb-1">
                            <strong>Order status:</strong>
                            <?= paymentEscape($payment['order_status']) ?>
                        </p>

                        <?php if (!empty($payment['admin_note'])): ?>
                            <p class="mt-2">
                                <strong>Admin note:</strong>
                                <?= nl2br(paymentEscape($payment['admin_note'])) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-7">
                        <h5 class="mb-3">Uploaded Receipt</h5>

                        <?php
                        $filename = basename($payment['proof_file']);
                        $receiptUrl = '../uploads/payment_proofs/' .
                            rawurlencode($filename);
                        ?>

                        <a href="<?= paymentEscape($receiptUrl) ?>"
                           target="_blank" rel="noopener">
                            <img
                                src="<?= paymentEscape($receiptUrl) ?>"
                                alt="Payment receipt for order"
                                class="receipt-image"
                            >
                        </a>

                        <p class="small text-muted mt-2">
                            Click the receipt to open it in a new tab.
                        </p>

                        <?php if ($payment['payment_status'] === 'Pending'): ?>
                            <form method="POST" action="payments.php"
                                  class="mt-3">
                                <input type="hidden" name="csrf_token"
                                       value="<?= paymentEscape($_SESSION['payment_csrf']) ?>">

                                <input type="hidden" name="payment_id"
                                       value="<?= (int)$payment['payment_id'] ?>">

                                <div class="mb-3">
                                    <label class="form-label">
                                        Admin note (optional)
                                    </label>

                                    <textarea name="admin_note"
                                              class="form-control"
                                              rows="2"
                                              maxlength="2000"></textarea>
                                </div>

                                <button
                                    type="submit"
                                    name="action"
                                    value="approve"
                                    class="btn btn-success"
                                    onclick="return confirm('Approve this payment and confirm the order?');">
                                    Approve Payment
                                </button>

                                <button
                                    type="submit"
                                    name="action"
                                    value="reject"
                                    class="btn btn-danger"
                                    onclick="return confirm('Reject this payment?');">
                                    Reject Payment
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="text-muted mt-3">
                                This payment has already been reviewed.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>

    <?php else: ?>

        <div class="alert alert-secondary">
            No payment submissions found yet.
        </div>

    <?php endif; ?>
</div>

</body>
</html>