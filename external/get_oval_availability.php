<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Simple error handling
try {
    // Check if user is external (but don't fail if not)
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'external') {
        // For now, allow access - you can add proper auth later
    }

    $date = sanitize_input($_GET['date'] ?? '');
    $facility_id = intval($_GET['facility_id'] ?? 1);

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

    // Check if the date is blocked by a school event for external users
    $blocked_query = "SELECT event_name, event_type, blocked_for_user_types, description 
                      FROM gym_blocked_dates 
                      WHERE ? BETWEEN start_date AND end_date 
                      AND is_active = 1
                      AND (blocked_for_user_types = 'all' OR blocked_for_user_types = 'external')";
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
    
    // Check if month is allowed for external users
    $booking_month = (int)$date_obj->format('n');
    $month_allowed = true;
    $allowed_months_list = [];
    
    $rules_check = $conn->query("SELECT allowed_months, is_active FROM gym_booking_rules WHERE user_type = 'external' AND is_active = 1 LIMIT 1");
    if ($rules_check && $rules_check->num_rows > 0) {
        $rules = $rules_check->fetch_assoc();
        $allowed_months_list = array_map('intval', explode(',', $rules['allowed_months']));
        if (!in_array($booking_month, $allowed_months_list)) {
            $month_allowed = false;
        }
    }
    
    // If date is blocked or month not allowed, return immediately with no available sessions
    if ($is_blocked || !$month_allowed) {
        $month_names = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $allowed_month_names = array_map(function($m) use ($month_names) { return $month_names[$m]; }, $allowed_months_list);
        
        $message = '';
        if ($is_blocked) {
            $message = 'This date is blocked due to: ' . $blocked_info['event_name'];
        } elseif (!$month_allowed) {
            $message = 'External users can only book during: ' . implode(', ', $allowed_month_names);
        }
        
        echo json_encode([
            'date' => $date,
            'sessions' => [],
            'booked' => [],
            'operating_hours' => '8:00 AM - 6:00 PM',
            'is_blocked' => $is_blocked,
            'blocked_info' => $blocked_info,
            'month_allowed' => $month_allowed,
            'allowed_months' => $allowed_month_names,
            'message' => $message
        ]);
        exit();
    }

    // Define gym operating hours (8 AM to 12 PM in 2-hour sessions, then 1 PM to 6 PM)
    $operating_hours = [
        ['start' => '08:00:00', 'end' => '18:00:00', 'label' => 'Whole Day Booking (8:00 AM - 6:00 PM)', 'type' => 'whole_day'],
        ['start' => '08:00:00', 'end' => '10:00:00', 'label' => 'Morning Session (8:00 AM - 10:00 AM)', 'type' => 'session'],
        ['start' => '10:00:00', 'end' => '12:00:00', 'label' => 'Late Morning Session (10:00 AM - 12:00 PM)', 'type' => 'session'],
        ['start' => '13:00:00', 'end' => '15:00:00', 'label' => 'Afternoon Session (1:00 PM - 3:00 PM)', 'type' => 'session'],
        ['start' => '15:00:00', 'end' => '17:00:00', 'label' => 'Late Afternoon Session (3:00 PM - 5:00 PM)', 'type' => 'session'],
        ['start' => '17:00:00', 'end' => '18:00:00', 'label' => 'Evening Session (5:00 PM - 6:00 PM)', 'type' => 'session']
    ];


    // Get existing bookings for the date
    // Include rescheduled bookings as they are still active reservations
    // Exclude cancelled and rejected bookings
    $bookings_query = "SELECT start_time, end_time, status FROM bookings 
                       WHERE facility_type = 'oval' 
                       AND date = ? 
                       AND status IN ('pending', 'confirmed', 'rescheduled', 'approved')
                       AND status NOT IN ('cancelled', 'canceled', 'rejected')";
    $bookings_stmt = $conn->prepare($bookings_query);
    $bookings_stmt->bind_param("s", $date);
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

    $month_names = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $allowed_month_names = array_map(function($m) use ($month_names) { return $month_names[$m]; }, $allowed_months_list);
    
    echo json_encode([
        'date' => $date,
        'sessions' => $sessions,
        'booked' => $booked_display,
        'operating_hours' => '8:00 AM - 6:00 PM',
        'is_blocked' => $is_blocked,
        'blocked_info' => $blocked_info,
        'month_allowed' => $month_allowed,
        'allowed_months' => $allowed_month_names
    ]);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => 'Check server logs for more details'
    ]);
}
?>
