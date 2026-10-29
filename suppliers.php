
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['supplier_csrf_token'])) {
    $_SESSION['supplier_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['supplier_csrf_token'];
$message = '';
$messageType = 'success';
$editSupplier = null;

function supplierEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function supplierRedirect(string $message, string $type = 'success'): void
{
    header(
        'Location: suppliers.php?message=' . urlencode($message)
        . '&type=' . urlencode($type)
    );
    exit;
}

/* PROCESS ADD, EDIT, ACTIVATE, AND DEACTIVATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        supplierRedirect('Invalid form token. Please refresh and try again.', 'danger');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

        $supplierName = trim($_POST['supplier_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($action === 'update' && (!$id || $id < 1)) {
            supplierRedirect('Invalid supplier ID.', 'danger');
        }

        if ($supplierName === '') {
            supplierRedirect('Supplier name is required.', 'danger');
        }

        if (
            strlen($supplierName) > 150 ||
            strlen($contactPerson) > 150 ||
            strlen($phone) > 30 ||
            strlen($email) > 150
        ) {
            supplierRedirect('One or more fields exceed the allowed length.', 'danger');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            supplierRedirect('Please enter a valid email address.', 'danger');
        }

        $contactPerson = $contactPerson === '' ? null : $contactPerson;
        $phone = $phone === '' ? null : $phone;
        $email = $email === '' ? null : $email;
        $address = $address === '' ? null : $address;
        $notes = $notes === '' ? null : $notes;

        if ($action === 'add') {
            $stmt = $conn->prepare("
                INSERT INTO suppliers
                    (supplier_name, contact_person, phone, email, address, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                'ssssss',
                $supplierName,
                $contactPerson,
                $phone,
                $email,
                $address,
                $notes
            );

            $stmt->execute();
            $stmt->close();

            supplierRedirect('Supplier added successfully.');
        }

        $stmt = $conn->prepare("
            UPDATE suppliers
            SET supplier_name = ?,
                contact_person = ?,
                phone = ?,
                email = ?,
                address = ?,
                notes = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'ssssssi',
            $supplierName,
            $contactPerson,
            $phone,
            $email,
            $address,
            $notes,
            $id
        );

        $stmt->execute();
        $stmt->close();

        supplierRedirect('Supplier details updated successfully.');
    }

    if ($action === 'toggle') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            supplierRedirect('Invalid supplier ID.', 'danger');
        }

        $stmt = $conn->prepare("
            UPDATE suppliers
            SET is_active = IF(is_active = 1, 0, 1)
            WHERE id = ?
        ");

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed > 0) {
            supplierRedirect('Supplier status updated successfully.');
        }

        supplierRedirect('Supplier not found or status was not changed.', 'warning');
    }

    supplierRedirect('Invalid action.', 'danger');
}

/* DISPLAY FEEDBACK */
if (isset($_GET['message'])) {
    $message = (string)$_GET['message'];

    $allowedTypes = ['success', 'danger', 'warning', 'info'];
    $requestedType = (string)($_GET['type'] ?? 'success');

    $messageType = in_array($requestedType, $allowedTypes, true)
        ? $requestedType
        : 'success';
}

/* LOAD SUPPLIER FOR EDITING */
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);

if ($editId && $editId > 0) {
    $stmt = $conn->prepare("
        SELECT id, supplier_name, contact_person, phone, email,
               address, notes, is_active
        FROM suppliers
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $editId);
    $stmt->execute();

    $editSupplier = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$editSupplier) {
        $message = 'Supplier not found.';
        $messageType = 'warning';
    }
}

/* SEARCH AND STATUS FILTER */
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

if (!in_array($statusFilter, ['', 'active', 'inactive'], true)) {
    $statusFilter = '';
}

$sql = "
    SELECT id, supplier_name, contact_person, phone, email,
           address, notes, is_active, created_at
    FROM suppliers
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= "
        AND (
            supplier_name LIKE ?
            OR contact_person LIKE ?
            OR phone LIKE ?
            OR email LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    $types = 'ssss';
}

if ($statusFilter === 'active') {
    $sql .= " AND is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND is_active = 0";
}

$sql .= " ORDER BY is_active DESC, supplier_name ASC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$suppliers = [];

while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

$stmt->close();

/* SUMMARY COUNTS: ALWAYS BASED ON ALL SUPPLIERS */
$summaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count,
        COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) AS inactive_count
    FROM suppliers
");

$summary = $summaryResult->fetch_assoc();

$totalCount = (int)$summary['total_count'];
$activeCount = (int)$summary['active_count'];
$inactiveCount = (int)$summary['inactive_count'];

$formAction = $editSupplier ? 'update' : 'add';
$formTitle = $editSupplier ? 'Edit Supplier' : 'Add New Supplier';
$submitLabel = $editSupplier ? 'Save Changes' : 'Add Supplier';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Supplier Management | MLSD</title>

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

        .page-card {
            background: #fff;
            border: 0;
            border-radius: 15px;
            padding: 22px;
            box-shadow: 0 4px 18px #0000000c;
            margin-bottom: 24px;
        }

        .stat-number {
            color: #b83267;
            font-size: 1.7rem;
            font-weight: 700;
        }

        .btn-pink {
            color: #fff;
            background: #b83267;
            border-color: #b83267;
        }

        .btn-pink:hover {
            color: #fff;
            background: #962651;
            border-color: #962651;
        }

        .table th {
            white-space: nowrap;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark px-3 py-2">
    <a class="navbar-brand fw-bold" href="dashboard.php">
        MLSD Sweets and Delights
    </a>

    <div class="d-flex flex-wrap gap-2">
        <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
        <a href="orders.php" class="btn btn-outline-light btn-sm">Orders</a>
        <a href="products.php" class="btn btn-outline-light btn-sm">Cakes</a>
        <a href="inventory.php" class="btn btn-outline-light btn-sm">Inventory</a>
        <a href="reports.php" class="btn btn-outline-light btn-sm">Reports</a>

        <?php if (isAdmin()): ?>
            <a href="users.php" class="btn btn-outline-light btn-sm">
                User Management
            </a>
            <a href="etl.php" class="btn btn-outline-light btn-sm">
                Run ETL
            </a>
        <?php endif; ?>

        <a href="logout.php" class="btn btn-outline-light btn-sm">Log out</a>
    </div>
</nav>

<main class="container py-4">

    <h2 class="fw-bold">Supplier Management</h2>

    <p class="text-muted">
        Manage the suppliers who provide ingredients and materials
        for MLSD Sweets and Delights.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= supplierEscape($messageType) ?> alert-dismissible fade show">
            <?= supplierEscape($message) ?>
            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>
        </div>
    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="page-card mb-0">
                <div class="text-muted">Total Suppliers</div>
                <div class="stat-number"><?= number_format($totalCount) ?></div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="page-card mb-0">
                <div class="text-muted">Active Suppliers</div>
                <div class="stat-number"><?= number_format($activeCount) ?></div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="page-card mb-0">
                <div class="text-muted">Inactive Suppliers</div>
                <div class="stat-number"><?= number_format($inactiveCount) ?></div>
            </div>
        </div>
    </div>

    <!-- ADD OR EDIT SUPPLIER -->
    <section class="page-card">
        <h4 class="fw-bold mb-3"><?= supplierEscape($formTitle) ?></h4>

        <form method="POST" action="suppliers.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= supplierEscape($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="<?= supplierEscape($formAction) ?>"
            >

            <?php if ($editSupplier): ?>
                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$editSupplier['id'] ?>"
                >
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="supplier_name" class="form-label">
                        Supplier Name *
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        id="supplier_name"
                        name="supplier_name"
                        maxlength="150"
                        required
                        value="<?= supplierEscape($editSupplier['supplier_name'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label for="contact_person" class="form-label">
                        Contact Person
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        id="contact_person"
                        name="contact_person"
                        maxlength="150"
                        value="<?= supplierEscape($editSupplier['contact_person'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label for="phone" class="form-label">Phone Number</label>

                    <input
                        type="tel"
                        class="form-control"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        value="<?= supplierEscape($editSupplier['phone'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label for="email" class="form-label">Email Address</label>

                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        maxlength="150"
                        value="<?= supplierEscape($editSupplier['email'] ?? '') ?>"
                    >
                </div>

                <div class="col-12">
                    <label for="address" class="form-label">Address</label>

                    <textarea
                        class="form-control"
                        id="address"
                        name="address"
                        rows="2"
                    ><?= supplierEscape($editSupplier['address'] ?? '') ?></textarea>
                </div>

                <div class="col-12">
                    <label for="notes" class="form-label">Notes</label>

                    <textarea
                        class="form-control"
                        id="notes"
                        name="notes"
                        rows="2"
                        placeholder="Example: Supplies flour, sugar, and baking ingredients"
                    ><?= supplierEscape($editSupplier['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-pink">
                    <?= supplierEscape($submitLabel) ?>
                </button>

                <?php if ($editSupplier): ?>
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- SEARCH AND FILTER -->
    <section class="page-card">
        <h4 class="fw-bold mb-3">Search and Filter Suppliers</h4>

        <form method="GET" action="suppliers.php">
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label for="search" class="form-label">
                        Search Supplier
                    </label>

                    <input
                        type="search"
                        class="form-control"
                        id="search"
                        name="search"
                        placeholder="Name, contact person, phone, or email"
                        value="<?= supplierEscape($search) ?>"
                    >
                </div>

                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>

                    <select class="form-select" id="status" name="status">
                        <option value="">All suppliers</option>
                        <option
                            value="active"
                            <?= $statusFilter === 'active' ? 'selected' : '' ?>
                        >Active</option>
                        <option
                            value="inactive"
                            <?= $statusFilter === 'inactive' ? 'selected' : '' ?>
                        >Inactive</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-pink">Apply</button>
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </section>

    <!-- SUPPLIER LIST -->
    <section class="page-card">
        <h4 class="fw-bold mb-3">Supplier List</h4>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Supplier</th>
                        <th>Contact Person</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($suppliers) === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No matching suppliers found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $supplier): ?>
                        <tr>
                            <td><?= (int)$supplier['id'] ?></td>

                            <td>
                                <strong>
                                    <?= supplierEscape($supplier['supplier_name']) ?>
                                </strong>

                                <?php if (!empty($supplier['address'])): ?>
                                    <div class="text-muted small">
                                        <?= nl2br(supplierEscape($supplier['address'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($supplier['notes'])): ?>
                                    <div class="text-muted small">
                                        Notes: <?= supplierEscape($supplier['notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= supplierEscape($supplier['contact_person'] ?? '—') ?>
                            </td>

                            <td>
                                <?= supplierEscape($supplier['phone'] ?? '—') ?>
                            </td>

                            <td>
                                <?php if (!empty($supplier['email'])): ?>
                                    <a href="mailto:<?= supplierEscape($supplier['email']) ?>">
                                        <?= supplierEscape($supplier['email']) ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ((int)$supplier['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <a
                                        href="suppliers.php?edit=<?= (int)$supplier['id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >Edit</a>

                                    <form
                                        method="POST"
                                        action="suppliers.php"
                                        onsubmit="return confirm('Change this supplier\\'s active status?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= supplierEscape($csrfToken) ?>"
                                        >

                                        <input type="hidden" name="action" value="toggle">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$supplier['id'] ?>"
                                        >

                                        <?php if ((int)$supplier['is_active'] === 1): ?>
                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                            >Deactivate</button>
                                        <?php else: ?>
                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-success"
                                            >Activate</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="text-muted small mb-0">
            Showing <?= count($suppliers) ?> supplier(s) matching your filters.
            Deactivated suppliers remain in the database.
        </p>
    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
