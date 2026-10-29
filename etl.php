
<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['etl_csrf'])) {
    $_SESSION['etl_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$processed = null;

/*
 * IMPORTANT:
 * Use the SAME host, username, and password as your existing
 * config/database.php, but change the database name to mlsd_dw.
 *
 * Default XAMPP example:
 */
$dwConn = new mysqli('localhost', 'root', '', 'mlsd_dw');
$dwConn->set_charset('utf8mb4');

if ($dwConn->connect_error) {
    error_log($dwConn->connect_error);
    die('Could not connect to the Data Warehouse. Check its database settings.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['etl_csrf'], $token)) {
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        try {
            /*
             * Extract data from the operational database.
             * $conn is the connection from config/database.php.
             */
            $sql = "
                SELECT
                    oi.id AS order_item_id,
                    o.id AS order_id,
                    o.order_date,
                    o.event_date,
                    o.status AS order_status,
                    o.customer_id,
                    oi.product_id,
                    oi.cake_name,
                    oi.quantity,
                    oi.unit_price,
                    oi.subtotal
                FROM orders o
                INNER JOIN order_items oi ON oi.order_id = o.id
                ORDER BY o.id, oi.id
            ";

            $result = $conn->query($sql);

            $dwConn->begin_transaction();

            $insert = $dwConn->prepare("
                INSERT INTO fact_sales (
                    order_item_id,
                    order_id,
                    order_date,
                    event_date,
                    order_status,
                    customer_id,
                    product_id,
                    cake_name,
                    quantity,
                    unit_price,
                    subtotal
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    order_id = VALUES(order_id),
                    order_date = VALUES(order_date),
                    event_date = VALUES(event_date),
                    order_status = VALUES(order_status),
                    customer_id = VALUES(customer_id),
                    product_id = VALUES(product_id),
                    cake_name = VALUES(cake_name),
                    quantity = VALUES(quantity),
                    unit_price = VALUES(unit_price),
                    subtotal = VALUES(subtotal)
            ");

            $processed = 0;

            while ($row = $result->fetch_assoc()) {
                $orderItemId = (int)$row['order_item_id'];
                $orderId = (int)$row['order_id'];
                $orderDate = $row['order_date'];
                $eventDate = $row['event_date'];
                $status = $row['order_status'];
                $customerId = (int)$row['customer_id'];

                $productId = $row['product_id'] !== null
                    ? (int)$row['product_id']
                    : null;

                $cakeName = $row['cake_name'];
                $quantity = (int)$row['quantity'];
                $unitPrice = (float)$row['unit_price'];
                $subtotal = (float)$row['subtotal'];

                $insert->bind_param(
                    'iisssiisidd',
                    $orderItemId,
                    $orderId,
                    $orderDate,
                    $eventDate,
                    $status,
                    $customerId,
                    $productId,
                    $cakeName,
                    $quantity,
                    $unitPrice,
                    $subtotal
                );

                $insert->execute();
                $processed++;
            }

            $dwConn->commit();

            $message = 'ETL completed successfully. Warehouse records processed: '
                . $processed . '.';
        } catch (Throwable $e) {
            try {
                $dwConn->rollback();
            } catch (Throwable $ignored) {
                // The transaction may not have started.
            }

            error_log($e->getMessage());
            $error = 'ETL failed. Check your PHP error log and database table names.';
        }
    }
}

$countResult = $dwConn->query("SELECT COUNT(*) AS total FROM fact_sales");
$warehouseCount = (int)$countResult->fetch_assoc()['total'];

$dwConn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Warehouse ETL | MLSD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <style>
        body { background: #fff5f7; color: #4a2634; }
        .navbar { background: #b83267; }
        .panel {
            background: white;
            border: 0;
            border-radius: 15px;
            box-shadow: 0 4px 18px #0000000c;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
    <a class="navbar-brand fw-bold" href="dashboard.php">MLSD Admin</a>
    <div>
        <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
        <a href="orders.php" class="btn btn-outline-light btn-sm">Orders</a>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Log out</a>
    </div>
</nav>

<main class="container py-5" style="max-width: 800px;">
    <div class="panel p-4 p-md-5">
        <h2>Data Warehouse ETL</h2>
        <p class="text-muted">
            Extract order data, transform it into a reporting format,
            and load it into the MLSD Data Warehouse.
        </p>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="border rounded p-3 mb-4">
            <p class="mb-1">Operational database</p>
            <strong>mlsd_sweets</strong>

            <hr>

            <p class="mb-1">Warehouse database</p>
            <strong>mlsd_dw.fact_sales</strong>

            <hr>

            <p class="mb-1">Records currently in warehouse</p>
            <h3><?= $warehouseCount ?></h3>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['etl_csrf'], ENT_QUOTES, 'UTF-8') ?>">

            <button type="submit" class="btn btn-danger btn-lg">
                Run ETL Process
            </button>
        </form>

        <p class="small text-muted mt-3 mb-0">
            Run this after new orders arrive or their statuses change.
            Running it again updates existing records instead of duplicating
            the same order items.
        </p>
    </div>
</main>
</body>
</html>
