<?php
require_once 'config/database.php';

echo "<h2>Find OVAL-2025-001 Booking</h2>";

// Check if the specific booking ID exists
$check_id_query = "SELECT * FROM bookings WHERE booking_id = 'OVAL-2025-001'";
$check_id_result = $conn->query($check_id_query);

if ($check_id_result && $check_id_result->num_rows > 0) {
    echo "<p style='color: green;'><strong>✓ Found booking OVAL-2025-001!</strong></p>";
    while ($row = $check_id_result->fetch_assoc()) {
        echo "<h3>Booking Details:</h3>";
        echo "<table border='1' cellpadding='5'>";
        foreach ($row as $key => $value) {
            echo "<tr><td><strong>" . htmlspecialchars($key) . "</strong></td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
        }
        echo "</table>";
        
        // Check if user_id matches user account
        if (!empty($row['user_id'])) {
            $user_check = $conn->query("SELECT * FROM user_accounts WHERE id = " . intval($row['user_id']));
            if ($user_check && $user_check->num_rows > 0) {
                $user = $user_check->fetch_assoc();
                echo "<p style='color: green;'>✓ User account found: " . htmlspecialchars($user['name']) . " (ID: " . htmlspecialchars($user['id']) . ")</p>";
            } else {
                echo "<p style='color: red;'>✗ User account NOT found for user_id: " . htmlspecialchars($row['user_id']) . "</p>";
            }
        }
    }
} else {
    echo "<p style='color: red;'>✗ Booking OVAL-2025-001 NOT found</p>";
}

echo "<hr><h3>All Bookings (Any Facility Type):</h3>";
$all_bookings = $conn->query("SELECT booking_id, user_id, facility_type, date, status, created_at FROM bookings ORDER BY created_at DESC LIMIT 20");
if ($all_bookings && $all_bookings->num_rows > 0) {
    echo "<p>Found " . $all_bookings->num_rows . " recent booking(s):</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Booking ID</th><th>User ID</th><th>Facility Type</th><th>Date</th><th>Status</th><th>Created At</th></tr>";
    while ($row = $all_bookings->fetch_assoc()) {
        $highlight = (strpos($row['booking_id'], 'OVAL') !== false) ? "style='background: #ffffcc;'" : "";
        echo "<tr $highlight>";
        echo "<td>" . htmlspecialchars($row['booking_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['user_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['facility_type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['date']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No bookings found at all.</p>";
}

echo "<hr><h3>Check Table Structure:</h3>";
$structure = $conn->query("DESCRIBE bookings");
if ($structure) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($col = $structure->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr><h3>Test Queries:</h3>";

// Test 1: Find by facility_type = 'oval'
echo "<h4>1. Query: WHERE facility_type = 'oval'</h4>";
$test1 = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE facility_type = 'oval'");
if ($test1) {
    $result1 = $test1->fetch_assoc();
    echo "<p>Count: " . $result1['count'] . "</p>";
}

// Test 2: Find by booking_id pattern
echo "<h4>2. Query: WHERE booking_id LIKE 'OVAL-%'</h4>";
$test2 = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE booking_id LIKE 'OVAL-%'");
if ($test2) {
    $result2 = $test2->fetch_assoc();
    echo "<p>Count: " . $result2['count'] . "</p>";
    if ($result2['count'] > 0) {
        $details = $conn->query("SELECT * FROM bookings WHERE booking_id LIKE 'OVAL-%'");
        while ($row = $details->fetch_assoc()) {
            echo "<p>Found: " . htmlspecialchars($row['booking_id']) . " - Facility: " . htmlspecialchars($row['facility_type']) . " - User ID: " . htmlspecialchars($row['user_id']) . "</p>";
        }
    }
}

// Test 3: Find by user_id = 95
echo "<h4>3. Query: WHERE user_id = 95</h4>";
$test3 = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE user_id = 95");
if ($test3) {
    $result3 = $test3->fetch_assoc();
    echo "<p>Count: " . $result3['count'] . "</p>";
    if ($result3['count'] > 0) {
        $details = $conn->query("SELECT * FROM bookings WHERE user_id = 95");
        while ($row = $details->fetch_assoc()) {
            echo "<p>Found: " . htmlspecialchars($row['booking_id']) . " - Facility: " . htmlspecialchars($row['facility_type']) . " - Date: " . htmlspecialchars($row['date']) . "</p>";
        }
    }
}

?>





