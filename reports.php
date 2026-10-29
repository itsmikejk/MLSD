
<?php
session_start();

require_once 'auth.php';

/* Data Warehouse connection */
$dwConn = new mysqli('localhost', 'root', '', 'mlsd_dw');

if ($dwConn->connect_error) {
    die('Data Warehouse connection failed: ' . $dwConn->connect_error);
}

$dwConn->set_charset('utf8mb4');

/* Date filters */
$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

$dateCondition = '';
$paymentDateCondition = '';

if (
    $startDate !== '' &&
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)
) {
    $safeStartDate = $dwConn->real_escape_string($startDate);

    $dateCondition .= " AND order_date >= '$safeStartDate'";
    $paymentDateCondition .= " AND submitted_at >= '$safeStartDate'";
}

if (
    $endDate !== '' &&
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
) {
    $nextDay = date('Y-m-d', strtotime($endDate . ' +1 day'));
    $safeNextDay = $dwConn->real_escape_string($nextDay);

    $dateCondition .= " AND order_date < '$safeNextDay'";
    $paymentDateCondition .= " AND submitted_at < '$safeNextDay'";
}

/* Summary cards */
$summarySql = "
    SELECT
        COALESCE(SUM(
            CASE WHEN order_status = 'Delivered'
            THEN subtotal ELSE 0 END
        ), 0) AS total_sales,

        COUNT(DISTINCT CASE
            WHEN order_status = 'Delivered' THEN order_id
        END) AS delivered_orders,

        COUNT(DISTINCT order_id) AS all_orders,

        COALESCE(SUM(
            CASE WHEN order_status = 'Delivered'
            THEN quantity ELSE 0 END
        ), 0) AS cakes_sold

    FROM fact_sales
    WHERE 1 = 1
    $dateCondition
";

$summaryResult = $dwConn->query($summarySql);

if (!$summaryResult) {
    die('Summary query failed: ' . $dwConn->error);
}

$summary = $summaryResult->fetch_assoc();

/* Monthly sales */
$monthlySql = "
    SELECT
        DATE_FORMAT(order_date, '%Y-%m') AS sales_month,
        SUM(subtotal) AS monthly_sales
    FROM fact_sales
    WHERE order_status = 'Delivered'
    $dateCondition
    GROUP BY DATE_FORMAT(order_date, '%Y-%m')
    ORDER BY sales_month ASC
";

$monthlyResult = $dwConn->query($monthlySql);

if (!$monthlyResult) {
    die('Monthly sales query failed: ' . $dwConn->error);
}

$monthlyRows = $monthlyResult->fetch_all(MYSQLI_ASSOC);

$monthlyLabels = [];
$monthlySales = [];

foreach ($monthlyRows as $row) {
    $monthlyLabels[] = date(
        'M Y',
        strtotime($row['sales_month'] . '-01')
    );

    $monthlySales[] = (float) $row['monthly_sales'];
}

/* Top 10 popular cakes */
$topCakesSql = "
    SELECT
        cake_name,
        SUM(quantity) AS total_quantity,
        SUM(subtotal) AS total_sales
    FROM fact_sales
    WHERE order_status = 'Delivered'
    $dateCondition
    GROUP BY cake_name
    ORDER BY total_quantity DESC
    LIMIT 10
";

$topCakesResult = $dwConn->query($topCakesSql);

if (!$topCakesResult) {
    die('Popular cakes query failed: ' . $dwConn->error);
}

$topCakesRows = $topCakesResult->fetch_all(MYSQLI_ASSOC);

$cakeLabels = [];
$cakeQuantities = [];

foreach ($topCakesRows as $row) {
    $cakeLabels[] = $row['cake_name'];
    $cakeQuantities[] = (int) $row['total_quantity'];
}

/* Order status distribution */
$statusSql = "
    SELECT
        order_status,
        COUNT(DISTINCT order_id) AS total_orders
    FROM fact_sales
    WHERE 1 = 1
    $dateCondition
    GROUP BY order_status
    ORDER BY total_orders DESC
";

$statusResult = $dwConn->query($statusSql);

if (!$statusResult) {
    die('Order status query failed: ' . $dwConn->error);
}

$statusRows = $statusResult->fetch_all(MYSQLI_ASSOC);

$statusLabels = [];
$statusCounts = [];

foreach ($statusRows as $row) {
    $statusLabels[] = $row['order_status'];
    $statusCounts[] = (int) $row['total_orders'];
}

/* Figure 4.4: Sales by Product Category */
$categorySql = "
    SELECT
        COALESCE(
            NULLIF(TRIM(p.category), ''),
            'Uncategorized'
        ) AS category_name,
        SUM(fs.subtotal) AS category_sales
    FROM fact_sales fs
    LEFT JOIN mlsd_sweets.products p
        ON p.id = fs.product_id
    WHERE fs.order_status = 'Delivered'
    $dateCondition
    GROUP BY
        COALESCE(
            NULLIF(TRIM(p.category), ''),
            'Uncategorized'
        )
    ORDER BY category_sales DESC
";

$categoryResult = $dwConn->query($categorySql);

if (!$categoryResult) {
    die('Category sales query failed: ' . $dwConn->error);
}

$categoryRows = $categoryResult->fetch_all(MYSQLI_ASSOC);

$categoryLabels = [];
$categorySales = [];

foreach ($categoryRows as $row) {
    $categoryLabels[] = $row['category_name'];
    $categorySales[] = (float) $row['category_sales'];
}

/* Figure 4.7: Payment Method Distribution
   Counts all submitted payment records, regardless of verification status. */
$paymentMethodSql = "
    SELECT
        payment_method,
        COUNT(*) AS total_payments
    FROM mlsd_sweets.order_payments
    WHERE 1 = 1
    $paymentDateCondition
    GROUP BY payment_method
    ORDER BY total_payments DESC
";

$paymentMethodResult = $dwConn->query($paymentMethodSql);

if (!$paymentMethodResult) {
    die('Payment method query failed: ' . $dwConn->error);
}

$paymentMethodRows = $paymentMethodResult->fetch_all(MYSQLI_ASSOC);

$paymentMethodLabels = [];
$paymentMethodCounts = [];

foreach ($paymentMethodRows as $row) {
    $paymentMethodLabels[] = $row['payment_method'];
    $paymentMethodCounts[] = (int) $row['total_payments'];
}

/* Payment verification status */
$paymentStatusSql = "
    SELECT
        payment_status,
        COUNT(*) AS total_payments
    FROM mlsd_sweets.order_payments
    WHERE 1 = 1
    $paymentDateCondition
    GROUP BY payment_status
    ORDER BY total_payments DESC
";

$paymentStatusResult = $dwConn->query($paymentStatusSql);

if (!$paymentStatusResult) {
    die('Payment status query failed: ' . $dwConn->error);
}

$paymentStatusRows = $paymentStatusResult->fetch_all(MYSQLI_ASSOC);

$paymentStatusLabels = [];
$paymentStatusCounts = [];

foreach ($paymentStatusRows as $row) {
    $paymentStatusLabels[] = $row['payment_status'];
    $paymentStatusCounts[] = (int) $row['total_payments'];
}

/* JSON chart data */
$jsonFlags = JSON_HEX_TAG
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
    | JSON_HEX_AMP;

$monthlyLabelsJson = json_encode($monthlyLabels, $jsonFlags);
$monthlySalesJson = json_encode($monthlySales, $jsonFlags);

$cakeLabelsJson = json_encode($cakeLabels, $jsonFlags);
$cakeQuantitiesJson = json_encode($cakeQuantities, $jsonFlags);

$statusLabelsJson = json_encode($statusLabels, $jsonFlags);
$statusCountsJson = json_encode($statusCounts, $jsonFlags);

$categoryLabelsJson = json_encode($categoryLabels, $jsonFlags);
$categorySalesJson = json_encode($categorySales, $jsonFlags);

$paymentMethodLabelsJson = json_encode($paymentMethodLabels, $jsonFlags);
$paymentMethodCountsJson = json_encode($paymentMethodCounts, $jsonFlags);

$paymentStatusLabelsJson = json_encode($paymentStatusLabels, $jsonFlags);
$paymentStatusCountsJson = json_encode($paymentStatusCounts, $jsonFlags);

function reportEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Data Warehouse Reports | MLSD Sweets and Delights</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            background: #fff8fa;
            color: #4a2634;
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #b83267;
        }

        .navbar-brand,
        .navbar .nav-link {
            color: #fff !important;
        }

        .page-header {
            background: linear-gradient(135deg, #ffe0eb, #fff4f7);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 24px;
        }

        .section-card,
        .summary-card {
            background: #fff;
            border: 1px solid #f2d8e2;
            border-radius: 14px;
            padding: 20px;
            height: 100%;
            box-shadow: 0 3px 12px rgba(74, 38, 52, 0.05);
        }

        .summary-label {
            color: #795466;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .summary-value {
            color: #b83267;
            font-size: 27px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .chart-container {
            position: relative;
            height: 320px;
            width: 100%;
        }

        .chart-empty {
            min-height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #795466;
            background: #fff8fa;
            border-radius: 10px;
            padding: 20px;
        }

        .btn-pink {
            background: #b83267;
            border-color: #b83267;
            color: #fff;
        }

        .btn-pink:hover {
            background: #94244f;
            border-color: #94244f;
            color: #fff;
        }

        .table thead th {
            background: #ffe0eb;
            color: #4a2634;
            white-space: nowrap;
        }

        .filter-card {
            background: #fff;
            border: 1px solid #f2d8e2;
            border-radius: 14px;
            padding: 18px;
        }

        footer {
            color: #795466;
            text-align: center;
            padding: 25px 10px;
        }

        @media print {
            .navbar,
            .filter-card,
            .no-print,
            footer {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .section-card,
            .summary-card {
                box-shadow: none;
                break-inside: avoid;
            }

            .chart-container {
                height: 260px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            MLSD Sweets and Delights
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNavbar"
            aria-controls="mainNavbar"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">Dashboard</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link active" href="reports.php">Reports</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4">

    <div class="page-header">
        <h2 class="fw-bold mb-2">Data Warehouse Reports</h2>
        <p class="mb-0">
            View sales performance, popular cakes, order statuses,
            category sales, and payment statistics.
        </p>
    </div>

    <!-- Date Filters -->
    <section class="filter-card mb-4 no-print">
        <form method="GET" action="reports.php">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="start_date" class="form-label fw-semibold">
                        Start Date
                    </label>
                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        class="form-control"
                        value="<?= reportEscape($startDate) ?>"
                    >
                </div>

                <div class="col-md-4">
                    <label for="end_date" class="form-label fw-semibold">
                        End Date
                    </label>
                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        class="form-control"
                        value="<?= reportEscape($endDate) ?>"
                    >
                </div>

                <div class="col-md-4 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-pink">
                        Apply Filter
                    </button>

                    <a href="reports.php" class="btn btn-outline-secondary">
                        Reset
                    </a>

                    <button
                        type="button"
                        class="btn btn-outline-dark"
                        onclick="window.print()"
                    >
                        Print / Save as PDF
                    </button>
                </div>
            </div>

            <div class="small text-muted mt-2">
                Leave the dates blank to show all available records.
                Sales charts use order dates; payment charts use submission dates.
            </div>
        </form>
    </section>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="summary-card">
                <div class="summary-label">Total Delivered Sales</div>
                <div class="summary-value">
                    ₱<?= number_format((float) $summary['total_sales'], 2) ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="summary-card">
                <div class="summary-label">Delivered Orders</div>
                <div class="summary-value">
                    <?= number_format((int) $summary['delivered_orders']) ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="summary-card">
                <div class="summary-label">All Orders</div>
                <div class="summary-value">
                    <?= number_format((int) $summary['all_orders']) ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="summary-card">
                <div class="summary-label">Cakes Sold</div>
                <div class="summary-value">
                    <?= number_format((int) $summary['cakes_sold']) ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Charts -->
    <div class="row g-4 mb-4">

        <div class="col-12">
            <section class="section-card">
                <h5 class="fw-bold">Monthly Sales</h5>
                <p class="text-muted small">
                    Delivered sales grouped by month.
                </p>

                <?php if (count($monthlyRows) === 0): ?>
                    <div class="chart-empty">
                        No delivered sales records are available for this period.
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="monthlySalesChart"></canvas>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-12 col-lg-6">
            <section class="section-card">
                <h5 class="fw-bold">Top 10 Popular Cakes</h5>
                <p class="text-muted small">
                    Cakes ranked according to quantity sold.
                </p>

                <?php if (count($topCakesRows) === 0): ?>
                    <div class="chart-empty">No popular-cake data found.</div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="topCakesChart"></canvas>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-12 col-lg-6">
            <section class="section-card">
                <h5 class="fw-bold">Order Status Distribution</h5>
                <p class="text-muted small">
                    Number of orders grouped by status.
                </p>

                <?php if (count($statusRows) === 0): ?>
                    <div class="chart-empty">No order-status data found.</div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="orderStatusChart"></canvas>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- Figure 4.4 -->
        <div class="col-12">
            <section class="section-card">
                <h5 class="fw-bold">
                   Sales by Product Category
                </h5>

                <p class="text-muted small">
                    Actual delivered sales across cake categories.
                </p>

                <?php if (count($categoryRows) === 0): ?>
                    <div class="chart-empty">
                        No delivered category sales were found.
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="categorySalesChart"></canvas>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- Figure 4.7 -->
        <div class="col-12 col-lg-6">
            <section class="section-card">
                <h5 class="fw-bold">
                  Payment Method Distribution
                </h5>

                <p class="text-muted small">
                    All submitted payments, including those awaiting verification.
                </p>

                <?php if (count($paymentMethodRows) === 0): ?>
                    <div class="chart-empty">
                        No submitted payment records were found.
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="paymentMethodChart"></canvas>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- Payment verification -->
        <div class="col-12 col-lg-6">
            <section class="section-card">
                <h5 class="fw-bold">Payment Verification Status</h5>

                <p class="text-muted small">
                    Submitted payments grouped by their recorded verification status.
                </p>

                <?php if (count($paymentStatusRows) === 0): ?>
                    <div class="chart-empty">
                        No payment verification records were found.
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="paymentStatusChart"></canvas>
                    </div>
                <?php endif; ?>
            </section>
        </div>

    </div>

    <!-- Category Sales Details -->
    <section class="section-card mb-4">
        <h5 class="fw-bold">Sales by Product Category Details</h5>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mt-3">
                <thead>
                    <tr>
                        <th>Product Category</th>
                        <th class="text-end">Delivered Sales</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($categoryRows) === 0): ?>
                        <tr>
                            <td colspan="2" class="text-center text-muted">
                                No category sales records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categoryRows as $row): ?>
                            <tr>
                                <td><?= reportEscape($row['category_name']) ?></td>
                                <td class="text-end">
                                    ₱<?= number_format((float) $row['category_sales'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Popular Cakes Details -->
    <section class="section-card mb-4">
        <h5 class="fw-bold">Popular Cakes Details</h5>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mt-3">
                <thead>
                    <tr>
                        <th>Cake Name</th>
                        <th class="text-end">Quantity Sold</th>
                        <th class="text-end">Delivered Sales</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($topCakesRows) === 0): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">
                                No cake sales records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topCakesRows as $row): ?>
                            <tr>
                                <td><?= reportEscape($row['cake_name']) ?></td>
                                <td class="text-end">
                                    <?= number_format((int) $row['total_quantity']) ?>
                                </td>
                                <td class="text-end">
                                    ₱<?= number_format((float) $row['total_sales'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Payment Method Details -->
    <section class="section-card mb-4">
        <h5 class="fw-bold">Payment Method Details</h5>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mt-3">
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th class="text-end">Submitted Payments</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($paymentMethodRows) === 0): ?>
                        <tr>
                            <td colspan="2" class="text-center text-muted">
                                No payment records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paymentMethodRows as $row): ?>
                            <tr>
                                <td><?= reportEscape($row['payment_method']) ?></td>
                                <td class="text-end">
                                    <?= number_format((int) $row['total_payments']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Payment Verification Details -->
    <section class="section-card mb-4">
        <h5 class="fw-bold">Payment Verification Details</h5>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mt-3">
                <thead>
                    <tr>
                        <th>Verification Status</th>
                        <th class="text-end">Number of Payments</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($paymentStatusRows) === 0): ?>
                        <tr>
                            <td colspan="2" class="text-center text-muted">
                                No verification records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paymentStatusRows as $row): ?>
                            <tr>
                                <td><?= reportEscape($row['payment_status']) ?></td>
                                <td class="text-end">
                                    <?= number_format((int) $row['total_payments']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<footer>
    MLSD Sweets and Delights &copy; <?= date('Y') ?>
    — Baked with Love, Made for Your Moments.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const monthlyLabels = <?= $monthlyLabelsJson ?: '[]' ?>;
const monthlySales = <?= $monthlySalesJson ?: '[]' ?>;

const cakeLabels = <?= $cakeLabelsJson ?: '[]' ?>;
const cakeQuantities = <?= $cakeQuantitiesJson ?: '[]' ?>;

const statusLabels = <?= $statusLabelsJson ?: '[]' ?>;
const statusCounts = <?= $statusCountsJson ?: '[]' ?>;

const categoryLabels = <?= $categoryLabelsJson ?: '[]' ?>;
const categorySales = <?= $categorySalesJson ?: '[]' ?>;

const paymentMethodLabels = <?= $paymentMethodLabelsJson ?: '[]' ?>;
const paymentMethodCounts = <?= $paymentMethodCountsJson ?: '[]' ?>;

const paymentStatusLabels = <?= $paymentStatusLabelsJson ?: '[]' ?>;
const paymentStatusCounts = <?= $paymentStatusCountsJson ?: '[]' ?>;

function formatPeso(value) {
    return '₱' + Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/* Monthly Sales */
const monthlyCanvas = document.getElementById('monthlySalesChart');

if (monthlyCanvas) {
    new Chart(monthlyCanvas, {
        type: 'bar',
        data: {
            labels: monthlyLabels,
            datasets: [{
                label: 'Delivered Sales (₱)',
                data: monthlySales,
                backgroundColor: '#e9a3bd',
                borderColor: '#b83267',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => formatPeso(value)
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: context => formatPeso(context.raw)
                    }
                }
            }
        }
    });
}

/* Top 10 Popular Cakes */
const cakesCanvas = document.getElementById('topCakesChart');

if (cakesCanvas) {
    new Chart(cakesCanvas, {
        type: 'bar',
        data: {
            labels: cakeLabels,
            datasets: [{
                label: 'Quantity Sold',
                data: cakeQuantities,
                backgroundColor: '#e9a3bd',
                borderColor: '#b83267',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

/* Order Status */
const statusCanvas = document.getElementById('orderStatusChart');

if (statusCanvas) {
    new Chart(statusCanvas, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                label: 'Orders',
                data: statusCounts,
                backgroundColor: [
                    '#b83267',
                    '#e9a3bd',
                    '#f4c6d7',
                    '#8e6b7b',
                    '#d9a441',
                    '#78a890',
                    '#8bb8d8'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

/* Figure 4.4: Sales by Product Category */
const categoryCanvas = document.getElementById('categorySalesChart');

if (categoryCanvas) {
    new Chart(categoryCanvas, {
        type: 'bar',
        data: {
            labels: categoryLabels,
            datasets: [{
                label: 'Delivered Sales (₱)',
                data: categorySales,
                backgroundColor: '#e9a3bd',
                borderColor: '#b83267',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => formatPeso(value)
                    },
                    title: {
                        display: true,
                        text: 'Sales Amount (₱)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Cake Category'
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: context => 'Sales: ' + formatPeso(context.raw)
                    }
                }
            }
        }
    });
}

/* Figure 4.7: Payment Method Distribution */
const paymentMethodCanvas = document.getElementById('paymentMethodChart');

if (paymentMethodCanvas) {
    new Chart(paymentMethodCanvas, {
        type: 'doughnut',
        data: {
            labels: paymentMethodLabels,
            datasets: [{
                label: 'Submitted Payments',
                data: paymentMethodCounts,
                backgroundColor: ['#b83267', '#e9a3bd'],
                borderColor: '#ffffff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: context =>
                            context.label + ': ' + context.raw + ' payments'
                    }
                }
            }
        }
    });
}

/* Payment Verification Status */
const paymentStatusCanvas = document.getElementById('paymentStatusChart');

if (paymentStatusCanvas) {
    new Chart(paymentStatusCanvas, {
        type: 'bar',
        data: {
            labels: paymentStatusLabels,
            datasets: [{
                label: 'Number of Payments',
                data: paymentStatusCounts,
                backgroundColor: '#e9a3bd',
                borderColor: '#b83267',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    title: {
                        display: true,
                        text: 'Number of Payments'
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}
</script>

</body>
</html>
