<?php
// config/db_connect.php

// We use getenv() to fetch credentials from Railway's secure environment.
// If they don't exist (like on your local XAMPP), it falls back to your local credentials.

$host = getenv('DB_HOST') ?: 'aws-0-ap-southeast-1.pooler.supabase.com'; 
$db   = getenv('DB_NAME') ?: 'postgres';
$user = getenv('DB_USER') ?: 'postgres.your_supabase_id';
$pass = getenv('DB_PASS') ?: 'your_local_password';
$port = getenv('DB_PORT') ?: '6543'; // Supabase usually uses 6543 or 5432

$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>