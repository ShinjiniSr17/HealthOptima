<?php
function connectDB() {
    $servername = "localhost";
    $username = "root"; // default for XAMPP
    $password = "";
    $dbname = "healthoptima_db";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }
    return $conn;
}
?>
