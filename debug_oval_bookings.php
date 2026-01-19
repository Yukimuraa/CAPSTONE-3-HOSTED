<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>Oval Bookings Debug</h2>";

// Check all oval bookings
echo "<h3>All Oval Bookings in Database:</h3>";
$all_query = "SELECT b.*, u.name as user_name, u.id as user_account_id 
              FROM bookings b 
              LEFT JOIN user_accounts u ON b.user_id = u.id 
              WHERE b.facility_type = 'oval' 
              ORDER BY b.created_at DESC";
$all_result = $conn->query($all_query);

if ($all_result && $all_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Booking ID</th><th>User ID</th><th>User Name</th><th>User Account ID</th><th>Date</th><th>Status</th><th>Created At</th></tr>";
    while ($row = $all_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['booking_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['user_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['user_name'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['user_account_id'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['date']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No oval bookings found in database.</p>";
}

// Check current session user_id if external user
if (isset($_SESSION['user_sessions']['external']['user_id'])) {
    $external_user_id = $_SESSION['user_sessions']['external']['user_id'];
    echo "<h3>Current External User ID from Session:</h3>";
    echo "<p>User ID: " . htmlspecialchars($external_user_id) . "</p>";
    
    // Check bookings for this user
    echo "<h3>Bookings for Current External User:</h3>";
    $user_query = "SELECT * FROM bookings WHERE user_id = ? AND facility_type = 'oval'";
    $user_stmt = $conn->prepare($user_query);
    $user_stmt->bind_param("i", $external_user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_result->num_rows > 0) {
        echo "<p>Found " . $user_result->num_rows . " booking(s)</p>";
        while ($row = $user_result->fetch_assoc()) {
            echo "<p>Booking ID: " . htmlspecialchars($row['booking_id']) . ", Date: " . htmlspecialchars($row['date']) . ", Status: " . htmlspecialchars($row['status']) . "</p>";
        }
    } else {
        echo "<p>No bookings found for user_id: " . htmlspecialchars($external_user_id) . "</p>";
    }
} else {
    echo "<h3>Session Info:</h3>";
    echo "<p>Not logged in as external user, or session not set.</p>";
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
}

// Check for bookings with NULL or 0 user_id
echo "<h3>Bookings with NULL or Invalid User ID:</h3>";
$null_query = "SELECT * FROM bookings WHERE facility_type = 'oval' AND (user_id IS NULL OR user_id = 0)";
$null_result = $conn->query($null_query);
if ($null_result && $null_result->num_rows > 0) {
    echo "<p>Found " . $null_result->num_rows . " booking(s) with NULL or 0 user_id</p>";
    while ($row = $null_result->fetch_assoc()) {
        echo "<p>Booking ID: " . htmlspecialchars($row['booking_id']) . ", Date: " . htmlspecialchars($row['date']) . "</p>";
    }
} else {
    echo "<p>No bookings with NULL or 0 user_id found.</p>";
}

?>





