<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

header('Content-Type: application/json');

try {
    // Get exclude_booking_id if provided (for rescheduling - exclude current booking)
    $exclude_booking_id = sanitize_input($_GET['exclude_booking_id'] ?? '');
    
    // Get all booked dates for oval field (active bookings only)
    // Include rescheduled bookings as they block the date
    $booked_dates_query = "SELECT DISTINCT date FROM bookings 
                           WHERE (facility_type = 'oval' OR booking_id LIKE 'OVAL-%')
                           AND date >= CURDATE()
                           AND status IN ('pending', 'confirmed', 'approved', 'rescheduled')
                           AND status NOT IN ('cancelled', 'canceled', 'rejected')";
    
    if (!empty($exclude_booking_id)) {
        $booked_dates_query .= " AND booking_id != ?";
    }
    
    $booked_dates_query .= " ORDER BY date ASC";
    
    $stmt = $conn->prepare($booked_dates_query);
    if (!empty($exclude_booking_id)) {
        $stmt->bind_param("s", $exclude_booking_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $booked_dates = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $booked_dates[] = $row['date'];
        }
    }

    echo json_encode([
        'success' => true,
        'booked_dates' => $booked_dates
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'booked_dates' => []
    ]);
}
?>


