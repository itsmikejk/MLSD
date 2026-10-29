
<?php
require_once __DIR__ . '/auth.php';
requireAdmin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['users_csrf'], $token)) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $fullName = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'staff';

            if ($fullName === '' || $username === '' || $password === '') {
                $error = 'Please complete all required fields.';
            } elseif (mb_strlen($fullName) > 100 ||
                      mb_strlen($username) > 50) {
                $error = 'The name or username is too long.';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                $error = 'Username may contain letters, numbers, dots, underscores, and hyphens only.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must contain at least 8 characters.';
            } elseif (!in_array($role, ['admin', 'staff'], true)) {
                $error = 'Please select a valid role.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                try {
                    $stmt = $conn->prepare(
                        "INSERT INTO admin_users
                            (username, password_hash, full_name, role, is_active)
                         VALUES (?, ?, ?, ?, 1)"
                    );

                    $stmt->bind_param(
                        "ssss",
                        $username,
                        $passwordHash,
                        $fullName,
                        $role
                    );

                    $stmt->execute();
                    $stmt->close();

                    $message = 'User account created successfully.';
                } catch (mysqli_sql_exception $ex) {
                    if ((int)$ex->getCode() === 1062) {
                        $error = 'That username already exists. Please choose another.';
                    } else {
                        error_log($ex->getMessage());
                        $error = 'Could not create the account. Check the PHP error log.';
                    }
                }
            }
        } elseif ($action === 'toggle') {
            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$userId || $userId < 1) {
                $error = 'Invalid user account.';
            } elseif ($userId === (int)$_SESSION['admin_id']) {
                $error = 'You cannot deactivate your own account.';
            } else {
                $stmt = $conn->prepare(
                    "UPDATE admin_users
                     SET is_active = IF(is_active = 1, 0, 1)
                     WHERE id = ?"
                );

                $stmt->bind_param("i", $userId);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $message = 'Account status updated successfully.';
                } else {
                    $check = $conn->prepare(
                        "SELECT id FROM admin_users WHERE id = ?"
                    );
                    $check->bind_param("i", $userId);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if ($exists) {
                        $message = 'The account status is unchanged.';
                    } else {
                        $error = 'User account not found.';
                    }
                }

                $stmt->close();
            }
        } else {
            $error = 'Invalid action.';
        }
    }
}

// Search and filter.
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$sql = "
    SELECT id, username, full_name, role, is_active, created_at
    FROM admin_users
    WHERE 1 = 1
";

$types = '';
$params = [];

if ($search !== '') {
    $sql .= " AND (username LIKE ? OR full_name LIKE ?)";
    $term = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $term;
    $params[] = $term;
}

if (in_array($roleFilter, ['admin', 'staff'], true)) {
    $sql .= " AND role = ?";
    $types .= 's';
    $params[] = $roleFilter;
}

if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $active = $statusFilter === 'active' ? 1 : 0;
    $sql .= " AND is_active = ?";
    $types .= 'i';
    $params[] = $active;
}

$sql .= " ORDER BY created_at DESC, id DESC";

$stmt = $conn->prepare($sql);

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$users = $stmt->get_result();

$countResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(is_active = 1), 0) AS active,
        COALESCE(SUM(is_active = 0), 0) AS inactive
    FROM admin_users
");

$counts = $countResult->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management | MLSD</title>

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
            background: #fff;
            border: 0;
            border-radius: 15px;
            box-shadow: 0 4px 18px #0000000c;
        }

        .brand-color {
            color: #b83267;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark px-3">
    <a class="navbar-brand fw-bold" href="dashboard.php">
        MLSD Admin
    </a>

    <div class="d-flex flex-wrap gap-2">
        <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
        <a href="orders.php" class="btn btn-outline-light btn-sm">Orders</a>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Log out</a>
    </div>
</nav>

<main class="container py-4">
    <h2 class="fw-bold">User Management</h2>
    <p class="text-muted">
        Manage administrator and staff accounts, roles, and access status.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="panel p-3">
                <div class="text-muted">Total Accounts</div>
                <h3 class="brand-color"><?= number_format((int)$counts['total']) ?></h3>
            </div>
        </div>

        <div class="col-md-4">
            <div class="panel p-3">
                <div class="text-muted">Active Accounts</div>
                <h3 class="text-success"><?= number_format((int)$counts['active']) ?></h3>
            </div>
        </div>

        <div class="col-md-4">
            <div class="panel p-3">
                <div class="text-muted">Inactive Accounts</div>
                <h3 class="text-danger"><?= number_format((int)$counts['inactive']) ?></h3>
            </div>
        </div>
    </div>

    <section class="panel p-4 mb-4">
        <h4 class="mb-3">Create User Account</h4>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['users_csrf']) ?>">
            <input type="hidden" name="action" value="add">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="full_name">Full Name</label>
                    <input class="form-control" id="full_name" name="full_name"
                           maxlength="100" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="username">Username</label>
                    <input class="form-control" id="username" name="username"
                           maxlength="50" autocomplete="off" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="password">Initial Password</label>
                    <input type="password" class="form-control" id="password"
                           name="password" minlength="8" autocomplete="new-password"
                           required>
                    <small class="text-muted">At least 8 characters.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="role">Role</label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="staff" selected>Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-danger mt-3">
                Create Account
            </button>
        </form>
    </section>

    <section class="panel p-4">
        <h4 class="mb-3">Existing Accounts</h4>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-5">
                <input class="form-control" name="search"
                       placeholder="Search name or username"
                       value="<?= e($search) ?>">
            </div>

            <div class="col-md-3">
                <select class="form-select" name="role">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>
                        Admin
                    </option>
                    <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>
                        Staff
                    </option>
                </select>
            </div>

            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Inactive
                    </option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-danger" type="submit">Filter</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($users->num_rows === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            No accounts found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($user = $users->fetch_assoc()): ?>
                        <tr>
                            <td><?= e($user['full_name']) ?></td>
                            <td><?= e($user['username']) ?></td>
                            <td>
                                <span class="badge text-bg-<?= $user['role'] === 'admin' ? 'danger' : 'secondary' ?>">
                                    <?= e(ucfirst($user['role'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ((int)$user['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($user['created_at']) ?></td>
                            <td>
                                <?php if ((int)$user['id'] === (int)$_SESSION['admin_id']): ?>
                                    <span class="text-muted">Current account</span>
                                <?php else: ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Change this account status?');">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['users_csrf']) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="user_id"
                                               value="<?= (int)$user['id'] ?>">

                                        <button type="submit"
                                                class="btn btn-sm <?= (int)$user['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <?= (int)$user['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
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
