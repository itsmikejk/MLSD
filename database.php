<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Keep your existing database connection code below.

$host = "localhost";
$username = "root";
$password = ""; // Default XAMPP setup; change if yours has a password
$database = "mlsd_sweets";

try {
    $conn = new mysqli($host, $username, $password, $database);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    exit("Database connection failed. Check config/database.php and make sure MySQL is running.");
}
