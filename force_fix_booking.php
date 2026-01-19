<?php
require_once 'config/database.php';

echo "<h2>Force Fix Booking OVAL-2025-001</h2>";

// First, check current state
$check_query = "SELECT * FROM bookings WHERE booking_id = 'OVAL-2025-001'";
$check_result = $conn->query($check_query);
$booking = $check_result->fetch_assoc();

echo "<h3>Current State:</h3>";
echo "<p>Facility Type: '" . htmlspecialchars($booking['facility_type'] ?? 'NULL') . "' (length: " . strlen($booking['facility_type'] ?? '') . ")</p>";
echo "<p>User ID: " . htmlspecialchars($booking['user_id']) . "</p>";

// Try multiple update methods
echo "<hr><h3>Attempting Updates:</h3>";

// Method 1: Direct UPDATE with WHERE booking_id
echo "<h4>Method 1: UPDATE by booking_id</h4>";
$update1 = "UPDATE bookings SET facility_type = 'oval' WHERE booking_id = 'OVAL-2025-001'";
if ($conn->query($update1)) {
    echo "<p style='color: green;'>✓ Update executed</p>";
    echo "<p>Rows affected: " . $conn->affected_rows . "</p>";
} else {
    echo "<p style='color: red;'>✗ Error: " . $conn->error . "</p>";
}

// Method 2: UPDATE by ID with prepared statement
echo "<h4>Method 2: UPDATE by ID with prepared statement</h4>";
$update2 = "UPDATE bookings SET facility_type = ? WHERE id = ?";
$stmt2 = $conn->prepare($update2);
$facility_type = 'oval';
$booking_id_num = $booking['id'];
$stmt2->bind_param("si", $facility_type, $booking_id_num);
if ($stmt2->execute()) {
    echo "<p style='color: green;'>✓ Update executed</p>";
    echo "<p>Rows affected: " . $stmt2->affected_rows . "</p>";
} else {
    echo "<p style='color: red;'>✗ Error: " . $stmt2->error . "</p>";
}

// Method 3: Check if there's a trigger or constraint
echo "<h4>Method 3: Check table structure</h4>";
$structure = $conn->query("SHOW COLUMNS FROM bookings LIKE 'facility_type'");
if ($structure && $structure->num_rows > 0) {
    $col = $structure->fetch_assoc();
    echo "<pre>";
    print_r($col);
    echo "</pre>";
    
    // Check for triggers
    $triggers = $conn->query("SHOW TRIGGERS WHERE `Table` = 'bookings'");
    if ($triggers && $triggers->num_rows > 0) {
        echo "<p style='color: orange;'>Found triggers on bookings table:</p>";
        while ($trigger = $triggers->fetch_assoc()) {
            echo "<p>" . htmlspecialchars($trigger['Trigger']) . "</p>";
        }
    } else {
        echo "<p>No triggers found</p>";
    }
}

// Verify after updates
echo "<hr><h3>Verification After Updates:</h3>";
$verify_query = "SELECT * FROM bookings WHERE booking_id = 'OVAL-2025-001'";
$verify_result = $conn->query($verify_query);
$verify_booking = $verify_result->fetch_assoc();

echo "<p><strong>Facility Type:</strong> '" . htmlspecialchars($verify_booking['facility_type'] ?? 'NULL') . "' (length: " . strlen($verify_booking['facility_type'] ?? '') . ")</p>";

if (($verify_booking['facility_type'] ?? '') === 'oval') {
    echo "<p style='color: green;'>✓ SUCCESS! Facility type is now 'oval'</p>";
    
    // Test the query
    $test_query = "SELECT * FROM bookings WHERE user_id = 95 AND facility_type = 'oval'";
    $test_result = $conn->query($test_query);
    if ($test_result && $test_result->num_rows > 0) {
        echo "<p style='color: green;'>✓ Query now works! Found " . $test_result->num_rows . " booking(s)</p>";
    } else {
        echo "<p style='color: red;'>✗ Query still doesn't work</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Facility type is still not 'oval'</p>";
    echo "<p>Raw value: ";
    var_dump($verify_booking['facility_type']);
    echo "</p>";
    
    // Try to see what's in the database
    $raw_query = "SELECT HEX(facility_type) as hex_value, LENGTH(facility_type) as len, facility_type FROM bookings WHERE booking_id = 'OVAL-2025-001'";
    $raw_result = $conn->query($raw_query);
    if ($raw_result) {
        $raw = $raw_result->fetch_assoc();
        echo "<p>Hex value: " . htmlspecialchars($raw['hex_value']) . "</p>";
        echo "<p>Length: " . htmlspecialchars($raw['len']) . "</p>";
        echo "<p>Raw value: " . htmlspecialchars($raw['facility_type']) . "</p>";
    }
}

?>





