
<?php
require_once __DIR__ . '/auth.php';

if (!isAdmin()) {
    http_response_code(403);
    exit('Access denied. Only Admin accounts can export reports.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $dwConn = new mysqli('localhost', 'root', '', 'mlsd_dw');
    $dwConn->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to connect to the Data Warehouse.');
}

function validExportDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parts = explode('-', $date);

    return checkdate(
        (int)$parts[1],
        (int)$parts[2],
        (int)$parts[0]
    );
}

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

if ($startDate !== '' && !validExportDate($startDate)) {
    http_response_code(400);
    exit('Invalid start date.');
}

if ($endDate !== '' && !validExportDate($endDate)) {
    http_response_code(400);
    exit('Invalid end date.');
}

if (
    $startDate !== '' &&
    $endDate !== '' &&
    $startDate > $endDate
) {
    http_response_code(400);
    exit('Start date must not be later than end date.');
}

$conditions = ["order_status = 'Delivered'"];

if ($startDate !== '') {
    $safeStart = $dwConn->real_escape_string($startDate);
    $conditions[] = "order_date >= '$safeStart'";
}

if ($endDate !== '') {
    $nextDay = date('Y-m-d', strtotime($endDate . ' +1 day'));
    $safeNextDay = $dwConn->real_escape_string($nextDay);
    $conditions[] = "order_date < '$safeNextDay'";
}

$where = implode(' AND ', $conditions);

$sql = "
    SELECT
        order_id,
        order_date,
        cake_name,
        quantity,
        subtotal,
        order_status
    FROM fact_sales
    WHERE $where
    ORDER BY order_date DESC, order_id DESC
";

$result = $dwConn->query($sql);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="mlsd_delivered_sales.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$output = fopen('php://output', 'w');

/* Helps Excel recognize UTF-8 text */
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Order ID',
    'Order Date',
    'Cake Name',
    'Quantity',
    'Subtotal',
    'Order Status'
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['order_id'],
        $row['order_date'],
        $row['cake_name'],
        $row['quantity'],
        $row['subtotal'],
        $row['order_status']
    ]);
}

fclose($output);
$dwConn->close();
exit;
