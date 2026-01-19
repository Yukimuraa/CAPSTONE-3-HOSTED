<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

require_admin();
header('Content-Type: application/json');

// Optional date range FullCalendar passes
$start = $_GET['start'] ?? null; // ISO date
$end = $_GET['end'] ?? null; // ISO date

$query = "SELECT b.booking_id, b.date as booking_date, b.start_time, b.end_time, b.status, u.name as user_name, b.purpose
          FROM bookings b
          JOIN user_accounts u ON u.id = b.user_id
          WHERE b.facility_type = 'oval'";

$params = [];
$types = '';

if ($start) {
    $query .= " AND b.date >= ?";
    $params[] = $start;
    $types .= 's';
}
if ($end) {
    $query .= " AND b.date < ?"; // FullCalendar 'end' is exclusive
    $params[] = $end;
    $types .= 's';
}

$query .= " ORDER BY b.date, b.start_time";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$statusColors = [
    'pending' => '#fbbf24', // amber-400
    'confirmed' => '#10b981', // emerald-500
    'rejected' => '#ef4444', // red-500
    'cancelled' => '#6b7280'  // gray-500
];

$events = [];
while ($row = $res->fetch_assoc()) {
    $date = $row['booking_date'];
    $startDateTime = $date . 'T' . $row['start_time'];
    $endDateTime = $date . 'T' . $row['end_time'];
    $status = $row['status'];
    $color = $statusColors[$status] ?? '#6b7280';
    $events[] = [
        'id' => $row['booking_id'],
        'title' => $row['purpose'] ?: 'Oval Field Reservation',
        'start' => $startDateTime,
        'end' => $endDateTime,
        'color' => $color,
        'extendedProps' => [
            'status' => $status,
            'user' => $row['user_name']
        ]
    ];
}

// Add blocked dates/school events to calendar
$blocked_query = "SELECT event_name, event_type, start_date, end_date, description, blocked_for_user_types
                  FROM gym_blocked_dates 
                  WHERE is_active = 1";

if ($start && $end) {
    $blocked_query .= " AND (start_date <= ? AND end_date >= ?)";
}

$blocked_stmt = $conn->prepare($blocked_query);
if ($start && $end) {
    $blocked_stmt->bind_param("ss", $end, $start);
}
$blocked_stmt->execute();
$blocked_result = $blocked_stmt->get_result();

// Add blocked events to calendar with distinctive styling
while ($blocked_event = $blocked_result->fetch_assoc()) {
    // Color based on event type
    $color = '#dc2626'; // red-600 for blocked events
    $icon = '🚫';
    
    if ($blocked_event['event_type'] === 'intramurals') {
        $color = '#ea580c'; // orange-600
        $icon = '🏅';
    } elseif ($blocked_event['event_type'] === 'ceremony') {
        $color = '#9333ea'; // purple-600
        $icon = '🎓';
    } elseif ($blocked_event['event_type'] === 'maintenance') {
        $color = '#f59e0b'; // amber-500
        $icon = '🔧';
    }
    
    // Add as background event (shows across entire days)
    $events[] = [
        'id' => 'blocked_' . $blocked_event['start_date'] . '_' . preg_replace('/[^a-z0-9]/i', '', $blocked_event['event_name']),
        'title' => $icon . ' ' . $blocked_event['event_name'] . ' (BLOCKED)',
        'start' => $blocked_event['start_date'],
        'end' => date('Y-m-d', strtotime($blocked_event['end_date'] . ' +1 day')), // FullCalendar end is exclusive
        'color' => $color,
        'display' => 'background', // Shows as background event spanning the dates
        'extendedProps' => [
            'type' => 'blocked',
            'event_type' => $blocked_event['event_type'],
            'blocked_for' => $blocked_event['blocked_for_user_types'],
            'description' => $blocked_event['description']
        ]
    ];
    
    // Also add as regular event for tooltip/details
    $events[] = [
        'id' => 'blocked_detail_' . $blocked_event['start_date'] . '_' . preg_replace('/[^a-z0-9]/i', '', $blocked_event['event_name']),
        'title' => $icon . ' ' . $blocked_event['event_name'],
        'start' => $blocked_event['start_date'],
        'end' => date('Y-m-d', strtotime($blocked_event['end_date'] . ' +1 day')),
        'color' => $color,
        'allDay' => true,
        'extendedProps' => [
            'type' => 'blocked',
            'event_type' => $blocked_event['event_type'],
            'blocked_for' => $blocked_event['blocked_for_user_types'],
            'description' => $blocked_event['description']
        ]
    ];
}

echo json_encode($events);









