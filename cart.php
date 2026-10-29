<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function redirectToCart(): void
{
    header('Location: cart.php');
    exit;
}

function getCartItems(mysqli $conn): array
{
    $items = [];

    foreach ($_SESSION['cart'] as $productId => $quantity) {
        $productId = (int)$productId;
        $quantity = (int)$quantity;

        if ($productId < 1 || $quantity < 1) {
            continue;
        }

        $stmt = $conn->prepare(
            "SELECT id, name, price, image
             FROM products
             WHERE id = ? AND is_available = 1"
        );

        $stmt->bind_param("i", $productId);
        $stmt->execute();

        $result = $stmt->get_result();
        $product = $result->fetch_assoc();

        $stmt->close();

        if ($product) {
            $product['quantity'] = min($quantity, 20);
            $product['subtotal'] =
                (float)$product['price'] * $product['quantity'];

            $items[] = $product;
        } else {
            unset($_SESSION['cart'][$productId]);
        }
    }

    return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $productId = filter_input(
            INPUT_POST,
            'product_id',
            FILTER_VALIDATE_INT
        );

        $quantity = filter_input(
            INPUT_POST,
            'quantity',
            FILTER_VALIDATE_INT
        );

        if (!$productId || !$quantity || $quantity < 1 || $quantity > 20) {
            $_SESSION['cart_message'] = 'Please select a valid quantity.';
            redirectToCart();
        }

        $stmt = $conn->prepare(
            "SELECT id FROM products
             WHERE id = ? AND is_available = 1"
        );

        $stmt->bind_param("i", $productId);
        $stmt->execute();

        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;

        $stmt->close();

        if (!$exists) {
            $_SESSION['cart_message'] = 'This cake is currently unavailable.';
            redirectToCart();
        }

        $currentQuantity = (int)($_SESSION['cart'][$productId] ?? 0);
        $_SESSION['cart'][$productId] = min($currentQuantity + $quantity, 20);

        $_SESSION['cart_message'] = 'Cake added to your cart!';
        redirectToCart();
    }

    if ($action === 'update') {
        $quantities = $_POST['quantities'] ?? [];

        if (is_array($quantities)) {
            foreach ($quantities as $productId => $quantity) {
                $productId = (int)$productId;

                if ($productId < 1 || !isset($_SESSION['cart'][$productId])) {
                    continue;
                }

                if (is_array($quantity) || !is_numeric($quantity)) {
                    continue;
                }

                $quantity = (int)$quantity;

                if ($quantity <= 0) {
                    unset($_SESSION['cart'][$productId]);
                } else {
                    $_SESSION['cart'][$productId] = min($quantity, 20);
                }
            }
        }

        $_SESSION['cart_message'] = 'Cart updated.';
        redirectToCart();
    }

    if ($action === 'remove') {
        $productId = (int)($_POST['product_id'] ?? 0);
        unset($_SESSION['cart'][$productId]);

        $_SESSION['cart_message'] = 'Item removed from cart.';
        redirectToCart();
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        $_SESSION['cart_message'] = 'Cart cleared.';
        redirectToCart();
    }
}

$items = getCartItems($conn);
$total = 0;

foreach ($items as $item) {
    $total += $item['subtotal'];
}

$downpayment = $total * 0.50;
$message = $_SESSION['cart_message'] ?? '';
unset($_SESSION['cart_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - MLSD Sweets and Delights</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #fff8f5;
            font-family: Arial, sans-serif;
        }

        .navbar, footer {
            background: #8b4557;
            color: white;
        }

        .navbar-brand, .nav-link {
            color: white !important;
        }

        .btn-cake {
            background: #8b4557;
            color: white;
        }

        .btn-cake:hover {
            background: #6e3343;
            color: white;
        }

        .cart-box {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
        }

        footer {
            padding: 18px;
            margin-top: 40px;
            text-align: center;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            MLSD Sweets and Delights
        </a>

        <a href="index.php" class="nav-link">Continue Shopping</a>
    </div>
</nav>

<div class="container py-5">
    <h2 class="mb-4">Your Shopping Cart</h2>

    <?php if ($message !== ''): ?>
        <div class="alert alert-info">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($items)): ?>

        <div class="cart-box text-center">
            <h4>Your cart is empty.</h4>
            <p>Add some delicious cakes to get started!</p>
            <a href="index.php" class="btn btn-cake">Browse Cakes</a>
        </div>

    <?php else: ?>

        <div class="cart-box">
            <form method="POST" action="cart.php">
                <input type="hidden" name="action" value="update">

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cake</th>
                                <th>Price</th>
                                <th style="min-width: 100px;">Quantity</th>
                                <th>Subtotal</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($item['name']) ?>
                                </td>

                                <td>
                                    ₱<?= number_format((float)$item['price'], 2) ?>
                                </td>

                                <td>
                                    <input
                                        type="number"
                                        class="form-control"
                                        name="quantities[<?= (int)$item['id'] ?>]"
                                        value="<?= (int)$item['quantity'] ?>"
                                        min="0"
                                        max="20"
                                    >
                                    <small class="text-muted">Enter 0 to remove</small>
                                </td>

                                <td>
                                    ₱<?= number_format($item['subtotal'], 2) ?>
                                </td>

                                <td>
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        formaction="cart.php"
                                        formmethod="POST"
                                        formnovalidate
                                        name="action"
                                        value="remove"
                                        onclick="this.form.querySelector('[name=product_id]').value='<?= (int)$item['id'] ?>';"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <input type="hidden" name="product_id" value="">

                <button type="submit" class="btn btn-secondary">
                    Update Cart
                </button>
            </form>

            <hr>

            <div class="row justify-content-end">
                <div class="col-md-5">
                    <p class="d-flex justify-content-between">
                        <span>Order Total:</span>
                        <strong>₱<?= number_format($total, 2) ?></strong>
                    </p>

                    <p class="d-flex justify-content-between">
                        <span>Required 50% Downpayment:</span>
                        <strong class="text-success">
                            ₱<?= number_format($downpayment, 2) ?>
                        </strong>
                    </p>

                    <p class="text-muted small">
                        The remaining 50% is payable according to the
                        business's agreed payment arrangement. Cash on
                        delivery is not available.
                    </p>

                    <a href="order.php" class="btn btn-cake w-100 mb-2">
                        Proceed to Checkout
                    </a>

                    <form method="POST" action="cart.php"
                          onsubmit="return confirm('Clear all items from your cart?');">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            Clear Cart
                        </button>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<footer>
    &copy; <?= date('Y') ?> MLSD Sweets and Delights
</footer>

</body>
</html>