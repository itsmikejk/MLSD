
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['inventory_csrf'])) {
    $_SESSION['inventory_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$message = '';

function inventoryHtml($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function inventoryPeso($value): string
{
    return '₱' . number_format((float)$value, 2);
}

/* ADD INGREDIENT OR RESTOCK */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['inventory_csrf'], $token)
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $name = trim($_POST['ingredient_name'] ?? '');
            $unit = trim($_POST['unit'] ?? '');
            $quantityInput = $_POST['quantity_in_stock'] ?? '';
            $reorderInput = $_POST['reorder_level'] ?? '';
            $costInput = $_POST['unit_cost'] ?? '';

            $allowedUnits = ['kg', 'g', 'L', 'ml', 'pcs', 'pack'];

            if (
                $name === '' ||
                strlen($name) > 120 ||
                !in_array($unit, $allowedUnits, true) ||
                !is_numeric($quantityInput) ||
                !is_numeric($reorderInput) ||
                !is_numeric($costInput) ||
                (float)$quantityInput < 0 ||
                (float)$reorderInput < 0 ||
                (float)$costInput < 0
            ) {
                $error = 'Enter valid ingredient details and non-negative amounts.';
            } else {
                $quantity = (float)$quantityInput;
                $reorder = (float)$reorderInput;
                $cost = (float)$costInput;

                $stmt = $conn->prepare(
                    "INSERT INTO ingredients
                        (ingredient_name, unit, quantity_in_stock,
                         reorder_level, unit_cost)
                     VALUES (?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "ssddd",
                    $name,
                    $unit,
                    $quantity,
                    $reorder,
                    $cost
                );

                $stmt->execute();
                $stmt->close();

                $message = 'Ingredient added successfully.';
            }
        } elseif ($action === 'restock') {
            $ingredientId = filter_input(
                INPUT_POST,
                'ingredient_id',
                FILTER_VALIDATE_INT
            );

            $quantityInput = $_POST['restock_quantity'] ?? '';

            if (
                !$ingredientId ||
                $ingredientId < 1 ||
                !is_numeric($quantityInput) ||
                (float)$quantityInput <= 0
            ) {
                $error = 'Enter a valid ingredient and a restock quantity greater than zero.';
            } else {
                $quantity = (float)$quantityInput;
                $transactionStarted = false;

                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;

                    $stmt = $conn->prepare(
                        "SELECT id
                         FROM ingredients
                         WHERE id = ?
                         FOR UPDATE"
                    );

                    $stmt->bind_param("i", $ingredientId);
                    $stmt->execute();

                    $ingredient = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$ingredient) {
                        throw new RuntimeException('Ingredient not found.');
                    }

                    $stmt = $conn->prepare(
                        "UPDATE ingredients
                         SET quantity_in_stock = quantity_in_stock + ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param("di", $quantity, $ingredientId);
                    $stmt->execute();
                    $stmt->close();

                    $remarks = 'Manual restock';
                    $type = 'IN';

                    $stmt = $conn->prepare(
                        "INSERT INTO inventory_transactions
                            (ingredient_id, transaction_type, quantity, remarks)
                         VALUES (?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        "isds",
                        $ingredientId,
                        $type,
                        $quantity,
                        $remarks
                    );

                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $transactionStarted = false;

                    $message = 'Stock updated and restock transaction recorded.';
                } catch (Throwable $e) {
                    if ($transactionStarted) {
                        try {
                            $conn->rollback();
                        } catch (Throwable $rollbackError) {
                            error_log($rollbackError->getMessage());
                        }
                    }

                    error_log($e->getMessage());

                    $error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'Unable to update stock. Please try again.';
                }
            }
        } else {
            $error = 'Invalid action.';
        }
    }
}

/* SEARCH AND STOCK FILTERS */
$search = trim($_GET['search'] ?? '');
$stockFilter = $_GET['stock_filter'] ?? '';

if (!in_array($stockFilter, ['', 'low', 'normal'], true)) {
    $stockFilter = '';
}

$sql = "
    SELECT
        id,
        ingredient_name,
        unit,
        quantity_in_stock,
        reorder_level,
        unit_cost
    FROM ingredients
";

$conditions = [];
$params = [];
$types = '';

if ($search !== '') {
    $conditions[] = "ingredient_name LIKE ?";
    $params[] = '%' . $search . '%';
    $types .= 's';
}

if ($stockFilter === 'low') {
    $conditions[] = "quantity_in_stock <= reorder_level";
} elseif ($stockFilter === 'normal') {
    $conditions[] = "quantity_in_stock > reorder_level";
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY ingredient_name ASC';

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$ingredients = $stmt->get_result();

/* INVENTORY SUMMARY */
$summaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total_ingredients,
        COALESCE(SUM(
            CASE WHEN quantity_in_stock <= reorder_level THEN 1 ELSE 0 END
        ), 0) AS low_stock_count,
        COALESCE(SUM(quantity_in_stock * unit_cost), 0) AS stock_value
     FROM ingredients"
);

$inventorySummary = $summaryResult->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Inventory Management | MLSD Sweets and Delights</title>

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

        .panel,
        .summary-card,
        .filter-panel {
            background: white;
            border: 0;
            border-radius: 15px;
            box-shadow: 0 4px 18px #0000000c;
        }

        .summary-card {
            padding: 20px;
            height: 100%;
        }

        .summary-number {
            color: #b83267;
            font-size: 1.6rem;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .page-title {
            color: #b83267;
        }

        .filter-panel {
            padding: 20px;
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

    <div class="d-flex flex-wrap gap-2">
        <a href="dashboard.php" class="btn btn-light btn-sm">
            Dashboard
        </a>

        <a href="orders.php" class="btn btn-outline-light btn-sm">
            Orders
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

<main class="container py-4">

    <h2 class="fw-bold page-title">Inventory Management</h2>

    <p class="text-muted">
        Manage ingredients, monitor stock levels, and record restocking transactions.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success" role="alert">
            <?= inventoryHtml($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= inventoryHtml($error) ?>
        </div>
    <?php endif; ?>

    <!-- INVENTORY SUMMARY -->
    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="summary-card">
                <div class="text-muted">Total Ingredients</div>
                <div class="summary-number">
                    <?= number_format((int)$inventorySummary['total_ingredients']) ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card">
                <div class="text-muted">Low-Stock Ingredients</div>
                <div class="summary-number">
                    <?= number_format((int)$inventorySummary['low_stock_count']) ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card">
                <div class="text-muted">Estimated Stock Value</div>
                <div class="summary-number">
                    <?= inventoryPeso($inventorySummary['stock_value']) ?>
                </div>
                <small class="text-muted">
                    Based on recorded quantities and unit costs
                </small>
            </div>
        </div>

    </div>

    <!-- ADD INGREDIENT -->
    <section class="panel p-4 mb-4">
        <h4 class="mb-3">Add Ingredient</h4>

        <form method="POST" action="inventory.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= inventoryHtml($_SESSION['inventory_csrf']) ?>"
            >

            <input type="hidden" name="action" value="add">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label" for="ingredient_name">
                        Ingredient Name *
                    </label>

                    <input
                        class="form-control"
                        id="ingredient_name"
                        name="ingredient_name"
                        maxlength="120"
                        required
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="unit">Unit *</label>

                    <select class="form-select" id="unit" name="unit" required>
                        <option value="kg">kg</option>
                        <option value="g">g</option>
                        <option value="L">L</option>
                        <option value="ml">ml</option>
                        <option value="pcs">pcs</option>
                        <option value="pack">pack</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="quantity_in_stock">
                        Current Stock *
                    </label>

                    <input
                        class="form-control"
                        type="number"
                        step="0.01"
                        min="0"
                        id="quantity_in_stock"
                        name="quantity_in_stock"
                        required
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="reorder_level">
                        Low Stock At *
                    </label>

                    <input
                        class="form-control"
                        type="number"
                        step="0.01"
                        min="0"
                        id="reorder_level"
                        name="reorder_level"
                        required
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="unit_cost">
                        Unit Cost (₱) *
                    </label>

                    <input
                        class="form-control"
                        type="number"
                        step="0.01"
                        min="0"
                        id="unit_cost"
                        name="unit_cost"
                        required
                    >
                </div>

                <div class="col-12">
                    <button class="btn btn-danger" type="submit">
                        Add Ingredient
                    </button>
                </div>

            </div>
        </form>
    </section>

    <!-- SEARCH AND FILTER -->
    <section class="filter-panel mb-4">
        <h4 class="mb-3">Search and Filter Inventory</h4>

        <form method="GET" action="inventory.php">
            <div class="row g-3 align-items-end">

                <div class="col-md-6">
                    <label class="form-label" for="search">
                        Search Ingredient
                    </label>

                    <input
                        class="form-control"
                        type="search"
                        id="search"
                        name="search"
                        value="<?= inventoryHtml($search) ?>"
                        placeholder="Enter ingredient name..."
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="stock_filter">
                        Stock Status
                    </label>

                    <select
                        class="form-select"
                        id="stock_filter"
                        name="stock_filter"
                    >
                        <option value="">All stock levels</option>

                        <option
                            value="low"
                            <?= $stockFilter === 'low' ? 'selected' : '' ?>
                        >
                            Low Stock
                        </option>

                        <option
                            value="normal"
                            <?= $stockFilter === 'normal' ? 'selected' : '' ?>
                        >
                            In Stock
                        </option>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-danger" type="submit">
                        Apply
                    </button>

                    <a href="inventory.php" class="btn btn-outline-secondary">
                        Reset
                    </a>
                </div>

            </div>
        </form>
    </section>

    <!-- INGREDIENT STOCK TABLE -->
    <section class="panel p-4">
        <h4 class="mb-3">Ingredient Stock</h4>

        <div class="table-responsive">
            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Stock</th>
                        <th>Low Stock At</th>
                        <th>Unit Cost</th>
                        <th>Stock Value</th>
                        <th>Status</th>
                        <th>Restock</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($ingredients->num_rows === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No matching ingredients found.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php while ($item = $ingredients->fetch_assoc()): ?>
                        <?php
                        $low = (float)$item['quantity_in_stock']
                            <= (float)$item['reorder_level'];

                        $stockValue = (float)$item['quantity_in_stock']
                            * (float)$item['unit_cost'];
                        ?>

                        <tr>
                            <td>
                                <strong>
                                    <?= inventoryHtml($item['ingredient_name']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= number_format((float)$item['quantity_in_stock'], 2) ?>
                                <?= inventoryHtml($item['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float)$item['reorder_level'], 2) ?>
                                <?= inventoryHtml($item['unit']) ?>
                            </td>

                            <td>
                                <?= inventoryPeso($item['unit_cost']) ?>
                            </td>

                            <td>
                                <?= inventoryPeso($stockValue) ?>
                            </td>

                            <td>
                                <?php if ($low): ?>
                                    <span class="badge text-bg-danger">
                                        Low Stock
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">
                                        In Stock
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    action="inventory.php"
                                    class="d-flex gap-2"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= inventoryHtml($_SESSION['inventory_csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="restock"
                                    >

                                    <input
                                        type="hidden"
                                        name="ingredient_id"
                                        value="<?= (int)$item['id'] ?>"
                                    >

                                    <input
                                        class="form-control form-control-sm"
                                        type="number"
                                        name="restock_quantity"
                                        min="0.01"
                                        step="0.01"
                                        placeholder="Qty"
                                        aria-label="Restock quantity for <?= inventoryHtml($item['ingredient_name']) ?>"
                                        style="max-width: 105px"
                                        required
                                    >

                                    <button
                                        class="btn btn-sm btn-outline-danger"
                                        type="submit"
                                    >
                                        Restock
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
        Restocking increases the available quantity and records an
        inventory transaction. Low stock means the quantity is at or
        below the ingredient's reorder level.
    </p>

</main>

</body>
</html>
