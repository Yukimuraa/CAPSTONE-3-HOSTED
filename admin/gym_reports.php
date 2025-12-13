<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is an admin
require_admin();

// Get report parameters
$report_type = isset($_GET['report_type']) ? sanitize_input($_GET['report_type']) : '';
$user_type_filter = isset($_GET['user_type']) ? sanitize_input($_GET['user_type']) : '';

// Set page title based on report type
$page_title = "Gym Reports - CHMSU BAO";
switch ($report_type) {
    case 'usage':
        $page_title = "Gym Usage Report - CHMSU BAO";
        break;
    case 'utilization':
        $page_title = "Facility Utilization Report - CHMSU BAO";
        break;
    case 'status':
        $page_title = "Booking Status Report - CHMSU BAO";
        break;
}

$base_url = "..";

// Generate report data based on type
$report_data = [];
$chart_data = [];

if ($report_type === 'usage') {
    $start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
    $end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');
    
    // Get usage data with user type breakdown
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
    
    // Calculate collected amounts for external users
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
    
    if ($user_type_filter === 'external') {
        // Already filtered by external
    } elseif ($user_type_filter === 'internal') {
        // No collected for internal
        $collected_query = "SELECT b.date, b.additional_info FROM bookings b WHERE 1=0";
    }
    
    $collected_stmt = $conn->prepare($collected_query);
    if ($user_type_filter !== 'internal') {
        $collected_stmt->bind_param($collected_types, ...$collected_params);
        $collected_stmt->execute();
        $collected_result = $collected_stmt->get_result();
        
        $collected_by_date = [];
        $total_collected = 0;
        while ($collected_row = $collected_result->fetch_assoc()) {
            $additional_info = json_decode($collected_row['additional_info'] ?? '{}', true) ?: [];
            $cost_breakdown = $additional_info['cost_breakdown'] ?? [];
            $total = isset($cost_breakdown['total']) ? (float)$cost_breakdown['total'] : 0;
            
            $date_key = $collected_row['date'];
            if (!isset($collected_by_date[$date_key])) {
                $collected_by_date[$date_key] = 0;
            }
            $collected_by_date[$date_key] += $total;
            $total_collected += $total;
        }
    } else {
        $collected_by_date = [];
        $total_collected = 0;
    }
    
    while ($row = $result->fetch_assoc()) {
        $row['internal_count'] = (int)$row['internal_count'];
        $row['external_count'] = (int)$row['external_count'];
        $row['total_attendees'] = (int)($row['total_attendees'] ?? 0);
        $row['collected'] = isset($collected_by_date[$row['booking_date']]) ? $collected_by_date[$row['booking_date']] : 0;
        $report_data[] = $row;
    }
    
    // Prepare chart data - show total bookings per date with internal/external breakdown
    $chart_query = "SELECT b.date, 
                           COUNT(*) as booking_count,
                           COUNT(CASE WHEN u.user_type IN ('student', 'faculty', 'staff') THEN 1 END) as internal_count,
                           COUNT(CASE WHEN u.user_type = 'external' THEN 1 END) as external_count
                   FROM bookings b 
                   LEFT JOIN user_accounts u ON b.user_id = u.id
                   WHERE b.facility_type = 'gym' AND b.date BETWEEN ? AND ?";
    
    $chart_params = [$start_date, $end_date];
    $chart_types = "ss";
    
    if ($user_type_filter === 'internal') {
        $chart_query .= " AND u.user_type IN ('student', 'faculty', 'staff')";
    } elseif ($user_type_filter === 'external') {
        $chart_query .= " AND u.user_type = 'external'";
    }
    
    $chart_query .= " GROUP BY b.date ORDER BY b.date ASC";
    
    $chart_stmt = $conn->prepare($chart_query);
    $chart_stmt->bind_param($chart_types, ...$chart_params);
    $chart_stmt->execute();
    $chart_result = $chart_stmt->get_result();
    
    $labels = [];
    $internal_data = [];
    $external_data = [];
    
    while ($row = $chart_result->fetch_assoc()) {
        $labels[] = date('M j', strtotime($row['date']));
        $internal_data[] = (int)$row['internal_count'];
        $external_data[] = (int)$row['external_count'];
    }
    
    $chart_data = [
        'labels' => $labels,
        'internal_data' => $internal_data,
        'external_data' => $external_data
    ];
} elseif ($report_type === 'utilization') {
    $month = isset($_GET['month']) ? sanitize_input($_GET['month']) : date('Y-m');
    $facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
    
    // Get start and end date of the month
    $start_date = $month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    
    // Since bookings don't have facility_id, we'll show overall gym utilization
    // Get all gym facilities and show overall booking stats (same for all since we can't distinguish)
    $facilities_query = "SELECT id, name as facility_name, capacity FROM gym_facilities";
    $facility_params = [];
    $facility_types = "";
    
    if ($facility_id > 0) {
        $facilities_query .= " WHERE id = ?";
        $facility_params[] = $facility_id;
        $facility_types = "i";
    }
    
    $facilities_query .= " ORDER BY name ASC";
    $facility_stmt = $conn->prepare($facilities_query);
    if (!empty($facility_params)) {
        $facility_stmt->bind_param($facility_types, ...$facility_params);
    }
    $facility_stmt->execute();
    $facilities_result = $facility_stmt->get_result();
    
    // Get overall booking stats for the period
    $stats_query = "SELECT 
              COUNT(*) as booking_count,
              COUNT(CASE WHEN status = 'confirmed' OR status = 'approved' THEN 1 ELSE NULL END) as approved_count,
              COUNT(CASE WHEN status = 'rejected' THEN 1 ELSE NULL END) as rejected_count,
              COUNT(CASE WHEN status = 'cancelled' THEN 1 ELSE NULL END) as cancelled_count
              FROM bookings 
              WHERE facility_type = 'gym' AND date BETWEEN ? AND ?";
    
    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->bind_param("ss", $start_date, $end_date);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
    
    // Assign same stats to all facilities (since we can't distinguish)
    while ($facility = $facilities_result->fetch_assoc()) {
        $row = [
            'id' => $facility['id'],
            'facility_name' => $facility['facility_name'],
            'capacity' => $facility['capacity'],
            'booking_count' => $stats['booking_count'] ?? 0,
            'approved_count' => $stats['approved_count'] ?? 0,
            'rejected_count' => $stats['rejected_count'] ?? 0,
            'cancelled_count' => $stats['cancelled_count'] ?? 0
        ];
        
        // Calculate utilization percentage
        $days_in_month = date('t', strtotime($start_date));
        $max_possible_bookings = $days_in_month; // Assuming 1 booking per day
        $utilization_rate = $max_possible_bookings > 0 ? (($row['approved_count'] / $max_possible_bookings) * 100) : 0;
        
        $row['utilization_rate'] = round($utilization_rate, 2);
        $report_data[] = $row;
    }
    
    // Prepare chart data
    $labels = [];
    $data = [];
    
    foreach ($report_data as $row) {
        $labels[] = $row['facility_name'];
        $data[] = $row['utilization_rate'];
    }
    
    $chart_data = [
        'labels' => $labels,
        'data' => $data
    ];
} elseif ($report_type === 'status') {
    $status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
    $period = isset($_GET['period']) ? sanitize_input($_GET['period']) : 'month';
    
    // Check if custom date range is provided
    if (isset($_GET['start_date']) && !empty($_GET['start_date']) && isset($_GET['end_date']) && !empty($_GET['end_date'])) {
        $start_date = sanitize_input($_GET['start_date']);
        $end_date = sanitize_input($_GET['end_date']);
    } else {
        // Determine date range based on period
        $end_date = date('Y-m-d');
        switch ($period) {
            case 'week':
                $start_date = date('Y-m-d', strtotime('-1 week'));
                break;
            case 'month':
                $start_date = date('Y-m-d', strtotime('-1 month'));
                break;
            case 'quarter':
                $start_date = date('Y-m-d', strtotime('-3 months'));
                break;
            case 'year':
                $start_date = date('Y-m-d', strtotime('-1 year'));
                break;
            default:
                $start_date = date('Y-m-d', strtotime('-1 month'));
        }
    }
    
    // Build query based on status filter with user type breakdown
    $query = "SELECT b.status, 'Gymnasium' as facility_name, 
                     COUNT(*) as booking_count,
                     COUNT(CASE WHEN u.user_type IN ('student', 'faculty', 'staff') THEN 1 END) as internal_count,
                     COUNT(CASE WHEN u.user_type = 'external' THEN 1 END) as external_count,
                     SUM(COALESCE(b.attendees, 0)) as total_attendees
              FROM bookings b 
              LEFT JOIN user_accounts u ON b.user_id = u.id
              WHERE b.facility_type = 'gym' AND b.date BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    $types = "ss";
    
    if (!empty($status)) {
        // Handle both 'approved' and 'confirmed' status
        if ($status === 'approved') {
            $query .= " AND (b.status = 'approved' OR b.status = 'confirmed')";
        } else {
            $query .= " AND b.status = ?";
            $params[] = $status;
            $types .= "s";
        }
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
    
    // Calculate collected amounts for external users
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
        } else {
            $collected_query .= " AND b.status = ?";
            $collected_params[] = $status;
            $collected_types .= "s";
        }
    }
    
    if ($user_type_filter === 'internal') {
        $collected_query = "SELECT b.status, b.additional_info FROM bookings b WHERE 1=0";
    }
    
    $collected_stmt = $conn->prepare($collected_query);
    if ($user_type_filter !== 'internal') {
        $collected_stmt->bind_param($collected_types, ...$collected_params);
        $collected_stmt->execute();
        $collected_result = $collected_stmt->get_result();
        
        $collected_by_status = [];
        $total_collected = 0;
        while ($collected_row = $collected_result->fetch_assoc()) {
            $additional_info = json_decode($collected_row['additional_info'] ?? '{}', true) ?: [];
            $cost_breakdown = $additional_info['cost_breakdown'] ?? [];
            $total = isset($cost_breakdown['total']) ? (float)$cost_breakdown['total'] : 0;
            
            $status_key = $collected_row['status'];
            if (!isset($collected_by_status[$status_key])) {
                $collected_by_status[$status_key] = 0;
            }
            $collected_by_status[$status_key] += $total;
            $total_collected += $total;
        }
    } else {
        $collected_by_status = [];
        $total_collected = 0;
    }
    
    while ($row = $result->fetch_assoc()) {
        $row['internal_count'] = (int)$row['internal_count'];
        $row['external_count'] = (int)$row['external_count'];
        $row['total_attendees'] = (int)($row['total_attendees'] ?? 0);
        $status_key = $row['status'];
        // Handle approved/confirmed status for collected
        if ($status_key === 'approved' || $status_key === 'confirmed') {
            $collected = ($collected_by_status['approved'] ?? 0) + ($collected_by_status['confirmed'] ?? 0);
        } else {
            $collected = $collected_by_status[$status_key] ?? 0;
        }
        $row['collected'] = $collected;
        $report_data[] = $row;
    }
    
    // Prepare chart data
    $status_query = "SELECT b.status, 
                            COUNT(*) as count,
                            COUNT(CASE WHEN u.user_type IN ('student', 'faculty', 'staff') THEN 1 END) as internal_count,
                            COUNT(CASE WHEN u.user_type = 'external' THEN 1 END) as external_count
                    FROM bookings b 
                    LEFT JOIN user_accounts u ON b.user_id = u.id
                    WHERE b.facility_type = 'gym' AND b.date BETWEEN ? AND ?";
    
    $status_params = [$start_date, $end_date];
    $status_types = "ss";
    
    if (!empty($status)) {
        if ($status === 'approved') {
            $status_query .= " AND (b.status = 'approved' OR b.status = 'confirmed')";
        } else {
            $status_query .= " AND b.status = ?";
            $status_params[] = $status;
            $status_types .= "s";
        }
    }
    
    if ($user_type_filter === 'internal') {
        $status_query .= " AND u.user_type IN ('student', 'faculty', 'staff')";
    } elseif ($user_type_filter === 'external') {
        $status_query .= " AND u.user_type = 'external'";
    }
    
    $status_query .= " GROUP BY b.status";
    
    $status_stmt = $conn->prepare($status_query);
    $status_stmt->bind_param($status_types, ...$status_params);
    $status_stmt->execute();
    $status_result = $status_stmt->get_result();
    
    $labels = [];
    $internal_data = [];
    $external_data = [];
    
    while ($row = $status_result->fetch_assoc()) {
        $status_label = $row['status'];
        // Normalize status labels
        if ($status_label === 'confirmed') {
            $status_label = 'approved';
        }
        $labels[] = ucfirst($status_label);
        $internal_data[] = (int)$row['internal_count'];
        $external_data[] = (int)$row['external_count'];
    }
    
    $chart_data = [
        'labels' => $labels,
        'internal_data' => $internal_data,
        'external_data' => $external_data
    ];
}
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900"><?php echo $page_title; ?></h1>
                <div class="flex items-center">
                    <span class="text-gray-700 mr-2"><?php echo $_SESSION['user_name']; ?></span>
                    <button class="md:hidden rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500" id="menu-button">
                        <span class="sr-only">Open menu</span>
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Main content -->
        <main class="flex-1 overflow-y-auto p-4 bg-gray-50">
            <div class="max-w-7xl mx-auto">
                <!-- Professional Report Header -->
                <div class="mb-6" style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); border-top: 4px solid #fbbf24; border-radius: 8px 8px 0 0;">
                    <div class="px-8 py-6">
                        <div class="flex items-center justify-center gap-4 mb-4">
                            <?php if (file_exists('../image/CHMSUWebLOGO.png')): ?>
                                <img src="../image/CHMSUWebLOGO.png" alt="CHMSU Logo" style="height: 80px; width: auto;">
                            <?php endif; ?>
                        </div>
                        <div class="text-center">
                            <h1 class="text-3xl font-bold mb-2 text-white">BUSINESS AFFAIRS OFFICE REPORTS</h1>
                            <p class="text-lg font-medium mb-1 text-white">CITY OF TALISAY, Province of Negros Occidental</p>
                            <p class="text-sm text-white opacity-90">CHMSU - Carlos Hilado Memorial State University</p>
                        </div>
                    </div>
                </div>
                
                <!-- Report Information Section -->
                <div class="mb-6 bg-white rounded-b-lg shadow-lg p-6 border-l-4 border-blue-800">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-600 mb-1"><strong>Generated on:</strong> <?php echo date('F j, Y'); ?> at <?php echo date('g:i A'); ?></p>
                            <p class="text-gray-600"><strong>Generated by:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        </div>
                        <div>
                            <p class="text-gray-600 mb-1"><strong>Report Type:</strong> 
                                <?php 
                                if ($report_type === 'usage') echo 'Gym Usage Report';
                                elseif ($report_type === 'utilization') echo 'Facility Utilization Report';
                                elseif ($report_type === 'status') echo 'Booking Status Report';
                                else echo 'Gym Report';
                                ?>
                            </p>
                            <p class="text-gray-600"><strong>Office:</strong> Business Affairs Office</p>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="mb-6 bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Filter Report</h2>
                    <form method="GET" action="gym_reports.php" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($report_type); ?>">
                        
                        <?php if ($report_type === 'usage'): ?>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Start Date</label>
                                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full rounded-md border-gray-300">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">End Date</label>
                                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full rounded-md border-gray-300">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">User Type</label>
                                <select name="user_type" class="w-full rounded-md border-gray-300">
                                    <option value="" <?php echo ($user_type_filter === '') ? 'selected' : ''; ?>>All Users</option>
                                    <option value="internal" <?php echo ($user_type_filter === 'internal') ? 'selected' : ''; ?>>Internal</option>
                                    <option value="external" <?php echo ($user_type_filter === 'external') ? 'selected' : ''; ?>>External</option>
                                </select>
                            </div>
                        <?php elseif ($report_type === 'utilization'): ?>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Month</label>
                                <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" class="w-full rounded-md border-gray-300">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Facility</label>
                                <select name="facility_id" class="w-full rounded-md border-gray-300">
                                    <option value="0">All Facilities</option>
                                    <?php
                                    $facilities_query = "SELECT id, name FROM gym_facilities ORDER BY name ASC";
                                    $facilities_result = $conn->query($facilities_query);
                                    while ($fac = $facilities_result->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo $fac['id']; ?>" <?php echo ($facility_id == $fac['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fac['name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">User Type</label>
                                <select name="user_type" class="w-full rounded-md border-gray-300">
                                    <option value="" <?php echo ($user_type_filter === '') ? 'selected' : ''; ?>>All Users</option>
                                    <option value="internal" <?php echo ($user_type_filter === 'internal') ? 'selected' : ''; ?>>Internal</option>
                                    <option value="external" <?php echo ($user_type_filter === 'external') ? 'selected' : ''; ?>>External</option>
                                </select>
                            </div>
                        <?php elseif ($report_type === 'status'): ?>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Start Date</label>
                                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full rounded-md border-gray-300">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">End Date</label>
                                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full rounded-md border-gray-300">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Status</label>
                                <select name="status" class="w-full rounded-md border-gray-300">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo ($status === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo ($status === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo ($status === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">User Type</label>
                                <select name="user_type" class="w-full rounded-md border-gray-300">
                                    <option value="" <?php echo ($user_type_filter === '') ? 'selected' : ''; ?>>All Users</option>
                                    <option value="internal" <?php echo ($user_type_filter === 'internal') ? 'selected' : ''; ?>>Internal</option>
                                    <option value="external" <?php echo ($user_type_filter === 'external') ? 'selected' : ''; ?>>External</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                                <i class="fas fa-filter mr-2"></i>Apply Filter
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Report Header -->
                <div class="mb-6 bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold text-gray-900">
                            <?php if ($report_type === 'usage'): ?>
                                Gym Usage Report
                            <?php elseif ($report_type === 'utilization'): ?>
                                Facility Utilization Report
                            <?php elseif ($report_type === 'status'): ?>
                                Booking Status Report
                            <?php else: ?>
                                Gym Report
                            <?php endif; ?>
                        </h2>
                        <div class="flex gap-2">
                            <a href="download_report.php?type=gym&format=pdf&report_type=<?php echo htmlspecialchars($report_type); ?>&<?php echo http_build_query($_GET); ?>" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                                <i class="fas fa-print mr-1"></i> Print PDF
                            </a>
                            <a href="download_report.php?type=gym&format=excel&report_type=<?php echo htmlspecialchars($report_type); ?>&<?php echo http_build_query($_GET); ?>" class="bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                <i class="fas fa-file-excel mr-1"></i> Download Excel
                            </a>
                            <a href="gym_management.php" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                                <i class="fas fa-arrow-left mr-1"></i> Back
                            </a>
                        </div>
                    </div>
                    
                    <div class="text-sm text-gray-500">
                        <?php if ($report_type === 'usage'): ?>
                            <p>Report Period: <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></p>
                        <?php elseif ($report_type === 'utilization'): ?>
                            <p>Report Month: <?php echo date('F Y', strtotime($month)); ?></p>
                            <?php if ($facility_id > 0): ?>
                                <?php 
                                $facility_query = "SELECT name FROM gym_facilities WHERE id = ?";
                                $facility_stmt = $conn->prepare($facility_query);
                                $facility_stmt->bind_param("i", $facility_id);
                                $facility_stmt->execute();
                                $facility_result = $facility_stmt->get_result();
                                $facility = $facility_result->fetch_assoc();
                                ?>
                                <p>Facility: <?php echo $facility['name']; ?></p>
                            <?php else: ?>
                                <p>Facility: All Facilities</p>
                            <?php endif; ?>
                        <?php elseif ($report_type === 'status'): ?>
                            <p>Report Period: <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></p>
                            <?php if (!empty($status)): ?>
                                <p>Status: <?php echo ucfirst($status); ?></p>
                            <?php else: ?>
                                <p>Status: All Statuses</p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <p>Generated on: <?php echo date('F j, Y, g:i a'); ?></p>
                    </div>
                </div>
                
                <!-- Chart -->
                <div class="mb-6 bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Report Visualization</h3>
                    <div class="h-64">
                        <canvas id="reportChart"></canvas>
                    </div>
                </div>
                
                <!-- Report Data -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <h3 class="text-lg font-medium text-gray-900">Report Data</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <?php if ($report_type === 'usage'): ?>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Bookings</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Internal</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">External</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Number of Attendees</th>
                                        <?php if ($user_type_filter !== 'internal'): ?>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Collected</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (count($report_data) > 0): ?>
                                        <?php 
                                        $total_bookings = 0;
                                        $total_internal = 0;
                                        $total_external = 0;
                                        $total_attendees = 0;
                                        $total_collected = 0;
                                        foreach ($report_data as $row): 
                                            $total_bookings += $row['booking_count'];
                                            $total_internal += $row['internal_count'];
                                            $total_external += $row['external_count'];
                                            $total_attendees += $row['total_attendees'] ?? 0;
                                            $total_collected += $row['collected'] ?? 0;
                                        ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('F j, Y', strtotime($row['booking_date'])); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['facility_name']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['booking_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['internal_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['external_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo number_format($row['total_attendees'] ?? 0); ?></td>
                                                <?php if ($user_type_filter !== 'internal'): ?>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">₱<?php echo number_format($row['collected'] ?? 0, 2); ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if ($user_type_filter !== 'internal'): ?>
                                        <tr class="bg-gray-50 font-semibold">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" colspan="2">Total</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_bookings; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_internal; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_external; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($total_attendees); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">₱<?php echo number_format($total_collected, 2); ?></td>
                                        </tr>
                                        <?php else: ?>
                                        <tr class="bg-gray-50 font-semibold">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" colspan="2">Total</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_bookings; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_internal; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_external; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($total_attendees); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo ($user_type_filter !== 'internal') ? '6' : '5'; ?>" class="px-6 py-4 text-center text-sm text-gray-500">No data found for the selected period</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php elseif ($report_type === 'utilization'): ?>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Capacity</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Bookings</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Approved</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rejected</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cancelled</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilization Rate</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (count($report_data) > 0): ?>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['facility_name']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['capacity']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['booking_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['approved_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['rejected_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['cancelled_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="flex items-center">
                                                        <span class="mr-2"><?php echo $row['utilization_rate']; ?>%</span>
                                                        <div class="w-24 bg-gray-200 rounded-full h-2.5">
                                                            <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo min($row['utilization_rate'], 100); ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No data found for the selected period</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php elseif ($report_type === 'status'): ?>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Bookings</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Internal</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">External</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Number of Attendees</th>
                                        <?php if ($user_type_filter !== 'internal'): ?>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Collected</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (count($report_data) > 0): ?>
                                        <?php 
                                        $total_bookings = 0;
                                        $total_internal = 0;
                                        $total_external = 0;
                                        $total_attendees = 0;
                                        $total_collected = 0;
                                        foreach ($report_data as $row): 
                                            $total_bookings += $row['booking_count'];
                                            $total_internal += $row['internal_count'];
                                            $total_external += $row['external_count'];
                                            $total_attendees += $row['total_attendees'] ?? 0;
                                            $total_collected += $row['collected'] ?? 0;
                                        ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php 
                                                    $status_class = '';
                                                    $status_display = $row['status'];
                                                    if ($status_display === 'confirmed') {
                                                        $status_display = 'approved';
                                                    }
                                                    switch ($status_display) {
                                                        case 'pending':
                                                            $status_class = 'bg-yellow-100 text-yellow-800';
                                                            break;
                                                        case 'approved':
                                                            $status_class = 'bg-green-100 text-green-800';
                                                            break;
                                                        case 'rejected':
                                                            $status_class = 'bg-red-100 text-red-800';
                                                            break;
                                                        case 'cancelled':
                                                            $status_class = 'bg-gray-100 text-gray-800';
                                                            break;
                                                    }
                                                    ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($status_display); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['facility_name']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['booking_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['internal_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $row['external_count']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo number_format($row['total_attendees'] ?? 0); ?></td>
                                                <?php if ($user_type_filter !== 'internal'): ?>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">₱<?php echo number_format($row['collected'] ?? 0, 2); ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if ($user_type_filter !== 'internal'): ?>
                                        <tr class="bg-gray-50 font-semibold">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" colspan="2">Total</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_bookings; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_internal; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_external; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($total_attendees); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">₱<?php echo number_format($total_collected, 2); ?></td>
                                        </tr>
                                        <?php else: ?>
                                        <tr class="bg-gray-50 font-semibold">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" colspan="2">Total</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_bookings; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_internal; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $total_external; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($total_attendees); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo ($user_type_filter !== 'internal') ? '7' : '6'; ?>" class="px-6 py-4 text-center text-sm text-gray-500">No data found for the selected period</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Mobile menu toggle
    document.getElementById('menu-button').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
    });
    
    // Initialize chart
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('reportChart').getContext('2d');
        
        <?php if (!empty($chart_data)): ?>
            const chartData = {
                labels: <?php echo json_encode($chart_data['labels']); ?>,
                datasets: [
                    <?php if (isset($chart_data['internal_data']) && isset($chart_data['external_data'])): ?>
                    {
                        label: 'Internal',
                        data: <?php echo json_encode($chart_data['internal_data']); ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'External',
                        data: <?php echo json_encode($chart_data['external_data']); ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                    <?php else: ?>
                    {
                        label: '<?php 
                            if ($report_type === 'usage') echo 'Booking Count';
                            elseif ($report_type === 'utilization') echo 'Utilization Rate (%)';
                            elseif ($report_type === 'status') echo 'Booking Count by Status';
                        ?>',
                        data: <?php echo json_encode($chart_data['data'] ?? []); ?>,
                        backgroundColor: [
                            'rgba(54, 162, 235, 0.2)',
                            'rgba(255, 99, 132, 0.2)',
                        'rgba(255, 206, 86, 0.2)',
                        'rgba(75, 192, 192, 0.2)',
                        'rgba(153, 102, 255, 0.2)',
                        'rgba(255, 159, 64, 0.2)'
                    ],
                    borderColor: [
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)'
                    ],
                    borderWidth: 1
                    }
                    <?php endif; ?>
                ]
            };
            
            const chartConfig = {
                type: '<?php echo ($report_type === 'status') ? 'pie' : 'bar'; ?>',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    <?php if ($report_type !== 'status'): ?>
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                    <?php endif; ?>
                }
            };
            
            new Chart(ctx, chartConfig);
        <?php endif; ?>
    });
    
    // Print styles
    window.onbeforeprint = function() {
        document.querySelectorAll('button, a').forEach(function(el) {
            el.style.display = 'none';
        });
    };
    
    window.onafterprint = function() {
        document.querySelectorAll('button, a').forEach(function(el) {
            el.style.display = '';
        });
    };
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
