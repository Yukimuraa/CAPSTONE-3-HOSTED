<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is student
require_student();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Support both old format (date_covered) and new format (start_date, end_date)
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $date_covered = $_POST['date_covered'] ?? '';
    
    // If using old format, convert to new format
    if (!empty($date_covered) && empty($start_date)) {
        $start_date = $date_covered;
        $end_date = $date_covered;
    }
    
    if (empty($start_date) || empty($end_date)) {
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }
    
    // Validate date range
    if (strtotime($end_date) < strtotime($start_date)) {
        echo json_encode(['error' => 'End date cannot be before start date']);
        exit;
    }
    
    // Per-bus availability for the date range (only available buses)
    $buses = [];
    $buses_query = "SELECT b.id, b.bus_number FROM buses b WHERE b.status = 'available' ORDER BY CAST(b.bus_number AS UNSIGNED) ASC";
    $buses_result = $conn->query($buses_query);
    
    // Preload booked bus ids for the date range
    // A bus is unavailable if it's booked on ANY date in the range
    $booked_ids = [];
    $booked_query = "SELECT DISTINCT bb.bus_id 
                     FROM bus_bookings bb 
                     JOIN bus_schedules bs ON bb.schedule_id = bs.id 
                     WHERE bb.booking_date BETWEEN ? AND ? 
                     AND bb.status = 'active' 
                     AND bs.status IN ('pending', 'approved')";
    $booked_stmt = $conn->prepare($booked_query);
    $booked_stmt->bind_param("ss", $start_date, $end_date);
    $booked_stmt->execute();
    $booked_result = $booked_stmt->get_result();
    while ($row = $booked_result->fetch_assoc()) {
        $booked_ids[(int)$row['bus_id']] = true;
    }
    
    while ($row = $buses_result->fetch_assoc()) {
        $busId = (int)$row['id'];
        $isAvailable = !isset($booked_ids[$busId]);
        $buses[] = [
            'id' => $busId,
            'bus_number' => $row['bus_number'],
            'available' => $isAvailable
        ];
    }
    
    $total_buses = count($buses);
    $booked_buses = count($booked_ids);
    $available_buses = $total_buses - $booked_buses;
    
    echo json_encode([
        'total_buses' => $total_buses,
        'booked_buses' => $booked_buses,
        'available_buses' => $available_buses,
        'can_book' => $available_buses > 0, // At least 1 bus available
        'buses' => $buses
    ]);
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>

