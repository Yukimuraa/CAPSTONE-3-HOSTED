<?php
session_start();
// Set cache-busting headers to ensure fresh dates
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Set timezone to ensure correct date/time
// Force timezone to Asia/Manila for consistent results
date_default_timezone_set('Asia/Manila');

// Create DateTime object with current time and timezone
$now_dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
$current_timestamp = $now_dt->getTimestamp();

require_once '../config/database.php';
require_once '../includes/functions.php';

require_admin();

$report_type = isset($_GET['type']) ? sanitize_input($_GET['type']) : '';
$format = isset($_GET['format']) ? sanitize_input($_GET['format']) : 'pdf';

if (!in_array($report_type, ['gym', 'bus', 'inventory', 'billing', 'user', 'summary'])) {
    die('Invalid report type');
}

if (!in_array($format, ['pdf', 'excel'])) {
    die('Invalid format');
}

// Get filter parameters
$start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : '';
$status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$department = isset($_GET['department']) ? sanitize_input($_GET['department']) : '';
$role = isset($_GET['role']) ? sanitize_input($_GET['role']) : '';
$service = isset($_GET['service']) ? sanitize_input($_GET['service']) : '';
$report_type_param = isset($_GET['report_type']) ? sanitize_input($_GET['report_type']) : '';
$mode = isset($_GET['mode']) ? sanitize_input($_GET['mode']) : 'daily';
$date = isset($_GET['date']) ? sanitize_input($_GET['date']) : date('Y-m-d');
$month = isset($_GET['month']) ? sanitize_input($_GET['month']) : date('Y-m');
$year = isset($_GET['year']) ? (int)sanitize_input($_GET['year']) : (int)date('Y');

// Fetch data based on report type
$data = [];
$headers = [];
$title = '';

switch ($report_type) {
    case 'bus':
        $title = 'Bus Reservation Report';
        $headers = ['Reservation ID', 'Requester/Dept', 'Destination', 'Date', 'Vehicles', 'Status'];
        
        $query = "SELECT s.id, s.client, s.destination, s.purpose, s.date_covered, s.vehicle, s.bus_no, s.no_of_days, s.no_of_vehicles, s.status,
                         bs.payment_status, bs.payment_date
                  FROM bus_schedules s
                  LEFT JOIN billing_statements bs ON bs.schedule_id = s.id
                  WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if (!empty($start_date)) { $query .= " AND s.date_covered >= ?"; $params[] = $start_date; $types .= "s"; }
        if (!empty($end_date))   { $query .= " AND s.date_covered <= ?"; $params[] = $end_date;   $types .= "s"; }
        if (!empty($status))     { $query .= " AND s.status = ?";         $params[] = $status;     $types .= "s"; }
        if (!empty($department)) { $query .= " AND s.client LIKE ?";      $params[] = "%$department%"; $types .= "s"; }
        
        $query .= " ORDER BY s.date_covered DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                $row['id'],
                $row['client'],
                $row['destination'],
                date('M d, Y', strtotime($row['date_covered'])),
                $row['no_of_vehicles'],
                ucfirst($row['status'])
            ];
        }
        break;
        
    case 'inventory':
        $title = 'Inventory Report';
        $headers = ['Item', 'Qty Issued', 'Revenue'];
        
        $orders_sql = "SELECT i.id as item_id, i.name as item_name, SUM(o.quantity) as qty_issued, SUM(o.total_price) as revenue
                       FROM orders o JOIN inventory i ON o.inventory_id = i.id
                       WHERE o.status IN ('approved','completed')";
        $params = []; $types = "";
        if (!empty($start_date)) { $orders_sql .= " AND DATE(o.created_at) >= ?"; $params[] = $start_date; $types .= "s"; }
        if (!empty($end_date))   { $orders_sql .= " AND DATE(o.created_at) <= ?"; $params[] = $end_date;   $types .= "s"; }
        $orders_sql .= " GROUP BY i.id, i.name ORDER BY revenue DESC";
        
        $stmt = $conn->prepare($orders_sql);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total_revenue = 0.00;
        while ($row = $result->fetch_assoc()) {
            $revenue = (float)$row['revenue'];
            $total_revenue += $revenue;
            $data[] = [
                $row['item_name'],
                number_format($row['qty_issued']),
                '₱' . number_format($revenue, 2)
            ];
        }
        
        // Get Remaining Stock data
        $stock_data = [];
        try {
            $stock_sql = "SELECT name, stock_quantity AS remaining FROM inventory ORDER BY name ASC";
            $stock_result = $conn->query($stock_sql);
            if ($stock_result) {
                while ($stock_row = $stock_result->fetch_assoc()) {
                    $stock_data[] = [
                        $stock_row['name'],
                        number_format($stock_row['remaining'])
                    ];
                }
            }
        } catch (mysqli_sql_exception $e) {
            // Fallback schema without stock_quantity
            $stock_sql = "SELECT name, quantity AS remaining FROM inventory ORDER BY name ASC";
            $stock_result = $conn->query($stock_sql);
            if ($stock_result) {
                while ($stock_row = $stock_result->fetch_assoc()) {
                    $stock_data[] = [
                        $stock_row['name'],
                        number_format($stock_row['remaining'])
                    ];
                }
            }
        }
        break;
        
    case 'billing':
        $title = 'Billing Summary Report';
        $headers = ['Billing ID', 'Service', 'Customer / Details', 'Amount', 'OR No.', 'Payment Status', 'Created'];
        
        $rows = [];
        
        if ($service === '' || $service === 'items') {
            $sql2 = "SELECT 'Items' as service, 
                        COALESCE(o.batch_id, CONCAT('ORDER-', o.id)) as billing_id,
                        u.name as requester, 
                        GROUP_CONCAT(DISTINCT i.name SEPARATOR ', ') as details,
                        SUM(o.total_price) as amount,
                        CASE WHEN COUNT(CASE WHEN o.status IN ('completed') THEN 1 END) = COUNT(*) THEN 'paid'
                             WHEN COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) > 0 THEN 'cancelled'
                             ELSE 'pending' END as pay_status,
                        MAX(o.updated_at) as paid_at,
                        MIN(o.created_at) as created_at,
                        MAX(o.or_number) as or_number
                    FROM orders o 
                    JOIN user_accounts u ON u.id = o.user_id 
                    JOIN inventory i ON i.id = o.inventory_id 
                    WHERE 1=1";
            $params2 = []; $types2 = "";
            if (!empty($status)) { 
                if ($status === 'paid') { $sql2 .= " AND o.status = 'completed'"; }
                elseif ($status === 'pending') { $sql2 .= " AND o.status IN ('pending','approved')"; }
                elseif ($status === 'cancelled') { $sql2 .= " AND o.status = 'cancelled'"; }
            }
            if (!empty($start_date)) { $sql2 .= " AND DATE(o.created_at) >= ?"; $params2[] = $start_date; $types2 .= "s"; }
            if (!empty($end_date))   { $sql2 .= " AND DATE(o.created_at) <= ?"; $params2[] = $end_date;   $types2 .= "s"; }
            $sql2 .= " GROUP BY o.batch_id, u.id";
            $stmt2 = $conn->prepare($sql2);
            if (!empty($params2)) { $stmt2->bind_param($types2, ...$params2); }
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($r = $res2->fetch_assoc()) { $rows[] = $r; }
        }
        
        foreach ($rows as $r) {
            $or_number = '';
            if ($r['service'] === 'Items' && !empty($r['or_number'])) {
                $or_number = $r['or_number'];
            } else {
                $or_number = '-';
            }
            
            $data[] = [
                $r['billing_id'],
                $r['service'],
                $r['requester'] . ' — ' . $r['details'],
                '₱' . number_format((float)$r['amount'], 2),
                $or_number,
                ucfirst($r['pay_status']),
                date('M d, Y', strtotime($r['created_at']))
            ];
        }
        break;
        
    case 'user':
        $title = 'User Accounts Report';
        $headers = ['User ID', 'Name', 'Email', 'Role', 'Status', 'Date Registered'];
        
        $sql = "SELECT id, name, email, user_type, status, created_at FROM user_accounts WHERE 1=1";
        $params = []; $types = "";
        if (!empty($role)) { $sql .= " AND user_type = ?"; $params[] = $role; $types .= "s"; }
        if (!empty($status)) { $sql .= " AND status = ?"; $params[] = $status; $types .= "s"; }
        if (!empty($start_date)) { $sql .= " AND DATE(created_at) >= ?"; $params[] = $start_date; $types .= "s"; }
        if (!empty($end_date))   { $sql .= " AND DATE(created_at) <= ?"; $params[] = $end_date;   $types .= "s"; }
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                $row['id'],
                $row['name'],
                $row['email'],
                ucfirst($row['user_type']),
                ucfirst($row['status']),
                date('M d, Y', strtotime($row['created_at']))
            ];
        }
        break;
        
    case 'summary':
        $title = 'Transaction Summary Report';
        $headers = ['Service', 'Requests', 'Approved', 'Collected'];
        
        if ($mode === 'daily') {
            $start = $date; $end = $date;
        } elseif ($mode === 'monthly') {
            $start = $month.'-01'; $end = date('Y-m-t', strtotime($start));
        } else {
            $start = sprintf('%04d-01-01', $year);
            $end = sprintf('%04d-12-31', $year);
        }
        
        $count_bookings = (int)$conn->query("SELECT COUNT(*) as c FROM bookings WHERE facility_type='gym' AND DATE(created_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
        $count_bus = (int)$conn->query("SELECT COUNT(*) as c FROM bus_schedules WHERE DATE(date_covered) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
        $count_orders = (int)$conn->query("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
        $approved_bookings = (int)$conn->query("SELECT COUNT(*) as c FROM bookings WHERE facility_type='gym' AND (status='confirmed' OR status='approved') AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
        $approved_bus = (int)$conn->query("SELECT COUNT(*) as c FROM bus_schedules WHERE status='approved' AND DATE(date_covered) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
        $completed_orders = (int)$conn->query("SELECT COUNT(*) as c FROM orders WHERE status='completed' AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
        $col_orders = (float)($conn->query("SELECT SUM(total_price) as s FROM orders WHERE status='completed' AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['s'] ?? 0);
        $col_bus = (float)($conn->query("SELECT SUM(total_amount) as s FROM billing_statements WHERE payment_status='paid' AND DATE(payment_date) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['s'] ?? 0);
        
        // Gym collections: sum total from cost_breakdown in additional_info for confirmed bookings with or_number
        $gym_collections_query = "SELECT additional_info FROM bookings WHERE facility_type='gym' AND (status='confirmed' OR status='approved') AND or_number IS NOT NULL AND or_number != '' AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'";
        $gym_collections_result = $conn->query($gym_collections_query);
        $col_gym = 0.0;
        if ($gym_collections_result) {
            while ($row = $gym_collections_result->fetch_assoc()) {
                if (!empty($row['additional_info'])) {
                    $additional_info = json_decode($row['additional_info'], true);
                    if (isset($additional_info['cost_breakdown']['total'])) {
                        $col_gym += (float)$additional_info['cost_breakdown']['total'];
                    }
                }
            }
        }
        
        $data[] = ['Gym', number_format($count_bookings), number_format($approved_bookings), '₱' . number_format($col_gym, 2)];
        $data[] = ['Item Sales', number_format($count_orders), number_format($completed_orders), '₱' . number_format($col_orders, 2)];
        
        // Calculate totals
        $total_requests = $count_bookings + $count_bus + $count_orders;
        $total_approved = $approved_bookings + $approved_bus + $completed_orders;
        $total_collected = $col_gym + $col_orders;
        break;
        
    case 'gym':
        $report_type_param = isset($_GET['report_type']) ? sanitize_input($_GET['report_type']) : '';
        $user_type_filter = isset($_GET['user_type']) ? sanitize_input($_GET['user_type']) : '';
        // Get status filter at the top level so it's accessible in PDF/Excel sections
        $status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
        
        // Handle different gym report types from gym_reports.php
        if ($report_type_param === 'usage') {
            $start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
            $end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');
            
            $title = 'Gym Usage Report';
            $headers = ['Date', 'Facility', 'Total Bookings', 'Number of Attendees'];
            
            // Only add Internal header if not filtering external only
            if ($user_type_filter !== 'external') {
                $headers[] = 'Internal';
            }
            
            // Only add External header if not filtering internal only
            if ($user_type_filter !== 'internal') {
                $headers[] = 'External';
            }
            
            // Only add Collected header if showing external or all users
            if ($user_type_filter !== 'internal') {
                $headers[] = 'Collected';
            }
            
            $query = "SELECT b.date as booking_date, 'Gymnasium' as facility_name, 
                             COUNT(*) as booking_count,
                             COUNT(CASE WHEN u.user_type IN ('student', 'faculty', 'staff') THEN 1 END) as internal_count,
                             COUNT(CASE WHEN u.user_type = 'external' THEN 1 END) as external_count,
                             SUM(COALESCE(b.attendees, 0)) as total_attendees
                      FROM bookings b 
                      LEFT JOIN user_accounts u ON b.user_id = u.id
                      WHERE b.facility_type = 'gym' AND b.date BETWEEN ? AND ?";
            
            $params = [$start_date, $end_date];
            $types = "ss";
            
            // Apply status filter - if no filter, exclude cancelled by default
            if (!empty($status_filter)) {
                if ($status_filter === 'approved') {
                    $query .= " AND (b.status = 'approved' OR b.status = 'confirmed')";
                } elseif ($status_filter === 'pending') {
                    $query .= " AND b.status = 'pending'";
                } elseif ($status_filter === 'rejected') {
                    $query .= " AND b.status = 'rejected'";
                } elseif ($status_filter === 'cancelled') {
                    $query .= " AND (b.status = 'cancelled' OR b.status = 'canceled')";
                } else {
                    $query .= " AND b.status = ?";
                    $params[] = $status_filter;
                    $types .= "s";
                }
            } else {
                // If no status filter, exclude cancelled by default
                $query .= " AND b.status NOT IN ('cancelled', 'canceled')";
            }
            
            if ($user_type_filter === 'internal') {
                $query .= " AND u.user_type IN ('student', 'faculty', 'staff')";
            } elseif ($user_type_filter === 'external') {
                $query .= " AND u.user_type = 'external'";
            }
            
            $query .= " GROUP BY b.date ORDER BY COUNT(CASE WHEN u.user_type = 'external' THEN 1 END) DESC, b.date DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // Get collected amounts (external only) - skip if internal filter
            if ($user_type_filter !== 'internal') {
                $collected_query = "SELECT b.date, b.additional_info
                                    FROM bookings b 
                                    LEFT JOIN user_accounts u ON b.user_id = u.id
                                    WHERE b.facility_type = 'gym' 
                                    AND b.date BETWEEN ? AND ?
                                    AND u.user_type = 'external'
                                    AND (b.status = 'confirmed' OR b.status = 'approved')
                                    AND b.or_number IS NOT NULL 
                                    AND b.or_number != ''
                                    AND b.additional_info IS NOT NULL";
                
                $collected_params = [$start_date, $end_date];
                $collected_types = "ss";
                
                // Apply status filter to collected query if set
                if (!empty($status_filter) && ($status_filter === 'approved' || $status_filter === 'confirmed')) {
                    // Already filtered by approved/confirmed status
                } elseif (!empty($status_filter)) {
                    // If status filter is not approved, no collected amounts
                    $collected_query = "SELECT b.date, b.additional_info FROM bookings b WHERE 1=0";
                }
                
                $collected_stmt = $conn->prepare($collected_query);
                if (strpos($collected_query, '1=0') === false) {
                    $collected_stmt->bind_param($collected_types, ...$collected_params);
                }
                $collected_stmt->execute();
                $collected_result = $collected_stmt->get_result();
            } else {
                $collected_result = null;
            }
            
            $collected_by_date = [];
            if ($collected_result) {
                while ($collected_row = $collected_result->fetch_assoc()) {
                    if (!empty($collected_row['additional_info'])) {
                        $additional_info = json_decode($collected_row['additional_info'], true);
                        if (isset($additional_info['cost_breakdown']['total'])) {
                            $date = $collected_row['date'];
                            $collected_by_date[$date] = ($collected_by_date[$date] ?? 0) + (float)$additional_info['cost_breakdown']['total'];
                        }
                    }
                }
            }
            
            $total_collected = 0;
            $total_bookings = 0;
            $total_internal = 0;
            $total_external = 0;
            $total_attendees = 0;
            
            while ($row = $result->fetch_assoc()) {
                $collected = $collected_by_date[$row['booking_date']] ?? 0;
                $total_collected += $collected;
                $total_bookings += $row['booking_count'];
                $total_internal += ($row['internal_count'] ?? 0);
                $total_external += ($row['external_count'] ?? 0);
                $total_attendees += (int)($row['total_attendees'] ?? 0);
                
                $row_data = [
                    date('F j, Y', strtotime($row['booking_date'])),
                    $row['facility_name'],
                    number_format($row['booking_count']),
                    number_format($row['total_attendees'] ?? 0)
                ];
                
                // Only add Internal column if not filtering external only
                if ($user_type_filter !== 'external') {
                    $row_data[] = number_format($row['internal_count'] ?? 0);
                }
                
                // Only add External column if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $row_data[] = number_format($row['external_count'] ?? 0);
                }
                
                // Only add Collected column if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $row_data[] = '₱' . number_format($collected, 2);
                }
                
                $data[] = $row_data;
            }
            
            // Add total row
            if (!empty($data)) {
                $total_row = ['Total', '', number_format($total_bookings), number_format($total_attendees)];
                
                // Only add Internal total if not filtering external only
                if ($user_type_filter !== 'external') {
                    $total_row[] = number_format($total_internal);
                }
                
                // Only add External total if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $total_row[] = number_format($total_external);
                }
                
                // Only add Collected total if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $total_row[] = '₱' . number_format($total_collected, 2);
                }
                
                $data[] = $total_row;
            }
            
        } elseif ($report_type_param === 'status') {
            $start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-d', strtotime('-1 month'));
            $end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');
            // Use the status_filter variable defined at top level
            $status = $status_filter;
            
            $title = 'Booking Status Report';
            $headers = ['Status', 'Facility', 'Total Bookings', 'Number of Attendees'];
            
            // Only add Internal header if not filtering external only
            if ($user_type_filter !== 'external') {
                $headers[] = 'Internal';
            }
            
            // Only add External header if not filtering internal only
            if ($user_type_filter !== 'internal') {
                $headers[] = 'External';
            }
            
            // Only add Collected header if not filtering internal only
            if ($user_type_filter !== 'internal') {
                $headers[] = 'Collected';
            }
            
            $query = "SELECT b.status, 'Gymnasium' as facility_name, COUNT(*) as booking_count,
                             COUNT(CASE WHEN u.user_type IN ('student', 'faculty', 'staff') THEN 1 END) as internal_count,
                             COUNT(CASE WHEN u.user_type = 'external' THEN 1 END) as external_count,
                             SUM(COALESCE(b.attendees, 0)) as total_attendees
                      FROM bookings b 
                      LEFT JOIN user_accounts u ON b.user_id = u.id
                      WHERE b.facility_type = 'gym' AND b.date BETWEEN ? AND ?";
            
            $params = [$start_date, $end_date];
            $types = "ss";
            
            // Apply status filter - if empty, show all statuses (pending, approved, rejected, cancelled)
            if (!empty($status)) {
                if ($status === 'approved') {
                    $query .= " AND (b.status = 'approved' OR b.status = 'confirmed')";
                } elseif ($status === 'pending') {
                    $query .= " AND b.status = 'pending'";
                } elseif ($status === 'rejected') {
                    $query .= " AND b.status = 'rejected'";
                } elseif ($status === 'cancelled') {
                    $query .= " AND (b.status = 'cancelled' OR b.status = 'canceled')";
                } else {
                    $query .= " AND b.status = ?";
                    $params[] = $status;
                    $types .= "s";
                }
            } else {
                // Show all statuses: pending, approved, confirmed, rejected, cancelled, canceled
                $query .= " AND b.status IN ('pending', 'approved', 'confirmed', 'rejected', 'cancelled', 'canceled', 'rescheduled')";
            }
            
            if ($user_type_filter === 'internal') {
                $query .= " AND u.user_type IN ('student', 'faculty', 'staff')";
            } elseif ($user_type_filter === 'external') {
                $query .= " AND u.user_type = 'external'";
            }
            
            $query .= " GROUP BY b.status ORDER BY COUNT(CASE WHEN u.user_type = 'external' THEN 1 END) DESC, b.status";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // Get collected by status (external only) - skip if internal filter
            if ($user_type_filter !== 'internal') {
                $collected_query = "SELECT b.status, b.additional_info
                                    FROM bookings b 
                                    LEFT JOIN user_accounts u ON b.user_id = u.id
                                    WHERE b.facility_type = 'gym' 
                                    AND b.date BETWEEN ? AND ?
                                    AND u.user_type = 'external'
                                    AND (b.status = 'confirmed' OR b.status = 'approved')
                                    AND b.or_number IS NOT NULL 
                                    AND b.or_number != ''
                                    AND b.additional_info IS NOT NULL";
                
                $collected_params = [$start_date, $end_date];
                $collected_types = "ss";
                
                if (!empty($status)) {
                    if ($status === 'approved') {
                        $collected_query .= " AND (b.status = 'approved' OR b.status = 'confirmed')";
                    } elseif ($status === 'pending' || $status === 'rejected' || $status === 'cancelled') {
                        // No collected for pending, rejected, or cancelled
                        $collected_query = "SELECT b.status, b.additional_info FROM bookings b WHERE 1=0";
                    } else {
                        $collected_query .= " AND b.status = ?";
                        $collected_params[] = $status;
                        $collected_types .= "s";
                    }
                }
                
                $collected_stmt = $conn->prepare($collected_query);
                if (strpos($collected_query, '1=0') === false) {
                    $collected_stmt->bind_param($collected_types, ...$collected_params);
                }
                $collected_stmt->execute();
                $collected_result = $collected_stmt->get_result();
            } else {
                $collected_result = null;
            }
            
            $collected_by_status = [];
            $total_collected = 0;
            if ($collected_result) {
                while ($collected_row = $collected_result->fetch_assoc()) {
                    if (!empty($collected_row['additional_info'])) {
                        $additional_info = json_decode($collected_row['additional_info'], true);
                        if (isset($additional_info['cost_breakdown']['total'])) {
                            $status_key = $collected_row['status'];
                            $amount = (float)$additional_info['cost_breakdown']['total'];
                            $collected_by_status[$status_key] = ($collected_by_status[$status_key] ?? 0) + $amount;
                            $total_collected += $amount;
                        }
                    }
                }
            }
            
            $total_bookings = 0;
            $total_internal = 0;
            $total_external = 0;
            $total_attendees = 0;
            
            while ($row = $result->fetch_assoc()) {
                $status_display = ucfirst($row['status']);
                if ($row['status'] === 'confirmed') {
                    $status_display = 'Approved';
                }
                
                $collected = $collected_by_status[$row['status']] ?? 0;
                
                $total_bookings += $row['booking_count'];
                $total_internal += ($row['internal_count'] ?? 0);
                $total_external += ($row['external_count'] ?? 0);
                $total_attendees += (int)($row['total_attendees'] ?? 0);
                
                $row_data = [
                    $status_display,
                    $row['facility_name'],
                    number_format($row['booking_count']),
                    number_format($row['total_attendees'] ?? 0)
                ];
                
                // Only add Internal column if not filtering external only
                if ($user_type_filter !== 'external') {
                    $row_data[] = number_format($row['internal_count'] ?? 0);
                }
                
                // Only add External column if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $row_data[] = number_format($row['external_count'] ?? 0);
                }
                
                // Only add Collected column if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $row_data[] = '₱' . number_format($collected, 2);
                }
                
                $data[] = $row_data;
            }
            
            // Add total row
            if (!empty($data)) {
                $total_row = ['Total', '', number_format($total_bookings), number_format($total_attendees)];
                
                // Only add Internal total if not filtering external only
                if ($user_type_filter !== 'external') {
                    $total_row[] = number_format($total_internal);
                }
                
                // Only add External total if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $total_row[] = number_format($total_external);
                }
                
                // Only add Collected total if not filtering internal only
                if ($user_type_filter !== 'internal') {
                    $total_row[] = '₱' . number_format($total_collected, 2);
                }
                
                $data[] = $total_row;
            }
            
        } else {
            // Default: detailed booking information
        $title = 'Gym Bookings Detailed Report';
        $headers = ['Booking ID', 'Status', 'Facility', 'Date', 'Time', 'Purpose', 'Attendees', 'Requested On', 'Requester Name', 'Department/Organization', 'Equipment/Services'];
        
        // Get date filters
        $start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');
        // Use the status_filter variable defined at top level
        
        // Build query to get detailed booking information
        $query = "SELECT b.booking_id, b.status, b.date, b.start_time, b.end_time, b.purpose, b.attendees, 
                         b.created_at, b.or_number, b.additional_info,
                             u.name as user_name, u.email as user_email, u.organization, u.user_type
                  FROM bookings b
                  LEFT JOIN user_accounts u ON b.user_id = u.id
                  WHERE b.facility_type = 'gym' AND b.date BETWEEN ? AND ?";
        
        $params = [$start_date, $end_date];
        $types = "ss";
        
        // Apply status filter
        if (!empty($status_filter)) {
            if ($status_filter === 'approved') {
                $query .= " AND (b.status = 'approved' OR b.status = 'confirmed')";
            } else {
                $query .= " AND b.status = ?";
                $params[] = $status_filter;
                $types .= "s";
            }
        }
        
            $query .= " ORDER BY CASE WHEN u.user_type = 'external' THEN 0 ELSE 1 END, b.date DESC, b.created_at DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Parse additional_info JSON
            $additional_info = [];
            if (!empty($row['additional_info'])) {
                $additional_info = json_decode($row['additional_info'], true);
            }
            
            // Get facility name
            $facility_name = "Gymnasium"; // Default
            if (isset($additional_info['facility_id']) && !empty($additional_info['facility_id'])) {
                $facility_id = intval($additional_info['facility_id']);
                $facility_stmt = $conn->prepare("SELECT name FROM gym_facilities WHERE id = ?");
                $facility_stmt->bind_param("i", $facility_id);
                $facility_stmt->execute();
                $facility_result = $facility_stmt->get_result();
                if ($facility_result->num_rows > 0) {
                    $facility_row = $facility_result->fetch_assoc();
                    $facility_name = $facility_row['name'];
                }
            }
            
            // Format booking ID (already in GYM-YYYY-XXX format)
            $booking_id = $row['booking_id'];
            
            // Format status
            $status_display = ucfirst($row['status']);
            if ($row['status'] === 'confirmed') {
                $status_display = 'Approved';
            }
            
            // Format date
            $date_formatted = date('F j, Y', strtotime($row['date']));
            
            // Format time
            $time_formatted = date('g:i A', strtotime($row['start_time'])) . ' - ' . date('g:i A', strtotime($row['end_time']));
            
            // Format requested on
            $requested_on = date('F j, Y g:i A', strtotime($row['created_at']));
            
            // Get equipment/services
            $equipment = isset($additional_info['equipment']) ? $additional_info['equipment'] : 'None selected';
            
            // Get organization/department
            $organization = !empty($row['organization']) ? $row['organization'] : 'N/A';
            
            $data[] = [
                $booking_id,
                $status_display,
                $facility_name,
                $date_formatted,
                $time_formatted,
                $row['purpose'],
                $row['attendees'] ?? 'N/A',
                $requested_on,
                $row['user_name'] ?? 'N/A',
                $organization,
                $equipment
            ];
            }
        }
        break;
}

// Generate file based on format
if ($format === 'pdf') {
    // Check if TCPDF is available
    $tcpdf_available = false;
    if (file_exists('../vendor/autoload.php')) {
        require_once '../vendor/autoload.php';
        if (class_exists('TCPDF')) {
            $tcpdf_available = true;
        }
    }
    
    if ($tcpdf_available) {
        // Use TCPDF for PDF generation
        // Clear output buffer
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('CHMSU BAO System');
        $pdf->SetAuthor('CHMSU BAO');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->AddPage();
        
        // Header - use DateTime for accurate timezone-aware dates
        $now_dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $html = '<h1 style="text-align:center; font-size:18px; margin-bottom:10px;">' . htmlspecialchars($title) . '</h1>';
        $html .= '<p style="text-align:center; font-size:10px; color:#666; margin-bottom:15px;">Generated on: ' . $now_dt->format('F j, Y, g:i a') . '</p>';
        
        // Table
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse:collapse;">';
        $html .= '<thead><tr style="background-color:#f3f4f6; font-weight:bold;">';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        
        // Add total row for inventory reports
        if ($report_type === 'inventory' && isset($total_revenue)) {
            $html .= '<tr style="background-color:#f3f4f6; font-weight:bold;">';
            $html .= '<td colspan="' . (count($headers) - 1) . '" style="text-align:right;">Total Revenue:</td>';
            $html .= '<td style="text-align:right;">₱' . number_format($total_revenue, 2) . '</td>';
            $html .= '</tr>';
        }
        
        // Add total row for billing reports
        if ($report_type === 'billing' && !empty($rows)) {
            $billing_total = 0.0;
            foreach ($rows as $r) {
                $billing_total += (float)$r['amount'];
            }
            $html .= '<tr style="background-color:#f3f4f6; font-weight:bold;">';
            $html .= '<td colspan="3" style="text-align:right;">Total:</td>';
            $html .= '<td style="text-align:right;">₱' . number_format($billing_total, 2) . '</td>';
            $html .= '<td colspan="3"></td>';
            $html .= '</tr>';
        }
        
        // Add total row for summary reports
        if ($report_type === 'summary' && isset($total_requests) && isset($total_approved) && isset($total_collected)) {
            $html .= '<tr style="background-color:#f3f4f6; font-weight:bold;">';
            $html .= '<td style="text-align:right;">Total:</td>';
            $html .= '<td style="text-align:right;">' . number_format($total_requests) . '</td>';
            $html .= '<td style="text-align:right;">' . number_format($total_approved) . '</td>';
            $html .= '<td style="text-align:right;">₱' . number_format($total_collected, 2) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        
        // Add Remaining Stock section for inventory reports
        if ($report_type === 'inventory' && isset($stock_data) && !empty($stock_data)) {
            $html .= '<br><br><h2 style="font-size:14px; margin-bottom:10px;">Remaining Stock</h2>';
            $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse:collapse;">';
            $html .= '<thead><tr style="background-color:#f3f4f6; font-weight:bold;">';
            $html .= '<th>Item</th><th>Remaining</th>';
            $html .= '</tr></thead><tbody>';
            
            foreach ($stock_data as $stock_row) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($stock_row[0]) . '</td>';
                $html .= '<td style="text-align:right;">' . htmlspecialchars($stock_row[1]) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
        }
        
        $pdf->writeHTML($html, true, false, true, false, '');
        
        $filename = strtolower(str_replace(' ', '_', $title)) . '_' . (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Ymd_His') . '.pdf';
        $pdf->Output($filename, 'D');
    } else {
        // Fallback: Generate print-friendly HTML that can be saved as PDF
        // Clear output buffer
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/html; charset=utf-8');
        
        $filename = strtolower(str_replace(' ', '_', $title)) . '_' . (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Ymd_His');
        
        // Get base URL for logo
        $logo_path = '../image/CHMSUWebLOGO.png';
        $logo_base64 = '';
        if (file_exists($logo_path)) {
            $logo_data = file_get_contents($logo_path);
            $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
        }
        
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        @media print {
            body { margin: 0; padding: 20px; }
            .no-print { display: none !important; }
            @page { margin: 1cm; }
        }
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        .report-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            padding: 20px;
            margin: -20px -20px 30px -20px;
            text-align: center;
            border-top: 4px solid #fbbf24;
        }
        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 15px;
        }
        .logo-container img {
            height: 80px;
            width: auto;
        }
        .report-header h1 {
            margin: 10px 0 5px 0;
            font-size: 24px;
            color: white;
            font-weight: bold;
        }
        .report-header p {
            margin: 5px 0;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.95);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            background: white;
            padding: 20px;
        }
        .header h2 {
            margin: 0;
            font-size: 20px;
            color: #1a1a1a;
            font-weight: bold;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 12px;
            color: #666;
        }
        .info {
            margin-bottom: 20px;
            font-size: 12px;
            color: #555;
            padding: 10px;
            background: #f9fafb;
            border-left: 4px solid #1e3a8a;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 11px;
        }
        th {
            background-color: #f3f4f6;
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #333;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        .back-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #1e3a8a;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        .back-button:hover {
            background-color: #1e40af;
        }
        @media print {
            .back-button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <button class="back-button no-print" onclick="window.history.back()">
        <i class="fas fa-arrow-left"></i> Back
    </button>
    <div class="report-header">
        <div class="logo-container">';
        if ($logo_base64) {
            echo '<img src="' . $logo_base64 . '" alt="CHMSU Logo">';
        }
        echo '</div>
        <h1>BUSINESS AFFAIRS OFFICE REPORTS</h1>
        <p>CITY OF TALISAY, Province of Negros Occidental</p>
        <p>CHMSU - Carlos Hilado Memorial State University</p>
    </div>
    
    <div class="header">
        <h2>' . htmlspecialchars($title) . '</h2>
        <p>Generated on: ' . (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('F j, Y, g:i a') . '</p>
    </div>';
        
        // Add filter info if available
        // For gym reports, check status_filter from GET or from variable scope
        // For other reports, use status variable
        $display_status = '';
        $display_user_type = '';
        
        // Check for status filter - try multiple ways to get it
        if ($report_type === 'gym') {
            // First try the variable if it exists
            if (isset($status_filter) && !empty($status_filter)) {
                $display_status = $status_filter;
            } elseif (isset($_GET['status']) && !empty($_GET['status'])) {
                // Fallback to GET parameter
                $display_status = sanitize_input($_GET['status']);
            }
            
            // Get user type filter
            if (isset($user_type_filter) && !empty($user_type_filter)) {
                $display_user_type = $user_type_filter;
            } elseif (isset($_GET['user_type']) && !empty($_GET['user_type'])) {
                $display_user_type = sanitize_input($_GET['user_type']);
            }
        } elseif (isset($status) && !empty($status)) {
            $display_status = $status;
        }
        
        if (!empty($start_date) || !empty($end_date) || !empty($display_status) || !empty($display_user_type)) {
            echo '<div class="info">';
            if (!empty($start_date)) echo '<strong>Start Date:</strong> ' . date('F j, Y', strtotime($start_date)) . ' | ';
            if (!empty($end_date)) echo '<strong>End Date:</strong> ' . date('F j, Y', strtotime($end_date)) . ' | ';
            if (!empty($display_status)) echo '<strong>Status:</strong> ' . ucfirst($display_status) . ' | ';
            if (!empty($display_user_type)) {
                $user_type_label = ($display_user_type === 'internal') ? 'Internal Users' : (($display_user_type === 'external') ? 'External Users' : 'All Users');
                echo '<strong>User Type:</strong> ' . $user_type_label;
            }
            echo '</div>';
        }
        
        echo '<table>
        <thead>
            <tr>';
        foreach ($headers as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>
        </thead>
        <tbody>';
        
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell) . '</td>';
            }
            echo '</tr>';
        }
        
        // Add total row for billing reports
        if ($report_type === 'billing' && !empty($rows)) {
            $billing_total = 0.0;
            foreach ($rows as $r) {
                $billing_total += (float)$r['amount'];
            }
            echo '<tr style="background-color:#f3f4f6; font-weight:bold;">';
            echo '<td colspan="3" style="text-align:right;">Total:</td>';
            echo '<td style="text-align:right;">₱' . number_format($billing_total, 2) . '</td>';
            echo '<td colspan="3"></td>';
            echo '</tr>';
        }
        
        // Add total row for inventory reports
        if ($report_type === 'inventory' && isset($total_revenue)) {
            echo '<tr style="background-color:#f3f4f6; font-weight:bold;">';
            echo '<td colspan="' . (count($headers) - 1) . '" style="text-align:right;">Total Revenue:</td>';
            echo '<td style="text-align:right;">₱' . number_format($total_revenue, 2) . '</td>';
            echo '</tr>';
        }
        
        // Add total row for summary reports
        if ($report_type === 'summary' && isset($total_requests) && isset($total_approved) && isset($total_collected)) {
            echo '<tr style="background-color:#f3f4f6; font-weight:bold;">';
            echo '<td style="text-align:right;">Total:</td>';
            echo '<td style="text-align:right;">' . number_format($total_requests) . '</td>';
            echo '<td style="text-align:right;">' . number_format($total_approved) . '</td>';
            echo '<td style="text-align:right;">₱' . number_format($total_collected, 2) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>
    </table>';
        
        // Add Remaining Stock section for inventory reports
        if ($report_type === 'inventory' && isset($stock_data) && !empty($stock_data)) {
            echo '<br><br><h2 style="font-size:16px; margin-bottom:15px; border-bottom:2px solid #333; padding-bottom:5px;">Remaining Stock</h2>';
            echo '<table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Remaining</th>
                </tr>
            </thead>
            <tbody>';
            
            foreach ($stock_data as $stock_row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($stock_row[0]) . '</td>';
                echo '<td style="text-align:right;">' . htmlspecialchars($stock_row[1]) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>
        </table>';
        }
        
        echo '
    
    <div class="footer">
        <p><strong>City of Talisay Business Affairs Office</strong></p>
        <p>This report was generated automatically on ' . (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('F j, Y') . ' at ' . (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('g:i A') . '</p>
        <p>For inquiries, please contact the Business Affairs Office</p>
    </div>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        // Auto-trigger print dialog after page loads
        window.onload = function() {
            // Small delay to ensure page is fully rendered
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>';
        exit;
    }
    
} else { // Excel format
    // Clear output buffer
    if (ob_get_length()) {
        ob_end_clean();
    }
    
    // Check if PhpSpreadsheet is available
    $phpspreadsheet_available = false;
    if (file_exists('../vendor/autoload.php')) {
        require_once '../vendor/autoload.php';
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $phpspreadsheet_available = true;
        }
    }
    
    if ($phpspreadsheet_available) {
        // Use PhpSpreadsheet for proper Excel formatting with borders
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $currentRow = 1;
        
        // Professional Report Header
        $sheet->setCellValue('A' . $currentRow, 'BUSINESS AFFAIRS OFFICE REPORTS');
        $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
        $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $currentRow++;
        
        $sheet->setCellValue('A' . $currentRow, 'CITY OF TALISAY, Province of Negros Occidental');
        $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
        $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $currentRow++;
        
        $sheet->setCellValue('A' . $currentRow, 'CHMSU - Carlos Hilado Memorial State University');
        $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
        $sheet->getStyle('A' . $currentRow)->getFont()->setSize(12);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $currentRow += 2;
        
        // Generation date and time (generate fresh each time using DateTime for accuracy)
        $now_dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $generated_date = $now_dt->format('F j, Y');
        $generated_time = $now_dt->format('g:i A');
        $sheet->setCellValue('A' . $currentRow, 'Generated on:');
        $sheet->setCellValue('B' . $currentRow, $generated_date . ' at ' . $generated_time);
        $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true);
        $currentRow += 2;
        
        // Report title
        $sheet->setCellValue('A' . $currentRow, $title);
        $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
        $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $currentRow += 2;
        
        // Covered period/filters
        $period_info = [];
        
        // Handle mode-based filters (for summary reports)
        if (isset($mode)) {
            if ($mode === 'daily') {
                $period_info[] = 'Date: ' . date('F j, Y', strtotime($date));
            } elseif ($mode === 'monthly') {
                $period_info[] = 'Month: ' . date('F Y', strtotime($month . '-01'));
            } elseif ($mode === 'yearly') {
                $period_info[] = 'Year: ' . $year;
            }
        }
        
        // Handle date range filters
        if (!empty($start_date) && !empty($end_date)) {
            if ($start_date === $end_date) {
                $period_info[] = 'Date: ' . date('F j, Y', strtotime($start_date));
            } else {
                $period_info[] = 'Period: ' . date('F j, Y', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date));
            }
        } elseif (!empty($start_date)) {
            $period_info[] = 'From: ' . date('F j, Y', strtotime($start_date));
        } elseif (!empty($end_date)) {
            $period_info[] = 'To: ' . date('F j, Y', strtotime($end_date));
        }
        
        // Handle other filters
        // For gym reports, use status_filter (defined at top of gym case)
        // For other reports, use status variable
        $display_status = '';
        if ($report_type === 'gym' && isset($status_filter)) {
            $display_status = $status_filter;
        } elseif (isset($status)) {
            $display_status = $status;
        }
        if (!empty($display_status)) {
            $period_info[] = 'Status: ' . ucfirst($display_status);
        }
        if (!empty($user_type_filter) && $report_type === 'gym') {
            $user_type_label = ($user_type_filter === 'internal') ? 'Internal Users' : (($user_type_filter === 'external') ? 'External Users' : 'All Users');
            $period_info[] = 'User Type: ' . $user_type_label;
        }
        if (!empty($role)) {
            $period_info[] = 'Role: ' . ucfirst($role);
        }
        if (!empty($department)) {
            $period_info[] = 'Department: ' . htmlspecialchars($department);
        }
        if (!empty($service)) {
            $period_info[] = 'Service: ' . ucfirst($service);
        }
        if (!empty($report_type_param)) {
            $period_info[] = 'Report Type: ' . ucfirst($report_type_param);
        }
        
        if (!empty($period_info)) {
            $col = 'A';
            foreach ($period_info as $info) {
                $sheet->setCellValue($col . $currentRow, $info);
                $col++;
            }
            $currentRow += 2;
        }
        
        // Table headers with borders and styling
        $headerRow = $currentRow;
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $currentRow, $header);
            $sheet->getStyle($col . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle($col . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle($col . $currentRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle($col . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $col++;
        }
        $currentRow++;
        
        // Write data with borders
        foreach ($data as $row) {
            $col = 'A';
            foreach ($row as $cell) {
                $sheet->setCellValue($col . $currentRow, $cell);
                $sheet->getStyle($col . $currentRow)->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                $col++;
            }
            $currentRow++;
        }
        
        // Add total row for inventory reports
        if ($report_type === 'inventory' && isset($total_revenue)) {
            $col = 'A';
            $lastCol = chr(64 + count($headers) - 1);
            $revenueCol = chr(64 + count($headers));
            
            // Merge cells for "Total Revenue:" label
            $sheet->setCellValue($col . $currentRow, 'Total Revenue:');
            $sheet->mergeCells($col . $currentRow . ':' . $lastCol . $currentRow);
            $sheet->getStyle($col . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle($col . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle($col . $currentRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($col . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            
            // Total revenue value
            $sheet->setCellValue($revenueCol . $currentRow, '₱' . number_format($total_revenue, 2));
            $sheet->getStyle($revenueCol . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle($revenueCol . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle($revenueCol . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            
            $currentRow++;
        }
        
        // Add Remaining Stock section for inventory reports
        if ($report_type === 'inventory' && isset($stock_data) && !empty($stock_data)) {
            $currentRow += 2; // Add spacing
            
            // Section header
            $sheet->setCellValue('A' . $currentRow, 'Remaining Stock');
            $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
            $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
            $currentRow++;
            
            // Stock table headers
            $sheet->setCellValue('A' . $currentRow, 'Item');
            $sheet->setCellValue('B' . $currentRow, 'Remaining');
            $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $currentRow++;
            
            // Stock data rows
            foreach ($stock_data as $stock_row) {
                $sheet->setCellValue('A' . $currentRow, $stock_row[0]);
                $sheet->setCellValue('B' . $currentRow, $stock_row[1]);
                $sheet->getStyle('A' . $currentRow . ':B' . $currentRow)->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                $sheet->getStyle('B' . $currentRow)->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                $currentRow++;
            }
        }
        
        // Add total row for summary reports
        if ($report_type === 'summary' && isset($total_requests) && isset($total_approved) && isset($total_collected)) {
            $serviceCol = 'A';
            $requestsCol = 'B';
            $approvedCol = 'C';
            $collectedCol = 'D';
            
            // Total label
            $sheet->setCellValue($serviceCol . $currentRow, 'Total:');
            $sheet->getStyle($serviceCol . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle($serviceCol . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle($serviceCol . $currentRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($serviceCol . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            
            // Total requests
            $sheet->setCellValue($requestsCol . $currentRow, number_format($total_requests));
            $sheet->getStyle($requestsCol . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle($requestsCol . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle($requestsCol . $currentRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($requestsCol . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            
            // Total approved
            $sheet->setCellValue($approvedCol . $currentRow, number_format($total_approved));
            $sheet->getStyle($approvedCol . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle($approvedCol . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle($approvedCol . $currentRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($approvedCol . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            
            // Total collected
            $sheet->setCellValue($collectedCol . $currentRow, '₱' . number_format($total_collected, 2));
            $sheet->getStyle($collectedCol . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle($collectedCol . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D3D3D3');
            $sheet->getStyle($collectedCol . $currentRow)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($collectedCol . $currentRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            
            $currentRow++;
        }
        
        // Auto-size columns
        foreach (range('A', chr(64 + count($headers))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Also auto-size column B for stock section
        if ($report_type === 'inventory' && isset($stock_data) && !empty($stock_data)) {
            $sheet->getColumnDimension('B')->setAutoSize(true);
        }
        
        // Add footer
        $currentRow += 2;
        $sheet->setCellValue('A' . $currentRow, 'City of Talisay Business Affairs Office');
        $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
        $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $currentRow++;
        
        $sheet->setCellValue('A' . $currentRow, 'This report was generated automatically on ' . $generated_date . ' at ' . $generated_time);
        $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
        $sheet->getStyle('A' . $currentRow)->getFont()->setSize(10);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $currentRow++;
        
        $sheet->setCellValue('A' . $currentRow, 'For inquiries, please contact the Business Affairs Office');
        $sheet->mergeCells('A' . $currentRow . ':' . chr(64 + count($headers)) . $currentRow);
        $sheet->getStyle('A' . $currentRow)->getFont()->setSize(10);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Set filename
        $filename = strtolower(str_replace(' ', '_', $title)) . '_' . (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Ymd_His') . '.xlsx';
        
        // Output file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        
    } else {
        // Generate Excel XML format (SpreadsheetML) with styling - NO COMPOSER REQUIRED
        $now_dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $generated_date = $now_dt->format('F j, Y');
        $generated_time = $now_dt->format('g:i A');
        
        $filename = strtolower(str_replace(' ', '_', $title)) . '_' . $now_dt->format('Ymd_His') . '.xls';
        
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        // Start XML output
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
        echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
        echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        echo ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        
        // Styles
        echo '<Styles>' . "\n";
        // Style 1: Header (Blue background, white text, bold, centered)
        echo '<Style ss:ID="Header">' . "\n";
        echo '<Font ss:Bold="1" ss:Size="12" ss:Color="#FFFFFF"/>' . "\n";
        echo '<Interior ss:Color="#1e3a8a" ss:Pattern="Solid"/>' . "\n";
        echo '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
        echo '<Borders>' . "\n";
        echo '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '</Borders>' . "\n";
        echo '</Style>' . "\n";
        
        // Style 2: Table Header (Gray background, bold, centered)
        echo '<Style ss:ID="TableHeader">' . "\n";
        echo '<Font ss:Bold="1" ss:Size="11"/>' . "\n";
        echo '<Interior ss:Color="#D3D3D3" ss:Pattern="Solid"/>' . "\n";
        echo '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
        echo '<Borders>' . "\n";
        echo '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '</Borders>' . "\n";
        echo '</Style>' . "\n";
        
        // Style 3: Title (Bold, large, centered)
        echo '<Style ss:ID="Title">' . "\n";
        echo '<Font ss:Bold="1" ss:Size="14"/>' . "\n";
        echo '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n";
        echo '</Style>' . "\n";
        
        // Style 4: Subtitle (Bold, medium)
        echo '<Style ss:ID="Subtitle">' . "\n";
        echo '<Font ss:Bold="1" ss:Size="12"/>' . "\n";
        echo '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n";
        echo '</Style>' . "\n";
        
        // Style 5: Data cells (with borders)
        echo '<Style ss:ID="Data">' . "\n";
        echo '<Borders>' . "\n";
        echo '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '</Borders>' . "\n";
        echo '<Alignment ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
        echo '</Style>' . "\n";
        
        // Style 6: Total row (Gray background, bold)
        echo '<Style ss:ID="Total">' . "\n";
        echo '<Font ss:Bold="1"/>' . "\n";
        echo '<Interior ss:Color="#D3D3D3" ss:Pattern="Solid"/>' . "\n";
        echo '<Borders>' . "\n";
        echo '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>' . "\n";
        echo '</Borders>' . "\n";
        echo '<Alignment ss:Vertical="Center"/>' . "\n";
        echo '</Style>' . "\n";
        
        // Style 7: Info (Bold label)
        echo '<Style ss:ID="Info">' . "\n";
        echo '<Font ss:Bold="1"/>' . "\n";
        echo '</Style>' . "\n";
        
        echo '</Styles>' . "\n";
        
        // Worksheet
        echo '<Worksheet ss:Name="Report">' . "\n";
        echo '<Table>' . "\n";
        
        $rowNum = 1;
        
        // Header Section
        echo '<Row ss:Height="25">' . "\n";
        echo '<Cell ss:StyleID="Header" ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">' . htmlspecialchars('BUSINESS AFFAIRS OFFICE REPORTS', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $rowNum++;
        
        echo '<Row ss:Height="20">' . "\n";
        echo '<Cell ss:StyleID="Subtitle" ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">' . htmlspecialchars('CITY OF TALISAY, Province of Negros Occidental', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $rowNum++;
        
        echo '<Row ss:Height="20">' . "\n";
        echo '<Cell ss:StyleID="Subtitle" ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">' . htmlspecialchars('CHMSU - Carlos Hilado Memorial State University', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $rowNum += 2;
        
        // Generation info
        echo '<Row ss:Height="20">' . "\n";
        echo '<Cell ss:StyleID="Info"><Data ss:Type="String">' . htmlspecialchars('Generated on:', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($generated_date . ' at ' . $generated_time, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $rowNum += 2;
        
        // Report title
        echo '<Row ss:Height="25">' . "\n";
        echo '<Cell ss:StyleID="Title" ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $rowNum += 2;
        
        // Period/Filters info
        $period_info = [];
        if (isset($mode)) {
            if ($mode === 'daily') {
                $period_info[] = 'Date: ' . date('F j, Y', strtotime($date));
            } elseif ($mode === 'monthly') {
                $period_info[] = 'Month: ' . date('F Y', strtotime($month . '-01'));
            } elseif ($mode === 'yearly') {
                $period_info[] = 'Year: ' . $year;
            }
        }
        if (!empty($start_date) && !empty($end_date)) {
            if ($start_date === $end_date) {
                $period_info[] = 'Date: ' . date('F j, Y', strtotime($start_date));
            } else {
                $period_info[] = 'Period: ' . date('F j, Y', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date));
            }
        } elseif (!empty($start_date)) {
            $period_info[] = 'From: ' . date('F j, Y', strtotime($start_date));
        } elseif (!empty($end_date)) {
            $period_info[] = 'To: ' . date('F j, Y', strtotime($end_date));
        }
        // For gym reports, use status_filter (defined at top of gym case)
        // For other reports, use status variable
        $display_status = '';
        if ($report_type === 'gym' && isset($status_filter)) {
            $display_status = $status_filter;
        } elseif (isset($status)) {
            $display_status = $status;
        }
        if (!empty($display_status)) {
            $period_info[] = 'Status: ' . ucfirst($display_status);
        }
        if (!empty($user_type_filter) && $report_type === 'gym') {
            $user_type_label = ($user_type_filter === 'internal') ? 'Internal Users' : (($user_type_filter === 'external') ? 'External Users' : 'All Users');
            $period_info[] = 'User Type: ' . $user_type_label;
        }
        if (!empty($role)) {
            $period_info[] = 'Role: ' . ucfirst($role);
        }
        if (!empty($department)) {
            $period_info[] = 'Department: ' . $department;
        }
        if (!empty($service)) {
            $period_info[] = 'Service: ' . ucfirst($service);
        }
        if (!empty($report_type_param)) {
            $period_info[] = 'Report Type: ' . ucfirst($report_type_param);
        }
        
        if (!empty($period_info)) {
            foreach ($period_info as $info) {
                echo '<Row ss:Height="20">' . "\n";
                echo '<Cell><Data ss:Type="String">' . htmlspecialchars($info, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '</Row>' . "\n";
                $rowNum++;
            }
            $rowNum++;
        }
        
        // Table headers
        echo '<Row ss:Height="22">' . "\n";
        foreach ($headers as $header) {
            echo '<Cell ss:StyleID="TableHeader"><Data ss:Type="String">' . htmlspecialchars($header, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        }
        echo '</Row>' . "\n";
        $rowNum++;
        
        // Data rows
        foreach ($data as $row) {
            echo '<Row ss:Height="18">' . "\n";
            foreach ($row as $cellValue) {
                $escaped = htmlspecialchars($cellValue, ENT_XML1, 'UTF-8');
                // Check if it's a number (for proper Excel formatting)
                $cleanValue = str_replace(['₱', ',', ' '], '', $cellValue);
                if (is_numeric($cleanValue) && strpos($cellValue, '₱') === false) {
                    echo '<Cell ss:StyleID="Data"><Data ss:Type="Number">' . $cleanValue . '</Data></Cell>' . "\n";
                } else {
                    echo '<Cell ss:StyleID="Data"><Data ss:Type="String">' . $escaped . '</Data></Cell>' . "\n";
                }
            }
            echo '</Row>' . "\n";
            $rowNum++;
        }
        
        // Total rows
        if ($report_type === 'billing' && !empty($rows)) {
            $billing_total = 0.0;
            foreach ($rows as $r) {
                $billing_total += (float)$r['amount'];
            }
            echo '<Row ss:Height="20">' . "\n";
            // First 3 columns merged for "Total:"
            echo '<Cell ss:StyleID="Total" ss:MergeAcross="2"><Data ss:Type="String">' . htmlspecialchars('Total:', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            // Amount column
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String">' . htmlspecialchars('₱' . number_format($billing_total, 2), ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            // Remaining 3 columns empty
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            $rowNum++;
        }
        
        
        if ($report_type === 'inventory' && isset($total_revenue)) {
            echo '<Row ss:Height="20">' . "\n";
            for ($i = 0; $i < count($headers) - 1; $i++) {
                if ($i === count($headers) - 2) {
                    echo '<Cell ss:StyleID="Total"><Data ss:Type="String">' . htmlspecialchars('Total Revenue:', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                } else {
                    echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>' . "\n";
                }
            }
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String">' . htmlspecialchars('₱' . number_format($total_revenue, 2), ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            $rowNum++;
        }
        
        if ($report_type === 'billing' && !empty($rows)) {
            $billing_total = 0.0;
            foreach ($rows as $r) {
                $billing_total += (float)$r['amount'];
            }
            echo '<Row ss:Height="20">' . "\n";
            echo '<Cell ss:StyleID="Total" ss:MergeAcross="2"><Data ss:Type="String">' . htmlspecialchars('Total:', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String">' . htmlspecialchars('₱' . number_format($billing_total, 2), ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String"></Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            $rowNum++;
        }
        
        if ($report_type === 'summary' && isset($total_requests) && isset($total_approved) && isset($total_collected)) {
            echo '<Row ss:Height="20">' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String">' . htmlspecialchars('Total:', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="Number">' . number_format($total_requests) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="Number">' . number_format($total_approved) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="Total"><Data ss:Type="String">' . htmlspecialchars('₱' . number_format($total_collected, 2), ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            $rowNum++;
        }
        
        // Remaining Stock section for inventory
        if ($report_type === 'inventory' && isset($stock_data) && !empty($stock_data)) {
            $rowNum += 2;
            echo '<Row ss:Height="25">' . "\n";
            echo '<Cell ss:StyleID="Title" ss:MergeAcross="1"><Data ss:Type="String">' . htmlspecialchars('Remaining Stock', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            $rowNum++;
            
            echo '<Row ss:Height="22">' . "\n";
            echo '<Cell ss:StyleID="TableHeader"><Data ss:Type="String">' . htmlspecialchars('Item', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="TableHeader"><Data ss:Type="String">' . htmlspecialchars('Remaining', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            $rowNum++;
            
            foreach ($stock_data as $stock_row) {
                echo '<Row ss:Height="18">' . "\n";
                echo '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($stock_row[0], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="Data"><Data ss:Type="Number">' . htmlspecialchars($stock_row[1], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '</Row>' . "\n";
                $rowNum++;
            }
        }
        
        // Footer
        $rowNum += 2;
        echo '<Row ss:Height="20">' . "\n";
        echo '<Cell ss:StyleID="Info" ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">' . htmlspecialchars('City of Talisay Business Affairs Office', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $rowNum++;
        
        echo '<Row ss:Height="18">' . "\n";
        echo '<Cell ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">' . htmlspecialchars('This report was generated automatically on ' . $generated_date . ' at ' . $generated_time, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $rowNum++;
        
        echo '<Row ss:Height="18">' . "\n";
        echo '<Cell ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">' . htmlspecialchars('For inquiries, please contact the Business Affairs Office', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        
        echo '</Table>' . "\n";
        
        // Column widths - Calculate based on header length and report type
        $columnWidths = [];
        
        // Define minimum and maximum widths
        $minWidth = 100;
        $maxWidth = 350;
        $defaultWidth = 180;
        
        // Calculate width for each column based on header length
        foreach ($headers as $index => $header) {
            $headerLength = strlen($header);
            // Base width on header length (approximately 10 pixels per character)
            $calculatedWidth = max($minWidth, min($maxWidth, $headerLength * 10 + 40));
            
            // Adjust for specific report types
            if ($report_type === 'gym') {
                // Gym reports have many columns, need much wider columns
                switch (strtolower($header)) {
                    case 'booking id':
                        $calculatedWidth = 180;
                        break;
                    case 'status':
                        $calculatedWidth = 140;
                        break;
                    case 'facility':
                        $calculatedWidth = 200;
                        break;
                    case 'date':
                        $calculatedWidth = 160;
                        break;
                    case 'time':
                        $calculatedWidth = 250;
                        break;
                    case 'purpose':
                        $calculatedWidth = 200;
                        break;
                    case 'attendees':
                    case 'attendee':
                        $calculatedWidth = 140;
                        break;
                    case 'requested on':
                    case 'request (date)':
                        $calculatedWidth = 250;
                        break;
                    case 'requester name':
                    case 'requestor name':
                        $calculatedWidth = 200;
                        break;
                    case 'department/organization':
                        $calculatedWidth = 280;
                        break;
                    case 'equipment/services':
                    case 'equipment/service':
                        $calculatedWidth = 280;
                        break;
                    default:
                        $calculatedWidth = max($calculatedWidth, 200);
                }
            } elseif ($report_type === 'bus') {
                // Bus reports
                switch (strtolower($header)) {
                    case 'reservation id':
                        $calculatedWidth = 200;
                        break;
                    case 'requester/dept':
                        $calculatedWidth = 220;
                        break;
                    case 'destination':
                        $calculatedWidth = 250;
                        break;
                    case 'date':
                        $calculatedWidth = 160;
                        break;
                    case 'vehicles':
                        $calculatedWidth = 140;
                        break;
                    case 'status':
                        $calculatedWidth = 140;
                        break;
                    case 'total amount':
                        $calculatedWidth = 180;
                        break;
                    default:
                        $calculatedWidth = max($calculatedWidth, 180);
                }
            } elseif ($report_type === 'billing') {
                // Billing reports
                switch (strtolower($header)) {
                    case 'billing id':
                        $calculatedWidth = 180;
                        break;
                    case 'service':
                        $calculatedWidth = 140;
                        break;
                    case 'requester / details':
                        $calculatedWidth = 280;
                        break;
                    case 'amount':
                        $calculatedWidth = 180;
                        break;
                    case 'payment status':
                        $calculatedWidth = 180;
                        break;
                    case 'created':
                        $calculatedWidth = 180;
                        break;
                    default:
                        $calculatedWidth = max($calculatedWidth, 180);
                }
            } else {
                // Default for other report types
                $calculatedWidth = max($calculatedWidth, $defaultWidth);
            }
            
            $columnWidths[] = $calculatedWidth;
        }
        
        // Output column widths
        foreach ($columnWidths as $width) {
            echo '<Column ss:Width="' . $width . '"/>' . "\n";
        }
        
        echo '</Worksheet>' . "\n";
        echo '</Workbook>';
        exit;
    }
}
?>

