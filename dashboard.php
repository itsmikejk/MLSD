
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

function getCount(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    return (int)$result->fetch_row()[0];
}

function dashboardEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* BUSINESS SUMMARY */
$totalOrders = getCount($conn, "SELECT COUNT(*) FROM orders");

$totalCustomers = getCount($conn, "SELECT COUNT(*) FROM customers");

$totalProducts = getCount($conn, "SELECT COUNT(*) FROM products");

$availableProducts = getCount(
    $conn,
    "SELECT COUNT(*) FROM products WHERE is_available = 1"
);

$pendingOrders = getCount(
    $conn,
    "SELECT COUNT(*) FROM orders WHERE status = 'Pending'"
);

$deliveredOrders = getCount(
    $conn,
    "SELECT COUNT(*) FROM orders WHERE status = 'Delivered'"
);

$lowStockIngredients = getCount(
    $conn,
    "SELECT COUNT(*) FROM ingredients
     WHERE quantity_in_stock <= reorder_level"
);

/* REVENUE FROM DELIVERED ORDERS */
$revenueResult = $conn->query(
    "SELECT COALESCE(SUM(total_amount), 0)
     FROM orders
     WHERE status = 'Delivered'"
);

$revenue = (float)$revenueResult->fetch_row()[0];

/* RECENT ORDERS */
$recentOrders = $conn->query(
    "SELECT o.id, c.full_name, o.order_date,
            o.status, o.total_amount
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     ORDER BY o.order_date DESC, o.id DESC
     LIMIT 10"
);

function orderStatusClass(string $status): string
{
    switch ($status) {
        case 'Pending':
            return 'text-bg-warning';

        case 'Confirmed':
            return 'text-bg-info';

        case 'Preparing':
            return 'text-bg-primary';

        case 'Ready':
            return 'text-bg-success';

        case 'Delivered':
            return 'text-bg-success';

        case 'Cancelled':
            return 'text-bg-danger';

        default:
            return 'text-bg-secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Management Dashboard | MLSD</title>

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

        .stat-card,
        .content-card {
            background: #fff;
            border: 0;
            border-radius: 15px;
            box-shadow: 0 4px 15px #0000000a;
        }

        .stat-card {
            height: 100%;
            padding: 20px;
        }

        .stat-number {
            color: #b83267;
            font-size: 1.8rem;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .page-title {
            color: #b83267;
        }

        .quick-link {
            min-width: 145px;
        }

        .table th {
            white-space: nowrap;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark px-3 py-3">
    <a class="navbar-brand fw-bold" href="dashboard.php">
        MLSD Sweets and Delights
    </a>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="text-white">
            Hello, <?= dashboardEscape($_SESSION['admin_name'] ?? 'User') ?>
            (<?= dashboardEscape(ucfirst($_SESSION['admin_role'] ?? 'staff')) ?>)
        </span>

        <a href="logout.php" class="btn btn-light btn-sm">Log out</a>
    </div>
</nav>

<main class="container py-4">

    <h2 class="fw-bold page-title">Management Dashboard</h2>

    <p class="text-muted">
        Welcome to your MLSD Sweets and Delights business overview.
        Review orders, products, and inventory from one place.
    </p>

    <!-- BUSINESS SUMMARY CARDS -->
    <div class="row g-3 my-2">

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-muted">Total Orders</div>
                <div class="stat-number">
                    <?= number_format($totalOrders) ?>
                </div>
                <small class="text-muted">All recorded orders</small>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-muted">Customers</div>
                <div class="stat-number">
                    <?= number_format($totalCustomers) ?>
                </div>
                <small class="text-muted">Registered customers</small>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-muted">Available Cakes</div>
                <div class="stat-number">
                    <?= number_format($availableProducts) ?>
                </div>
                <small class="text-muted">
                    <?= number_format($totalProducts) ?> total products
                </small>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-muted">Pending Orders</div>
                <div class="stat-number">
                    <?= number_format($pendingOrders) ?>
                </div>
                <small class="text-muted">Awaiting further action</small>
            </div>
        </div>

        <div class="col-6 col-lg-4">
            <div class="stat-card">
                <div class="text-muted">Delivered Orders</div>
                <div class="stat-number">
                    <?= number_format($deliveredOrders) ?>
                </div>
                <small class="text-muted">Orders marked Delivered</small>
            </div>
        </div>

        <div class="col-6 col-lg-4">
            <div class="stat-card">
                <div class="text-muted">Low-Stock Ingredients</div>
                <div class="stat-number">
                    <?= number_format($lowStockIngredients) ?>
                </div>
                <small class="text-muted">At or below reorder level</small>

                <?php if ($lowStockIngredients > 0): ?>
                    <a href="inventory.php?stock_filter=low"
                       class="btn btn-sm btn-outline-danger mt-2">
                        Review Low Stock
                    </a>
                <?php else: ?>
                    <div class="text-success small mt-2">
                        No low-stock ingredients
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="stat-card">
                <div class="text-muted">Revenue from Delivered Orders</div>
                <div class="stat-number">
                    ₱<?= number_format($revenue, 2) ?>
                </div>
                <small class="text-muted">
                    Sum of total_amount for delivered orders
                </small>
            </div>
        </div>

    </div>

    <!-- QUICK NAVIGATION -->
    <section class="content-card p-4 mt-4">
        <h4 class="fw-bold mb-3">Quick Navigation</h4>

        <p class="text-muted">
            Choose a module to manage your business.
        </p>

        <div class="d-flex flex-wrap gap-2">

            <a class="btn btn-outline-danger quick-link"
               href="orders.php">
                Manage Orders
            </a>

            <a class="btn btn-outline-danger quick-link"
               href="products.php">
                Manage Cakes
            </a>

            <a class="btn btn-outline-danger quick-link"
               href="inventory.php">
                Manage Inventory
            </a>

            <a class="btn btn-outline-danger quick-link"
               href="suppliers.php">
                Manage Suppliers
            </a>

            <a class="btn btn-outline-danger quick-link"
               href="reports.php">
                Sales Reports
            </a>

            <?php if (isAdmin()): ?>
                <a class="btn btn-outline-primary quick-link"
                   href="users.php">
                    User Management
                </a>

                <a class="btn btn-outline-primary quick-link"
                   href="etl.php">
                    Run Data Warehouse ETL
                </a>
            <?php endif; ?>

        </div>
    </section>

    <!-- RECENT ORDERS -->
    <section class="content-card p-4 mt-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 class="fw-bold mb-1">Recent Orders</h4>
                <p class="text-muted mb-0">
                    The 10 most recently recorded orders.
                </p>
            </div>

            <a href="orders.php" class="btn btn-pink btn-danger">
                View All Orders
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($recentOrders->num_rows === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No orders yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($order = $recentOrders->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong>
                                    #<?= (int)$order['id'] ?>
                                </strong>
                            </td>

                            <td>
                                <?= dashboardEscape($order['full_name']) ?>
                            </td>

                            <td>
                                <?= dashboardEscape($order['order_date']) ?>
                            </td>

                            <td>
                                <span class="badge <?= orderStatusClass((string)$order['status']) ?>">
                                    <?= dashboardEscape($order['status']) ?>
                                </span>
                            </td>

                            <td class="text-end">
                                ₱<?= number_format((float)$order['total_amount'], 2) ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                </tbody>

            </table>
        </div>
    </section>

    <p class="text-muted small mt-3">
        Dashboard figures are calculated from the current operational
        database. Revenue includes only orders marked Delivered.
    </p>

</main>

</body>
</html>
