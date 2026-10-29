
<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Initialize shopping cart
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cartCount = array_sum($_SESSION['cart']);

// Get available cakes from database
$sql = "SELECT id, name, description, category, price, image
        FROM products
        WHERE is_available = 1
        ORDER BY id DESC";

$result = $conn->query($sql);

// Helper function to safely display text
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Helper function to determine the correct image path
function getCakeImagePath($image) {
    $image = trim((string)($image ?? ''));

    // Use a placeholder if no image is saved
    if ($image === '') {
        return 'https://placehold.co/600x400?text=Cake';
    }

    // Keep external image URLs and absolute paths unchanged
    if (preg_match('~^(https?://|/)~i', $image)) {
        return $image;
    }

    // Remove any leading slash
    $image = ltrim($image, '/');

    // If the database already contains uploads/products/...
    if (strpos($image, 'uploads/products/') === 0) {
        return $image;
    }

    // If the database contains uploads/filename.jpg
    if (strpos($image, 'uploads/') === 0) {
        return $image;
    }

    // If only the filename is stored
    return 'uploads/products/' . $image;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>MLSD Sweets and Delights</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        html {
            scroll-behavior: smooth;
            scroll-padding-top: 80px;
        }

        body {
            background: #fff8f5;
            font-family: Arial, sans-serif;
            color: #49333a;
        }

        .navbar,
        footer {
            background: #8b4557;
        }

        .navbar-brand,
        .nav-link {
            color: white !important;
        }

        .nav-link:hover {
            color: #ffdce5 !important;
        }

        .hero {
            background: #f6dce3;
            padding: 85px 20px;
            text-align: center;
        }

        .hero h1 {
            color: #8b4557;
            font-size: clamp(2rem, 5vw, 3.2rem);
        }

        .hero p {
            max-width: 750px;
            margin-left: auto;
            margin-right: auto;
        }

        .section-padding {
            padding: 60px 0;
        }

        .section-title {
            color: #8b4557;
            font-weight: bold;
        }

        .cake-card {
            height: 100%;
            border: none;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(0, 0, 0, .1);
            transition: transform .2s ease;
        }

        .cake-card:hover {
            transform: translateY(-4px);
        }

        .cake-image {
            height: 220px;
            width: 100%;
            object-fit: cover;
            background: #f3eeee;
        }

        .price {
            color: #8b4557;
            font-weight: bold;
            font-size: 20px;
        }

        .btn-cake {
            background: #8b4557;
            color: white;
            border: none;
        }

        .btn-cake:hover {
            background: #6e3343;
            color: white;
        }

        .about-box,
        .contact-box {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, .06);
            height: 100%;
        }

        .about-box p,
        .contact-box p {
            line-height: 1.8;
        }

        footer {
            color: white;
            padding: 25px 0;
        }

        footer a {
            color: white;
            text-decoration: none;
        }

        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

<!-- NAVIGATION -->
<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container">

        <a class="navbar-brand fw-bold" href="index.php">
            MLSD Sweets and Delights
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarMenu"
            aria-controls="navbarMenu"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link" href="index.php">Home</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#cakes">Our Cakes</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="order.php">Order a Cake</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#about">About Us</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#contact">Contact</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="cart.php">
                        Cart (<?= (int)$cartCount ?>)
                    </a>
                </li>

                <li class="nav-item ms-lg-2">
                    <a
                        class="btn btn-light btn-sm mt-2 mt-lg-0"
                        href="admin/login.php"
                    >
                        Admin Login
                    </a>
                </li>

            </ul>
        </div>
    </div>
</nav>

<!-- HOME -->
<section class="hero" id="home">
    <div class="container">

        <h1 class="fw-bold">
            Baked with Love, Made for Your Moments
        </h1>

        <p class="lead mt-3">
            Customized cakes for birthdays, weddings, debuts,
            anniversaries, and every special celebration.
        </p>

        <a href="#cakes" class="btn btn-cake btn-lg mt-3">
            Explore Our Cakes
        </a>

    </div>
</section>

<!-- OUR CAKES -->
<section class="section-padding" id="cakes">
    <div class="container">

        <h2 class="section-title text-center mb-2">
            Our Cakes
        </h2>

        <p class="text-center text-muted mb-5">
            Sweet creations for your special moments.
        </p>

        <div class="row g-4">

            <?php if ($result && $result->num_rows > 0): ?>

                <?php while ($cake = $result->fetch_assoc()): ?>

                    <?php
                    $imagePath = getCakeImagePath($cake['image'] ?? '');
                    ?>

                    <div class="col-sm-6 col-lg-4">
                        <div class="card cake-card">

                            <img
                                src="<?= e($imagePath) ?>"
                                class="cake-image"
                                alt="<?= e($cake['name']) ?>"
                                loading="lazy"
                                onerror="this.onerror=null;this.src='https://placehold.co/600x400?text=Cake';"
                            >

                            <div class="card-body d-flex flex-column">

                                <h5 class="card-title">
                                    <?= e($cake['name']) ?>
                                </h5>

                                <p class="text-muted mb-2">
                                    <?= e($cake['category'] ?? '') ?>
                                </p>

                                <p class="card-text">
                                    <?= e($cake['description'] ?? '') ?>
                                </p>

                                <p class="price">
                                    ₱<?= number_format((float)$cake['price'], 2) ?>
                                </p>

                                <form
                                    action="cart.php"
                                    method="POST"
                                    class="mt-auto"
                                >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="add"
                                    >

                                    <input
                                        type="hidden"
                                        name="product_id"
                                        value="<?= (int)$cake['id'] ?>"
                                    >

                                    <label
                                        class="form-label"
                                        for="qty<?= (int)$cake['id'] ?>"
                                    >
                                        Quantity
                                    </label>

                                    <input
                                        type="number"
                                        name="quantity"
                                        id="qty<?= (int)$cake['id'] ?>"
                                        class="form-control mb-3"
                                        value="1"
                                        min="1"
                                        max="20"
                                        required
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-cake w-100"
                                    >
                                        Add to Cart
                                    </button>
                                </form>

                            </div>
                        </div>
                    </div>

                <?php endwhile; ?>

            <?php else: ?>

                <div class="col-12 text-center">
                    <p>No cakes are available right now.</p>
                </div>

            <?php endif; ?>

        </div>

        <div class="text-center mt-5">
            <a href="order.php" class="btn btn-cake btn-lg me-2 mb-2">
                Order a Cake
            </a>

            <a href="cart.php" class="btn btn-outline-secondary btn-lg mb-2">
                View Cart and Checkout
            </a>
        </div>

    </div>
</section>

<!-- ABOUT US -->
<section class="section-padding" id="about">
    <div class="container">

        <div class="about-box">
            <h2 class="section-title text-center mb-4">
                About MLSD
            </h2>

            <p class="mb-0">
                MLSD Sweets and Delights is a proposed home-based cake
                business that creates customized cakes for different
                occasions. Our goal is to make every celebration sweeter
                through delicious cakes and personalized designs.
            </p>
        </div>

    </div>
</section>

<!-- CONTACT US -->
<section
    class="section-padding"
    id="contact"
    style="background: #f6dce3;"
>
    <div class="container">

        <div class="row justify-content-center">
            <div class="col-lg-8">

                <div class="contact-box text-center">

                    <h2 class="section-title mb-4">
                        Contact Us
                    </h2>

                    <p>
                        Interested in ordering a customized cake?
                        Contact us through your official business
                        social media page.
                    </p>

                    <p>
                        Add your business Facebook page, contact number,
                        or email here.
                    </p>

                    <p class="mb-0">
                        <strong>MLSD Sweets and Delights</strong>
                    </p>

                </div>

            </div>
        </div>

    </div>
</section>

<!-- FOOTER -->
<footer>
    <div class="container text-center">

        <p class="mb-2">
            &copy; <?= date('Y') ?> MLSD Sweets and Delights.
            All rights reserved.
        </p>

        <p class="mb-2">
            Baked with Love, Made for Your Moments.
        </p>

        <a href="#home">Back to Top</a>
        |
        <a href="admin/login.php">Admin Login</a>

    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
