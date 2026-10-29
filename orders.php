
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$message = '';

$allowedStatuses = [
    'Pending',
    'Confirmed',
    'Preparing',
    'Ready',
    'Delivered',
    'Cancelled'
];

/* UPDATE ORDER STATUS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $orderId = filter_input(
            INPUT_POST,
            'order_id',
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['status'] ?? '';

        if (
            !$orderId ||
            !in_array($newStatus, $allowedStatuses, true)
        ) {
            $error = 'Please select a valid order and status.';
        } else {
            $stmt = $conn->prepare(
                "UPDATE orders SET status = ? WHERE id = ?"
            );

            $stmt->bind_param("si", $newStatus, $orderId);
            $stmt->execute();
            $stmt->close();

            // Verify the saved status, including when it was unchanged.
            $check = $conn->prepare(
                "SELECT id, status FROM orders WHERE id = ? LIMIT 1"
            );

            $check->bind_param("i", $orderId);
            $check->execute();
            $updatedOrder = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$updatedOrder) {
                $error = 'Order not found.';
            } elseif ($updatedOrder['status'] === $newStatus) {
                $message = "Order #$orderId status is now $newStatus.";
            } else {
                $error = 'The order could not be updated.';
            }
        }
    }
}

/* SEARCH AND FILTER */
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status_filter'] ?? '';

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$searchLike = '%' . $search . '%';

$sql = "
    SELECT
        o.id,
        o.order_date,
        o.event_date,
        o.status,
        o.total_amount,
        o.notes,
        c.full_name,
        c.email,
        c.phone,
        c.address,
        GROUP_CONCAT(
            CONCAT(oi.cake_name, ' x', oi.quantity)
            ORDER BY oi.id SEPARATOR ', '
        ) AS cake_items
    FROM orders o
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
";

$where = [];

if ($search !== '') {
    $where[] = "(
        CAST(o.id AS CHAR) LIKE ?
        OR c.full_name LIKE ?
        OR c.email LIKE ?
        OR c.phone LIKE ?
        OR oi.cake_name LIKE ?
    )";
}

if ($statusFilter !== '') {
    $where[] = "o.status = ?";
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    GROUP BY
        o.id,
        o.order_date,
        o.event_date,
        o.status,
        o.total_amount,
        o.notes,
        c.full_name,
        c.email,
        c.phone,
        c.address
    ORDER BY o.order_date DESC
";

$stmt = $conn->prepare($sql);

if ($search !== '' && $statusFilter !== '') {
    $stmt->bind_param(
        "ssssss",
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $statusFilter
    );
} elseif ($search !== '') {
    $stmt->bind_param(
        "sssss",
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
} elseif ($statusFilter !== '') {
    $stmt->bind_param("s", $statusFilter);
}

$stmt->execute();
$orders = $stmt->get_result();

/* ESCAPE OUTPUT */
function orderHtml($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function orderPeso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Manage Orders | MLSD Sweets and Delights</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #fff5f7;
            color: #4a2634;
        }

        .navbar {
            background: #b83267;
        }

        .panel {
            background: white;
            border: 0;
            border-radius: 15px;
            box-shadow: 0 4px 18px #0000000c;
        }

        .page-title {
            color: #b83267;
        }

        .table th {
            white-space: nowrap;
        }

        .filter-panel {
            background: white;
            border-radius: 15px;
            padding: 18px;
            box-shadow: 0 4px 18px #0000000c;
        }

        .order-details {
            min-width: 190px;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark px-3 py-3">
    <a class="navbar-brand fw-bold" href="dashboard.php">
        MLSD Sweets and Delights
    </a>

    <div class="d-flex flex-wrap gap-2">
        <a href="dashboard.php" class="btn btn-light btn-sm">
            Dashboard
        </a>

        <a href="products.php" class="btn btn-outline-light btn-sm">
            Cakes
        </a>

        <?php if (isAdmin()): ?>
            <a href="users.php" class="btn btn-outline-light btn-sm">
                User Management
            </a>

            <a href="etl.php" class="btn btn-outline-light btn-sm">
                Run ETL
            </a>
        <?php endif; ?>

        <a href="logout.php" class="btn btn-outline-light btn-sm">
            Log out
        </a>
    </div>
</nav>

<main class="container-fluid container-lg py-4">

    <h2 class="fw-bold page-title">Customer Orders</h2>

    <p class="text-muted">
        Search customer orders, review order details, and update order progress.
    </p>

    <p class="small text-muted">
        Logged in as:
        <strong><?= orderHtml($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'User') ?></strong>
        (<?= orderHtml(ucfirst($_SESSION['admin_role'] ?? 'staff')) ?>)
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success" role="alert">
            <?= orderHtml($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= orderHtml($error) ?>
        </div>
    <?php endif; ?>

    <!-- SEARCH AND FILTER -->
    <section class="filter-panel mb-4">
        <form method="GET" action="orders.php">
            <div class="row g-3 align-items-end">

                <div class="col-md-6">
                    <label for="search" class="form-label">
                        Search orders
                    </label>

                    <input
                        type="search"
                        class="form-control"
                        id="search"
                        name="search"
                        placeholder="Order number, customer, phone, email, or cake"
                        value="<?= orderHtml($search) ?>"
                    >
                </div>

                <div class="col-md-3">
                    <label for="status_filter" class="form-label">
                        Filter by status
                    </label>

                    <select
                        class="form-select"
                        id="status_filter"
                        name="status_filter"
                    >
                        <option value="">All statuses</option>

                        <?php foreach ($allowedStatuses as $statusOption): ?>
                            <option
                                value="<?= orderHtml($statusOption) ?>"
                                <?= $statusFilter === $statusOption ? 'selected' : '' ?>
                            >
                                <?= orderHtml($statusOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-danger">
                        Search
                    </button>

                    <a href="orders.php" class="btn btn-outline-secondary">
                        Reset
                    </a>
                </div>

            </div>
        </form>
    </section>

    <!-- ORDERS TABLE -->
    <section class="panel p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Order Records</h5>

            <span class="text-muted small">
                Results matching your search and filter
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Cake(s) and Notes</th>
                        <th>Event Date</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Update Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($orders->num_rows === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No matching orders found.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php while ($order = $orders->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong>#<?= (int)$order['id'] ?></strong>
                                <br>

                                <small class="text-muted">
                                    <?= orderHtml($order['order_date']) ?>
                                </small>
                            </td>

                            <td class="order-details">
                                <strong>
                                    <?= orderHtml($order['full_name']) ?>
                                </strong>
                                <br>

                                <small>
                                    <?= orderHtml($order['phone']) ?>
                                </small>
                                <br>

                                <small>
                                    <?= orderHtml($order['email']) ?>
                                </small>
                                <br>

                                <?php if (!empty($order['address'])): ?>
                                    <details class="mt-1">
                                        <summary>Address</summary>
                                        <small>
                                            <?= orderHtml($order['address']) ?>
                                        </small>
                                    </details>
                                <?php endif; ?>
                            </td>

                            <td class="order-details">
                                <?= orderHtml($order['cake_items'] ?? 'No items') ?>

                                <?php if (!empty($order['notes'])): ?>
                                    <details class="mt-2">
                                        <summary>Design notes</summary>
                                        <small>
                                            <?= nl2br(orderHtml($order['notes'])) ?>
                                        </small>
                                    </details>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= orderHtml($order['event_date']) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= orderPeso($order['total_amount']) ?>
                                </strong>
                            </td>

                            <td>
                                <?php
                                $statusClass = 'text-bg-secondary';

                                switch ($order['status']) {
                                    case 'Pending':
                                        $statusClass = 'text-bg-warning';
                                        break;
                                    case 'Confirmed':
                                        $statusClass = 'text-bg-info';
                                        break;
                                    case 'Preparing':
                                        $statusClass = 'text-bg-primary';
                                        break;
                                    case 'Ready':
                                        $statusClass = 'text-bg-success';
                                        break;
                                    case 'Delivered':
                                        $statusClass = 'text-bg-success';
                                        break;
                                    case 'Cancelled':
                                        $statusClass = 'text-bg-danger';
                                        break;
                                }
                                ?>

                                <span class="badge <?= $statusClass ?>">
                                    <?= orderHtml($order['status']) ?>
                                </span>
                            </td>

                            <td>
                                <form method="POST" action="orders.php"
                                      class="d-flex flex-column gap-2">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= orderHtml($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= (int)$order['id'] ?>"
                                    >

                                    <select
                                        name="status"
                                        class="form-select form-select-sm"
                                        aria-label="New status for order <?= (int)$order['id'] ?>"
                                        required
                                    >
                                        <?php foreach ($allowedStatuses as $statusOption): ?>
                                            <option
                                                value="<?= orderHtml($statusOption) ?>"
                                                <?= $order['status'] === $statusOption ? 'selected' : '' ?>
                                            >
                                                <?= orderHtml($statusOption) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button
                                        class="btn btn-danger btn-sm"
                                        type="submit"
                                    >
                                        Update
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php endif; ?>
                </tbody>

            </table>
        </div>
    </section>

    <p class="text-muted small mt-3">
        Order totals and statuses are taken from the operational database.
        Reports in the Data Warehouse may require a new ETL run to reflect
        the latest status changes.
    </p>

</main>

</body>
</html>
