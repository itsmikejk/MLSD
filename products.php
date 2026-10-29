<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function productHtml($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function productPeso($value): string
{
    return '₱' . number_format((float)$value, 2);
}

function productImageUrl($image, bool $adminPage = true): string
{
    $image = trim((string)($image ?? ''));

    if ($image === '') {
        return '';
    }

    // Existing external image URLs remain supported.
    if (preg_match('~^https?://~i', $image)) {
        return $image;
    }

    // Local uploaded images are stored relative to the MLSD root.
    if (str_starts_with($image, 'uploads/products/')) {
        return ($adminPage ? '../' : '') . $image;
    }

    return $image;
}

$categories = [
    'Birthday Cake',
    'Wedding Cake',
    'Debut Cake',
    'Anniversary Cake',
    'Customized Cake',
    'Other'
];

/* HANDLE FORM SUBMISSIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $productId = filter_input(
                INPUT_POST,
                'product_id',
                FILTER_VALIDATE_INT
            );

            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $priceInput = $_POST['price'] ?? '';
            $available = isset($_POST['is_available']) ? 1 : 0;

            $image = '';
            $uploadedImage = null;
            $uploadError = '';

            $uploadDirectory = __DIR__ . '/../uploads/products/';

            /*
             * Preserve the current image when editing without
             * selecting a new photo.
             */
            if ($action === 'edit') {
                if (!$productId || $productId < 1) {
                    $error = 'Invalid product selected.';
                } else {
                    $existingStmt = $conn->prepare(
                        'SELECT image FROM products WHERE id = ? LIMIT 1'
                    );

                    $existingStmt->bind_param('i', $productId);
                    $existingStmt->execute();

                    $existingRow = $existingStmt
                        ->get_result()
                        ->fetch_assoc();

                    $existingStmt->close();

                    if (!$existingRow) {
                        $error = 'The selected product was not found.';
                    } else {
                        $image = (string)($existingRow['image'] ?? '');
                    }
                }
            }

            /* VALIDATE AND SAVE UPLOADED IMAGE */
            if (
                isset($_FILES['image_file']) &&
                $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                $file = $_FILES['image_file'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $uploadError = 'Image upload failed. Please try again.';
                } elseif ($file['size'] > 2 * 1024 * 1024) {
                    $uploadError = 'Image must not exceed 2 MB.';
                } else {
                    $imageInfo = @getimagesize($file['tmp_name']);

                    $allowedTypes = [
                        IMAGETYPE_JPEG => 'jpg',
                        IMAGETYPE_PNG  => 'png',
                        IMAGETYPE_WEBP => 'webp'
                    ];

                    if (
                        $imageInfo === false ||
                        !isset($allowedTypes[$imageInfo[2]])
                    ) {
                        $uploadError =
                            'Please upload a valid JPG, PNG, or WebP image.';
                    } else {
                        if (
                            !is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0755, true)
                        ) {
                            $uploadError =
                                'Could not create the product image folder.';
                        } else {
                            $filename = bin2hex(random_bytes(16))
                                . '.'
                                . $allowedTypes[$imageInfo[2]];

                            if (
                                move_uploaded_file(
                                    $file['tmp_name'],
                                    $uploadDirectory . $filename
                                )
                            ) {
                                $uploadedImage =
                                    'uploads/products/' . $filename;
                            } else {
                                $uploadError =
                                    'Could not save the uploaded image.';
                            }
                        }
                    }
                }
            }

            /* VALIDATE PRODUCT DETAILS */
            if ($error === '') {
                if ($uploadError !== '') {
                    $error = $uploadError;
                } elseif (
                    $name === '' ||
                    $category === '' ||
                    !is_numeric($priceInput) ||
                    !is_finite((float)$priceInput) ||
                    (float)$priceInput < 0 ||
                    !in_array($category, $categories, true)
                ) {
                    $error =
                        'Enter a valid cake name, category, and non-negative price.';
                } elseif (
                    strlen($name) > 120 ||
                    strlen($category) > 80 ||
                    strlen($description) > 10000
                ) {
                    $error = 'Some fields are too long.';
                } else {
                    $price = (float)$priceInput;

                    if ($uploadedImage !== null) {
                        $image = $uploadedImage;
                    }

                    if (strlen($image) > 255) {
                        $error = 'The image path is too long.';
                    } else {
                        try {
                            if ($action === 'add') {
                                $stmt = $conn->prepare(
                                    "INSERT INTO products
                                        (name, description, category, price,
                                         image, is_available)
                                     VALUES (?, ?, ?, ?, NULLIF(?, ''), ?)"
                                );

                                $stmt->bind_param(
                                    'sssdsi',
                                    $name,
                                    $description,
                                    $category,
                                    $price,
                                    $image,
                                    $available
                                );

                                $stmt->execute();
                                $stmt->close();

                                $message =
                                    'Cake product added successfully!';
                            } else {
                                $stmt = $conn->prepare(
                                    "UPDATE products
                                     SET name = ?,
                                         description = ?,
                                         category = ?,
                                         price = ?,
                                         image = NULLIF(?, ''),
                                         is_available = ?
                                     WHERE id = ?"
                                );

                                $stmt->bind_param(
                                    'sssdsii',
                                    $name,
                                    $description,
                                    $category,
                                    $price,
                                    $image,
                                    $available,
                                    $productId
                                );

                                $stmt->execute();
                                $stmt->close();

                                $message =
                                    'Cake product updated successfully!';
                            }
                        } catch (Throwable $e) {
                            $error =
                                'Could not save the product. Please check the details and try again.';
                            error_log(
                                'MLSD product save error: ' . $e->getMessage()
                            );
                        }
                    }
                }
            }

            /*
             * Delete the newly uploaded file if saving the product
             * failed, to avoid leaving an unused file behind.
             */
            if ($uploadedImage !== null && $error !== '') {
                $newFile = __DIR__ . '/../' . $uploadedImage;

                if (is_file($newFile)) {
                    @unlink($newFile);
                }
            }
        } elseif ($action === 'toggle') {
            $productId = filter_input(
                INPUT_POST,
                'product_id',
                FILTER_VALIDATE_INT
            );

            $newAvailability = filter_input(
                INPUT_POST,
                'new_availability',
                FILTER_VALIDATE_INT
            );

            if (
                !$productId ||
                $productId < 1 ||
                !in_array($newAvailability, [0, 1], true)
            ) {
                $error = 'Invalid product or availability selection.';
            } else {
                $stmt = $conn->prepare(
                    'UPDATE products SET is_available = ? WHERE id = ?'
                );

                $stmt->bind_param(
                    'ii',
                    $newAvailability,
                    $productId
                );

                $stmt->execute();
                $stmt->close();

                $message = $newAvailability === 1
                    ? 'Cake is now available on the public website.'
                    : 'Cake hidden from the public website.';
            }
        }
    }
}

/* LOAD PRODUCT FOR EDITING */
$editProduct = null;

if (isset($_GET['edit'])) {
    $editId = filter_input(
        INPUT_GET,
        'edit',
        FILTER_VALIDATE_INT
    );

    if ($editId && $editId > 0) {
        $stmt = $conn->prepare(
            "SELECT id, name, description, category, price, image, is_available
             FROM products
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param('i', $editId);
        $stmt->execute();

        $editProduct = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$editProduct) {
            $error = 'The selected product was not found.';
        }
    }
}

/* SEARCH AND FILTER */
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category_filter'] ?? '');
$availabilityFilter = $_GET['availability_filter'] ?? '';

if (!in_array($categoryFilter, $categories, true)) {
    $categoryFilter = '';
}

if (!in_array($availabilityFilter, ['', '1', '0'], true)) {
    $availabilityFilter = '';
}

$sql = "
    SELECT id, name, description, category, price, image, is_available
    FROM products
";

$conditions = [];
$params = [];
$types = '';

if ($search !== '') {
    $conditions[] = '(name LIKE ? OR description LIKE ?)';

    $searchLike = '%' . $search . '%';

    $params[] = $searchLike;
    $params[] = $searchLike;

    $types .= 'ss';
}

if ($categoryFilter !== '') {
    $conditions[] = 'category = ?';
    $params[] = $categoryFilter;
    $types .= 's';
}

if ($availabilityFilter !== '') {
    $conditions[] = 'is_available = ?';
    $params[] = (int)$availabilityFilter;
    $types .= 'i';
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY id DESC';

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$products = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Manage Cakes | MLSD Sweets and Delights</title>

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

        .filter-panel {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 18px #0000000c;
        }

        .cake-image {
            width: 58px;
            height: 58px;
            object-fit: cover;
            border-radius: 8px;
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

    <h2 class="fw-bold page-title">Manage Cake Products</h2>

    <p class="text-muted">
        Add, edit, search, filter, and manage cake availability.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success" role="alert">
            <?= productHtml($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= productHtml($error) ?>
        </div>
    <?php endif; ?>

    <!-- ADD OR EDIT PRODUCT -->
    <section class="panel p-4 mb-4">
        <h4 class="mb-3">
            <?= $editProduct ? 'Edit Cake' : 'Add a Cake' ?>
        </h4>

        <form
            method="POST"
            action="products.php<?= $editProduct ? '?edit=' . (int)$editProduct['id'] : '' ?>"
            enctype="multipart/form-data"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= productHtml($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="<?= $editProduct ? 'edit' : 'add' ?>"
            >

            <?php if ($editProduct): ?>
                <input
                    type="hidden"
                    name="product_id"
                    value="<?= (int)$editProduct['id'] ?>"
                >
            <?php endif; ?>

            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label" for="name">
                        Cake Name *
                    </label>

                    <input
                        class="form-control"
                        id="name"
                        name="name"
                        maxlength="120"
                        value="<?= productHtml($editProduct['name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="category">
                        Category *
                    </label>

                    <select
                        class="form-select"
                        id="category"
                        name="category"
                        required
                    >
                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= productHtml($category) ?>"
                                <?= ($editProduct['category'] ?? 'Customized Cake') === $category ? 'selected' : '' ?>
                            >
                                <?= productHtml($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="price">
                        Price (₱) *
                    </label>

                    <input
                        class="form-control"
                        id="price"
                        type="number"
                        name="price"
                        min="0"
                        step="0.01"
                        value="<?= productHtml($editProduct['price'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="image_file">
                        Upload Cake Photo
                    </label>

                    <input
                        class="form-control"
                        id="image_file"
                        type="file"
                        name="image_file"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    >

                    <small class="text-muted d-block mt-1">
                        JPG, PNG, or WebP. Maximum size: 2 MB.
                        Leave empty when editing to keep the current photo.
                    </small>

                    <?php if (!empty($editProduct['image'])): ?>
                        <div class="mt-2">
                            <span class="small text-muted">Current photo:</span>
                            <br>

                            <img
                                class="cake-image mt-1"
                                src="<?= productHtml(productImageUrl($editProduct['image'])) ?>"
                                alt="Current cake photo"
                            >
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="description">
                        Description
                    </label>

                    <textarea
                        class="form-control"
                        id="description"
                        name="description"
                        rows="3"
                    ><?= productHtml($editProduct['description'] ?? '') ?></textarea>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="is_available"
                            id="is_available"
                            value="1"
                            <?= !$editProduct || (int)$editProduct['is_available'] === 1 ? 'checked' : '' ?>
                        >

                        <label class="form-check-label" for="is_available">
                            Available on public website
                        </label>
                    </div>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2">
                    <button class="btn btn-danger" type="submit">
                        <?= $editProduct ? 'Save Changes' : 'Add Cake' ?>
                    </button>

                    <?php if ($editProduct): ?>
                        <a
                            href="products.php"
                            class="btn btn-outline-secondary"
                        >
                            Cancel Editing
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </form>
    </section>

    <!-- SEARCH AND FILTER -->
    <section class="filter-panel mb-4">
        <h4 class="mb-3">Search and Filter Cakes</h4>

        <form method="GET" action="products.php">
            <div class="row g-3 align-items-end">

                <div class="col-md-4">
                    <label class="form-label" for="search">
                        Search by name or description
                    </label>

                    <input
                        class="form-control"
                        type="search"
                        id="search"
                        name="search"
                        value="<?= productHtml($search) ?>"
                        placeholder="Enter cake name..."
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="category_filter">
                        Category
                    </label>

                    <select
                        class="form-select"
                        id="category_filter"
                        name="category_filter"
                    >
                        <option value="">All categories</option>

                        <?php foreach ($categories as $categoryOption): ?>
                            <option
                                value="<?= productHtml($categoryOption) ?>"
                                <?= $categoryFilter === $categoryOption ? 'selected' : '' ?>
                            >
                                <?= productHtml($categoryOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="availability_filter">
                        Availability
                    </label>

                    <select
                        class="form-select"
                        id="availability_filter"
                        name="availability_filter"
                    >
                        <option value="">All products</option>

                        <option
                            value="1"
                            <?= $availabilityFilter === '1' ? 'selected' : '' ?>
                        >
                            Available
                        </option>

                        <option
                            value="0"
                            <?= $availabilityFilter === '0' ? 'selected' : '' ?>
                        >
                            Hidden
                        </option>
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-danger" type="submit">
                        Apply
                    </button>

                    <a
                        href="products.php"
                        class="btn btn-outline-secondary"
                    >
                        Reset
                    </a>
                </div>

            </div>
        </form>
    </section>

    <!-- PRODUCT TABLE -->
    <section class="panel p-4">
        <h4 class="mb-3">Cake Products</h4>

        <div class="table-responsive">
            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Cake</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($products->num_rows === 0): ?>
                    <tr>
                        <td
                            colspan="7"
                            class="text-center text-muted py-4"
                        >
                            No matching cakes found.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php while ($product = $products->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int)$product['id'] ?></td>

                            <td>
                                <?php if (!empty($product['image'])): ?>
                                    <img
                                        src="<?= productHtml(productImageUrl($product['image'])) ?>"
                                        alt="<?= productHtml($product['name']) ?>"
                                        class="cake-image"
                                    >
                                <?php else: ?>
                                    <span
                                        class="fs-3"
                                        aria-label="No image"
                                    >
                                        🎂
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <strong>
                                    <?= productHtml($product['name']) ?>
                                </strong>

                                <?php if (!empty($product['description'])): ?>
                                    <details class="small mt-1">
                                        <summary>Description</summary>
                                        <?= nl2br(productHtml($product['description'])) ?>
                                    </details>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= productHtml($product['category']) ?>
                            </td>

                            <td>
                                <?= productPeso($product['price']) ?>
                            </td>

                            <td>
                                <?php if ((int)$product['is_available'] === 1): ?>
                                    <span class="badge bg-success">
                                        Available
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">
                                        Hidden
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="d-flex flex-wrap gap-2">

                                    <a
                                        href="products.php?edit=<?= (int)$product['id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="products.php"
                                        onsubmit="return confirm('Change the availability of this cake?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= productHtml($_SESSION['csrf_token']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle"
                                        >

                                        <input
                                            type="hidden"
                                            name="product_id"
                                            value="<?= (int)$product['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="new_availability"
                                            value="<?= (int)$product['is_available'] === 1 ? 0 : 1 ?>"
                                        >

                                        <button
                                            class="btn btn-sm <?= (int)$product['is_available'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                            type="submit"
                                        >
                                            <?= (int)$product['is_available'] === 1 ? 'Hide' : 'Restore' ?>
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php endif; ?>
                </tbody>

            </table>
        </div>
    </section>

</main>

</body>
</html>