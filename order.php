
<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function checkoutItems(mysqli $conn): array {
    $items = [];
    $total = 0.0;

    $stmt = $conn->prepare(
        "SELECT id, name, price
         FROM products
         WHERE id = ? AND is_available = 1"
    );

    foreach ($_SESSION['cart'] as $key => $cartItem) {
        if (is_array($cartItem)) {
            $productId = (int)($cartItem['product_id'] ?? $cartItem['id'] ?? $key);
            $quantity = (int)($cartItem['quantity'] ?? 1);
            $customization = trim((string)($cartItem['customization'] ?? ''));
        } else {
            $productId = (int)$key;
            $quantity = (int)$cartItem;
            $customization = '';
        }

        if ($productId < 1) {
            continue;
        }

        $quantity = max(1, min(20, $quantity));

        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if (!$product) {
            continue;
        }

        $price = (float)$product['price'];
        $subtotal = $price * $quantity;
        $total += $subtotal;

        $items[] = [
            'product_id' => (int)$product['id'],
            'name' => (string)$product['name'],
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal,
            'customization' => $customization
        ];
    }

    $stmt->close();

    return ['items' => $items, 'total' => $total];
}

if (empty($_SESSION['checkout_token'])) {
    $_SESSION['checkout_token'] = bin2hex(random_bytes(32));
}

$message = '';
$success = false;
$orderId = 0;
$items = [];
$total = 0.0;
$downpayment = 0.0;

try {
    $checkout = checkoutItems($conn);
    $items = $checkout['items'];
    $total = (float)$checkout['total'];
    $downpayment = round($total * 0.50, 2);
} catch (Throwable $e) {
    error_log('MLSD cart error: ' . $e->getMessage());
    $message = 'We could not load your cart. Check that your products table is available.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message === '') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $eventDate = trim((string)($_POST['event_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $token = (string)($_POST['checkout_token'] ?? '');

    $transactionStarted = false;
    $proofPath = null;

    try {
        if (!hash_equals((string)$_SESSION['checkout_token'], $token)) {
            throw new RuntimeException('Your form expired. Refresh the page and try again.');
        }

        if (!$items || $total <= 0) {
            throw new RuntimeException('Your cart is empty. Please add a cake before checkout.');
        }

        if ($fullName === '' || strlen($fullName) > 120) {
            throw new RuntimeException('Please enter your name (up to 120 characters).');
        }

        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        if (strlen($phone) > 30) {
            throw new RuntimeException('Phone number must be 30 characters or fewer.');
        }

        if ($eventDate !== '') {
            $date = DateTime::createFromFormat('!Y-m-d', $eventDate);

            if (!$date || $date->format('Y-m-d') !== $eventDate) {
                throw new RuntimeException('Please enter a valid event date.');
            }
        } else {
            $eventDate = null;
        }

        if (!in_array($paymentMethod, ['GCash', 'Bank Transfer'], true)) {
            throw new RuntimeException('Please choose GCash or Bank Transfer.');
        }

        if (
            !isset($_FILES['payment_proof']) ||
            $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('Please upload payment proof (JPG, PNG, or WEBP; max 5 MB).');
        }

        $file = $_FILES['payment_proof'];

        if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Payment proof must be no larger than 5 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($allowedTypes[$mime])) {
            throw new RuntimeException('Payment proof must be a JPG, PNG, or WEBP image.');
        }

        $uploadDir = __DIR__ . '/uploads/payment_proofs/';

        if (
            !is_dir($uploadDir) &&
            !mkdir($uploadDir, 0755, true) &&
            !is_dir($uploadDir)
        ) {
            throw new RuntimeException('Could not create the payment proof folder.');
        }

        $proofName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mime];
        $proofPath = $uploadDir . $proofName;

        if (!move_uploaded_file($file['tmp_name'], $proofPath)) {
            $proofPath = null;
            throw new RuntimeException('Could not upload payment proof. Please try again.');
        }

        $conn->begin_transaction();
        $transactionStarted = true;

        // Find an existing customer when an email was provided.
        $customerId = 0;

        if ($email !== '') {
            $stmt = $conn->prepare(
                "SELECT id FROM customers WHERE email = ? LIMIT 1"
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();

            if ($customer) {
                $customerId = (int)$customer['id'];
            }

            $stmt->close();
        }

        if ($customerId > 0) {
            $stmt = $conn->prepare(
                "UPDATE customers
                 SET full_name = ?, phone = ?, address = ?
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'sssi',
                $fullName,
                $phone,
                $address,
                $customerId
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $emailValue = $email !== '' ? $email : null;
            $phoneValue = $phone !== '' ? $phone : null;
            $addressValue = $address !== '' ? $address : null;

            $stmt = $conn->prepare(
                "INSERT INTO customers (full_name, email, phone, address)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'ssss',
                $fullName,
                $emailValue,
                $phoneValue,
                $addressValue
            );
            $stmt->execute();
            $customerId = (int)$conn->insert_id;
            $stmt->close();
        }

        // Create the order.
        $status = 'Pending';
        $notesValue = $notes !== '' ? $notes : null;

        $stmt = $conn->prepare(
            "INSERT INTO orders
             (customer_id, event_date, status, total_amount, notes)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'issds',
            $customerId,
            $eventDate,
            $status,
            $total,
            $notesValue
        );
        $stmt->execute();
        $orderId = (int)$conn->insert_id;
        $stmt->close();

        // Save the ordered cakes.
        $stmt = $conn->prepare(
            "INSERT INTO order_items
             (order_id, product_id, cake_name, quantity,
              unit_price, subtotal, customization)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $cakeName = (string)$item['name'];
            $quantity = (int)$item['quantity'];
            $unitPrice = (float)$item['price'];
            $subtotal = (float)$item['subtotal'];
            $customization = $item['customization'] !== ''
                ? $item['customization']
                : null;

            $stmt->bind_param(
                'iisidds',
                $orderId,
                $productId,
                $cakeName,
                $quantity,
                $unitPrice,
                $subtotal,
                $customization
            );

            $stmt->execute();
        }

        $stmt->close();

        // IMPORTANT: match your database column and enum value.
        $paymentStatus = 'Pending Verification';

        $stmt = $conn->prepare(
            "INSERT INTO order_payments
             (order_id, payment_method, amount, proof_file, payment_status)
             VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'isdss',
            $orderId,
            $paymentMethod,
            $downpayment,
            $proofName,
            $paymentStatus
        );

        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $transactionStarted = false;

        $_SESSION['cart'] = [];
        $_SESSION['checkout_token'] = bin2hex(random_bytes(32));

        $success = true;
        $items = [];
        $total = 0.0;
        $downpayment = 0.0;

    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('MLSD rollback error: ' . $rollbackError->getMessage());
            }
        }

        if ($proofPath !== null && is_file($proofPath)) {
            @unlink($proofPath);
        }

        error_log('MLSD checkout error: ' . $e->getMessage());

        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'We could not save your order. Please check your database tables and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout | MLSD Sweets and Delights</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #fff8f5;
            color: #422d2d;
        }

        .navbar, .footer {
            background: #6f3e46;
        }

        .navbar-brand, .nav-link, .footer {
            color: #fff !important;
        }

        .checkout-card {
            background: #fff;
            border: 0;
            border-radius: 16px;
            box-shadow: 0 5px 22px #6f3e4612;
        }

        .btn-cake {
            background: #8f4f5b;
            color: #fff;
            border: 0;
        }

        .btn-cake:hover {
            background: #6f3e46;
            color: #fff;
        }

        .price {
            color: #8f4f5b;
            font-weight: 700;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            MLSD Sweets and Delights
        </a>
        <a class="nav-link" href="cart.php">Back to Cart</a>
    </div>
</nav>

<main class="container py-5">
    <h1 class="text-center mb-2">Checkout</h1>
    <p class="text-center text-secondary mb-4">
        Complete your cake order details below.
    </p>

    <?php if ($success): ?>

        <div class="alert alert-success checkout-card p-4">
            <h2 class="h4">Order submitted successfully!</h2>
            <p>
                Your order number is
                <strong>#<?= e($orderId) ?></strong>.
            </p>
            <p>Your payment is awaiting admin verification.</p>
            <a class="btn btn-cake" href="index.php">Return to Home</a>
        </div>

    <?php else: ?>

        <?php if ($message !== ''): ?>
            <div class="alert alert-danger">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!$items): ?>

            <div class="checkout-card p-4 text-center">
                <p>Your cart is empty or has no available products.</p>
                <a class="btn btn-cake" href="index.php">Browse Cakes</a>
            </div>

        <?php else: ?>

            <div class="row g-4">

                <div class="col-lg-7">
                    <div class="checkout-card p-4">
                        <h2 class="h4 mb-3">Customer and Event Details</h2>

                        <form method="post" enctype="multipart/form-data">
                            <input
                                type="hidden"
                                name="checkout_token"
                                value="<?= e($_SESSION['checkout_token']) ?>"
                            >

                            <div class="mb-3">
                                <label class="form-label" for="full_name">
                                    Full Name *
                                </label>
                                <input
                                    class="form-control"
                                    id="full_name"
                                    name="full_name"
                                    maxlength="120"
                                    required
                                    value="<?= e($_POST['full_name'] ?? '') ?>"
                                >
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="email">
                                        Email
                                    </label>
                                    <input
                                        class="form-control"
                                        type="email"
                                        id="email"
                                        name="email"
                                        maxlength="150"
                                        value="<?= e($_POST['email'] ?? '') ?>"
                                    >
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="phone">
                                        Phone Number
                                    </label>
                                    <input
                                        class="form-control"
                                        id="phone"
                                        name="phone"
                                        maxlength="30"
                                        value="<?= e($_POST['phone'] ?? '') ?>"
                                    >
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="address">
                                    Delivery Address
                                </label>
                                <textarea
                                    class="form-control"
                                    id="address"
                                    name="address"
                                    rows="2"
                                ><?= e($_POST['address'] ?? '') ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="event_date">
                                    Event Date
                                </label>
                                <input
                                    class="form-control"
                                    type="date"
                                    id="event_date"
                                    name="event_date"
                                    min="<?= e(date('Y-m-d')) ?>"
                                    value="<?= e($_POST['event_date'] ?? '') ?>"
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="notes">
                                    Cake Customization / Order Notes
                                </label>
                                <textarea
                                    class="form-control"
                                    id="notes"
                                    name="notes"
                                    rows="3"
                                    placeholder="Design, flavor, message on cake, delivery instructions..."
                                ><?= e($_POST['notes'] ?? '') ?></textarea>
                            </div>

                            <hr>

                            <h2 class="h4 mb-3">Payment</h2>
                            <p>
                                Pay the 50% downpayment shown in the order summary,
                                then upload your payment proof.
                            </p>

                            <div class="mb-3">
                                <label class="form-label" for="payment_method">
                                    Payment Method *
                                </label>
                                <select
                                    class="form-select"
                                    id="payment_method"
                                    name="payment_method"
                                    required
                                >
                                    <option value="">Choose payment method</option>
                                    <option
                                        value="GCash"
                                        <?= (($_POST['payment_method'] ?? '') === 'GCash') ? 'selected' : '' ?>
                                    >GCash</option>
                                    <option
                                        value="Bank Transfer"
                                        <?= (($_POST['payment_method'] ?? '') === 'Bank Transfer') ? 'selected' : '' ?>
                                    >Bank Transfer</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="payment_proof">
                                    Payment Proof (JPG, PNG, WEBP; max 5 MB) *
                                </label>
                                <input
                                    class="form-control"
                                    type="file"
                                    id="payment_proof"
                                    name="payment_proof"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    required
                                >
                            </div>

                            <button class="btn btn-cake btn-lg w-100" type="submit">
                                Submit Order
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="checkout-card p-4">
                        <h2 class="h4 mb-3">Order Summary</h2>

                        <?php foreach ($items as $item): ?>
                            <div class="d-flex justify-content-between gap-3 mb-3">
                                <div>
                                    <strong><?= e($item['name']) ?></strong>
                                    <div class="text-secondary small">
                                        Qty: <?= e($item['quantity']) ?>
                                        × ₱<?= number_format($item['price'], 2) ?>
                                    </div>

                                    <?php if ($item['customization'] !== ''): ?>
                                        <div class="text-secondary small">
                                            <?= e($item['customization']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="text-nowrap">
                                    ₱<?= number_format($item['subtotal'], 2) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <hr>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Total</span>
                            <strong>₱<?= number_format($total, 2) ?></strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>50% Downpayment</span>
                            <strong class="price">
                                ₱<?= number_format($downpayment, 2) ?>
                            </strong>
                        </div>

                        <p class="small text-secondary mb-0">
                            Your order remains pending until it is reviewed by the business.
                        </p>
                    </div>
                </div>

            </div>

        <?php endif; ?>
    <?php endif; ?>
</main>

<footer class="footer text-center py-3 mt-4">
    Baked with Love, Made for Your Moments.
</footer>

</body>
</html>
