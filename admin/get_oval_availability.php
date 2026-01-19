<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

// Set JSON header
header('Content-Type: application/json');

// Simple error handling
try {
    $date = sanitize_input($_GET['date'] ?? '');
    $facility_id = intval($_GET['facility_id'] ?? 1);
    $exclude_booking_id = sanitize_input($_GET['exclude_booking_id'] ?? '');

    if (empty($date)) {
        echo json_encode(['error' => 'Date is required']);
        exit();
    }

    // Validate date format
    $date_obj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$date_obj) {
        echo json_encode(['error' => 'Invalid date format']);
        exit();
    }

    // Check if the date is blocked by a school event
    $blocked_query = "SELECT event_name, event_type, blocked_for_user_types, description 
                      FROM gym_blocked_dates 
                      WHERE ? BETWEEN start_date AND end_date 
                      AND is_active = 1";
    $blocked_stmt = $conn->prepare($blocked_query);
    $blocked_stmt->bind_param("s", $date);
    $blocked_stmt->execute();
    $blocked_result = $blocked_stmt->get_result();
    
    $is_blocked = false;
    $blocked_info = null;
    if ($blocked_result->num_rows > 0) {
        $is_blocked = true;
        $blocked_info = $blocked_result->fetch_assoc();
    }
    
    // If date is blocked, return immediately with no available sessions
    if ($is_blocked) {
        echo json_encode([
            'date' => $date,
            'sessions' => [],
            'booked' => [],
            'operating_hours' => '8:00 AM - 6:00 PM',
            'is_blocked' => true,
            'blocked_info' => $blocked_info,
            'message' => 'This date is blocked due to: ' . $blocked_info['event_name']
        ]);
        exit();
    }
    
    // Define gym operating hours (8 AM to 6 PM)
    $operating_hours = [
        ['start' => '08:00:00', 'end' => '18:00:00', 'label' => 'Whole Day Booking (8:00 AM - 6:00 PM)', 'type' => 'whole_day'],
        ['start' => '08:00:00', 'end' => '10:00:00', 'label' => 'Morning Session (8:00 AM - 10:00 AM)', 'type' => 'session'],
        ['start' => '10:00:00', 'end' => '12:00:00', 'label' => 'Late Morning Session (10:00 AM - 12:00 PM)', 'type' => 'session'],
        ['start' => '13:00:00', 'end' => '15:00:00', 'label' => 'Afternoon Session (1:00 PM - 3:00 PM)', 'type' => 'session'],
        ['start' => '15:00:00', 'end' => '17:00:00', 'label' => 'Late Afternoon Session (3:00 PM - 5:00 PM)', 'type' => 'session'],
        ['start' => '17:00:00', 'end' => '18:00:00', 'label' => 'Evening Session (5:00 PM - 6:00 PM)', 'type' => 'session']
    ];

    // Get existing bookings for the date (exclude current booking if rescheduling)
    // Include rescheduled bookings as they are still active reservations
    // Exclude cancelled and rejected bookings
    $bookings_query = "SELECT start_time, end_time, status FROM bookings 
                       WHERE facility_type = 'oval' 
                       AND date = ? 
                       AND status IN ('pending', 'confirmed', 'approved', 'rescheduled')
                       AND status NOT IN ('cancelled', 'canceled', 'rejected')";
    
    if (!empty($exclude_booking_id)) {
        $bookings_query .= " AND booking_id != ?";
    }
    
    $bookings_stmt = $conn->prepare($bookings_query);
    
    if (!empty($exclude_booking_id)) {
        $bookings_stmt->bind_param("ss", $date, $exclude_booking_id);
    } else {
        $bookings_stmt->bind_param("s", $date);
    }
    
    $bookings_stmt->execute();
    $bookings_result = $bookings_stmt->get_result();

    // Real bookings from DB
    $real_booked_slots = [];
    while ($booking = $bookings_result->fetch_assoc()) {
        $real_booked_slots[] = [
            'start' => $booking['start_time'],
            'end' => $booking['end_time']
        ];
    }
    // For session availability and UI free-slot display, include lunch break as a pseudo booking
    $session_blocked_slots = $real_booked_slots;
    $session_blocked_slots[] = [
        'start' => '12:00:00',
        'end' => '13:00:00'
    ];

    // Check availability for each session
    $sessions = [];
    foreach ($operating_hours as $session) {
        $is_available = true;
        
        if ($session['type'] === 'whole_day') {
            // Whole day booking is only available if there are NO REAL bookings for the entire day
            $is_available = empty($real_booked_slots);
        } else {
            // Regular session - check if it conflicts with any existing booking
            foreach ($session_blocked_slots as $booked) {
                if (($session['start'] < $booked['end'] && $session['end'] > $booked['start'])) {
                    $is_available = false;
                    break;
                }
            }
        }
        
        $sessions[] = [
            'start' => $session['start'],
            'end' => $session['end'],
            'label' => $session['label'],
            'type' => $session['type'],
            'available' => $is_available
        ];
    }

    // Format booked slots for display (use session-blocked to reflect lunch in free-slot calc)
    $booked_display = [];
    foreach ($session_blocked_slots as $slot) {
        $booked_display[] = [
            'start' => $slot['start'],
            'end' => $slot['end']
        ];
    }

    echo json_encode([
        'date' => $date,
        'sessions' => $sessions,
        'booked' => $booked_display,
        'operating_hours' => '8:00 AM - 6:00 PM',
        'is_blocked' => $is_blocked,
        'blocked_info' => $blocked_info
    ]);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => 'Check server logs for more details'
    ]);
}
?>
