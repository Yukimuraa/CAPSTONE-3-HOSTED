<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

try {
    // Get all booked dates for oval field (active bookings only)
    // Include rescheduled bookings as they block the date
    $booked_dates_query = "SELECT DISTINCT date FROM bookings 
                           WHERE (facility_type = 'oval' OR booking_id LIKE 'OVAL-%')
                           AND date >= CURDATE()
                           AND status IN ('pending', 'confirmed', 'approved', 'rescheduled')
                           AND status NOT IN ('cancelled', 'canceled', 'rejected')
                           ORDER BY date ASC";
    
    $result = $conn->query($booked_dates_query);
    $booked_dates = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $booked_dates[] = $row['date'];
        }
    }
    
    echo json_encode([
        'booked_dates' => $booked_dates,
        'success' => true
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'booked_dates' => [],
        'success' => false
    ]);
}
?>


