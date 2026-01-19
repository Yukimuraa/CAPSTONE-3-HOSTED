<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or secretary
require_admin();

// Get user data based on active user type (admin or secretary)
$active_type = $_SESSION['active_user_type'];
$user_id = $_SESSION['user_sessions'][$active_type]['user_id'];
$user_name = $_SESSION['user_sessions'][$active_type]['user_name'];

$page_title = "Admin Dashboard - CHMSU BAO";
$base_url = "..";

// Create oval_event_types table if it doesn't exist
$create_oval_event_types_table = "CREATE TABLE IF NOT EXISTS oval_event_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$conn->query($create_oval_event_types_table);

// Insert default event types if table is empty
$check_oval_defaults = $conn->query("SELECT COUNT(*) as count FROM oval_event_types");
if ($check_oval_defaults && $check_oval_defaults->fetch_assoc()['count'] == 0) {
    $default_oval_types = [
        'Outdoor concerts',
        'Soccer/Football matches',
        'Rugby matches',
        'Frisbee',
        'Other'
    ];
    $stmt = $conn->prepare("INSERT INTO oval_event_types (name) VALUES (?)");
    foreach ($default_oval_types as $type) {
        $stmt->bind_param("s", $type);
        $stmt->execute();
    }
}

// Handle form submissions for oval field management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize_input($_POST['action']);
    
    // Handle oval booking status updates
    if (in_array($action, ['approve_oval', 'reject_oval', 'reschedule_oval', 'cancel_oval']) && isset($_POST['booking_id'])) {
        $booking_id = sanitize_input($_POST['booking_id']);
        $admin_remarks = sanitize_input($_POST['admin_remarks'] ?? '');
        $new_date = sanitize_input($_POST['new_date'] ?? '');
        $new_start_time = sanitize_input($_POST['new_start_time'] ?? '');
        $new_end_time = sanitize_input($_POST['new_end_time'] ?? '');
        $or_number = sanitize_input($_POST['or_number'] ?? '');
        
        // Check if reservation exists (check both facility_type and booking_id pattern)
        $check_stmt = $conn->prepare("SELECT b.*, u.user_type FROM bookings b LEFT JOIN user_accounts u ON b.user_id = u.id WHERE b.booking_id = ? AND (b.facility_type = 'oval' OR b.booking_id LIKE 'OVAL-%')");
        $check_stmt->bind_param("s", $booking_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $booking = $check_result->fetch_assoc();
            $new_status = '';
            
            switch ($action) {
                case 'approve_oval':
                    $new_status = 'confirmed';
                    // Require OR number for external users
                    if (!empty($booking['user_type']) && $booking['user_type'] === 'external') {
                        if (empty($or_number)) {
                            $_SESSION['error'] = "OR number is required for external users.";
                            header("Location: dashboard.php");
                            exit();
                        } elseif (!preg_match('/^[0-9]{7}$/', $or_number)) {
                            $_SESSION['error'] = "OR number must be exactly 7 digits.";
                            header("Location: dashboard.php");
                            exit();
                        }
                    }
                    break;
                case 'reject_oval':
                    $new_status = 'rejected';
                    break;
                case 'reschedule_oval':
                    $new_status = 'rescheduled';
                    if (empty($new_date) || empty($new_start_time) || empty($new_end_time)) {
                        $_SESSION['error'] = "Date and time are required for rescheduling.";
                        header("Location: dashboard.php");
                        exit();
                    }
                    // Validate that rescheduled date is at least 3 days in advance
                    $reschedule_date = new DateTime($new_date);
                    $today = new DateTime();
                    $today->setTime(0, 0, 0);
                    $min_reschedule_date = clone $today;
                    $min_reschedule_date->modify('+3 days');
                    
                    if ($reschedule_date < $min_reschedule_date) {
                        $_SESSION['error'] = "Rescheduled date must be at least 3 days in advance. The earliest available date is " . $min_reschedule_date->format('F j, Y') . ".";
                        header("Location: dashboard.php");
                        exit();
                    }
                    break;
                case 'cancel_oval':
                    $new_status = 'cancelled';
                    break;
            }
            
            // Update reservation status
            if ($action === 'reschedule_oval') {
                // Ensure status is set correctly - first check if 'rescheduled' is allowed in ENUM, if not use VARCHAR update
                $new_status = 'rescheduled'; // Explicitly set to ensure it's not empty
                
                // Try to update status column - if it's ENUM and doesn't support 'rescheduled', we'll handle it
                // First, ensure the status column can accept 'rescheduled' by checking and modifying if needed
                $check_enum = $conn->query("SHOW COLUMNS FROM bookings WHERE Field = 'status'");
                if ($check_enum && $enum_row = $check_enum->fetch_assoc()) {
                    $type = strtolower($enum_row['Type']);
                    // If it's ENUM and doesn't include 'rescheduled', we need to alter it
                    if (strpos($type, 'enum') !== false && strpos($type, 'rescheduled') === false) {
                        // Alter the ENUM to include 'rescheduled' and 'confirmed'
                        $alter_query = "ALTER TABLE bookings MODIFY COLUMN status ENUM('pending', 'confirmed', 'approved', 'rejected', 'rescheduled', 'cancelled', 'canceled', 'unavailable') DEFAULT 'pending'";
                        $alter_result = $conn->query($alter_query);
                        if (!$alter_result && $conn->error) {
                            // If ENUM alteration fails, try converting to VARCHAR to allow any status value
                            error_log("Failed to alter ENUM for bookings.status: " . $conn->error);
                            $alter_varchar = "ALTER TABLE bookings MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'";
                            $conn->query($alter_varchar);
                        }
                    }
                }
                
                $update_stmt = $conn->prepare("UPDATE bookings SET status = ?, date = ?, start_time = ?, end_time = ?, additional_info = JSON_SET(COALESCE(additional_info, '{}'), '$.admin_remarks', ?) WHERE booking_id = ?");
                if (!$update_stmt) {
                    $_SESSION['error'] = "Database error: " . $conn->error;
                    header("Location: dashboard.php");
                    exit();
                }
                $update_stmt->bind_param("ssssss", $new_status, $new_date, $new_start_time, $new_end_time, $admin_remarks, $booking_id);
            } elseif ($action === 'approve_oval') {
                // For approval, include OR number if provided
                $update_stmt = $conn->prepare("UPDATE bookings SET status = ?, or_number = ?, additional_info = JSON_SET(COALESCE(additional_info, '{}'), '$.admin_remarks', ?) WHERE booking_id = ?");
                if (!$update_stmt) {
                    $_SESSION['error'] = "Database error: " . $conn->error;
                    header("Location: dashboard.php");
                    exit();
                }
                $update_stmt->bind_param("ssss", $new_status, $or_number, $admin_remarks, $booking_id);
            } else {
                $update_stmt = $conn->prepare("UPDATE bookings SET status = ?, additional_info = JSON_SET(COALESCE(additional_info, '{}'), '$.admin_remarks', ?) WHERE booking_id = ?");
                if (!$update_stmt) {
                    $_SESSION['error'] = "Database error: " . $conn->error;
                    header("Location: dashboard.php");
                    exit();
                }
                $update_stmt->bind_param("sss", $new_status, $admin_remarks, $booking_id);
            }
            
            if ($update_stmt->execute()) {
                // Verify the update was successful by checking affected rows
                if ($update_stmt->affected_rows > 0) {
                    // Double-check: Verify status and date were actually updated correctly
                    if ($action === 'reschedule_oval') {
                        $verify_stmt = $conn->prepare("SELECT status, date FROM bookings WHERE booking_id = ?");
                        $verify_stmt->bind_param("s", $booking_id);
                        $verify_stmt->execute();
                        $verify_result = $verify_stmt->get_result();
                        if ($verify_row = $verify_result->fetch_assoc()) {
                            $actual_status = $verify_row['status'];
                            $actual_date = $verify_row['date'];
                            
                            // Verify both status and date are correct
                            $needs_fix = false;
                            if ($actual_status !== 'rescheduled') {
                                $needs_fix = true;
                            }
                            if ($actual_date !== $new_date) {
                                $needs_fix = true;
                            }
                            
                            // If either is wrong, force update both
                            if ($needs_fix) {
                                $force_update = $conn->prepare("UPDATE bookings SET status = 'rescheduled', date = ? WHERE booking_id = ?");
                                $force_update->bind_param("ss", $new_date, $booking_id);
                                $force_update->execute();
                                
                                // If still failing, the ENUM definitely needs to be altered
                                if ($force_update->affected_rows == 0) {
                                    // Try altering the column to VARCHAR to allow any status
                                    @$conn->query("ALTER TABLE bookings MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
                                    // Try update again
                                    $force_update->execute();
                                }
                            }
                        }
                    }
                    
                    $_SESSION['success'] = "Oval field reservation has been " . ucfirst(str_replace('_oval', '', $action)) . "d successfully.";
                    
                    // Send notification to user
                    require_once '../includes/notification_functions.php';
                    $booking_user_id = $booking['user_id'];
                    $date_formatted = date('F j, Y', strtotime($action === 'reschedule_oval' ? $new_date : $booking['date']));
                    
                    if ($new_status === 'confirmed') {
                        create_notification($booking_user_id, "Oval Field Reservation Approved", "Your oval field reservation (ID: {$booking_id}) for {$date_formatted} has been approved!", "success", "external/oval.php");
                    } elseif ($new_status === 'rejected') {
                        $reason = !empty($admin_remarks) ? " Reason: {$admin_remarks}" : "";
                        create_notification($booking_user_id, "Oval Field Reservation Rejected", "Your oval field reservation (ID: {$booking_id}) for {$date_formatted} has been rejected.{$reason}", "error", "external/oval.php");
                    } elseif ($new_status === 'rescheduled') {
                        create_notification($booking_user_id, "Oval Field Reservation Rescheduled", "Your oval field reservation (ID: {$booking_id}) has been rescheduled to {$date_formatted}.", "info", "external/oval.php");
                    } elseif ($new_status === 'cancelled') {
                        create_notification($booking_user_id, "Oval Field Reservation Cancelled", "Your oval field reservation (ID: {$booking_id}) for {$date_formatted} has been cancelled.", "error", "external/oval.php");
                    }
                } else {
                    $_SESSION['error'] = "No rows were updated. The reservation may not exist or the data is the same.";
                }
            } else {
                $_SESSION['error'] = "Error updating reservation: " . $conn->error;
            }
        } else {
            $_SESSION['error'] = "Reservation not found";
        }
        
        header("Location: dashboard.php");
        exit();
    }
    
    // Handle oval event type management
    if ($action === 'add_oval_event_type') {
        $event_type_name = sanitize_input($_POST['event_type_name'] ?? '');
        if (empty($event_type_name)) {
            $_SESSION['error'] = "Event type name is required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO oval_event_types (name) VALUES (?)");
            $stmt->bind_param("s", $event_type_name);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Event type added successfully.";
            } else {
                $_SESSION['error'] = "Error adding event type: " . $conn->error;
            }
        }
        header("Location: dashboard.php");
        exit();
    }
    
    if ($action === 'update_oval_event_type' && isset($_POST['event_type_id'])) {
        $event_type_id = (int)$_POST['event_type_id'];
        $event_type_name = sanitize_input($_POST['event_type_name'] ?? '');
        if (empty($event_type_name)) {
            $_SESSION['error'] = "Event type name is required.";
        } else {
            $stmt = $conn->prepare("UPDATE oval_event_types SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $event_type_name, $event_type_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Event type updated successfully.";
            } else {
                $_SESSION['error'] = "Error updating event type: " . $conn->error;
            }
        }
        header("Location: dashboard.php");
        exit();
    }
    
    if ($action === 'delete_oval_event_type' && isset($_POST['event_type_id'])) {
        $event_type_id = (int)$_POST['event_type_id'];
        $check_stmt = $conn->prepare("SELECT name FROM oval_event_types WHERE id = ?");
        $check_stmt->bind_param("i", $event_type_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $event_type = $check_result->fetch_assoc();
            if ($event_type['name'] === 'Other') {
                $_SESSION['error'] = "Cannot delete 'Other' event type.";
            } else {
                $stmt = $conn->prepare("DELETE FROM oval_event_types WHERE id = ?");
                $stmt->bind_param("i", $event_type_id);
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Event type deleted successfully.";
                } else {
                    $_SESSION['error'] = "Error deleting event type: " . $conn->error;
                }
            }
        } else {
            $_SESSION['error'] = "Event type not found.";
        }
        header("Location: dashboard.php");
        exit();
    }
    
    if ($action === 'toggle_oval_event_type_status' && isset($_POST['event_type_id'])) {
        $event_type_id = (int)$_POST['event_type_id'];
        $stmt = $conn->prepare("UPDATE oval_event_types SET is_active = NOT is_active WHERE id = ?");
        $stmt->bind_param("i", $event_type_id);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Event type status updated successfully.";
        } else {
            $_SESSION['error'] = "Error updating event type status: " . $conn->error;
        }
        header("Location: dashboard.php");
        exit();
    }
}

// Get counts for dashboard
$pending_orders_query = "SELECT COUNT(*) as count FROM orders WHERE status = 'pending'";
$pending_result = $conn->query($pending_orders_query);
$pending_count = $pending_result->fetch_assoc()['count'];

$inventory_query = "SELECT SUM(quantity) as count FROM inventory";
$inventory_result = $conn->query($inventory_query);
$inventory_count = $inventory_result->fetch_assoc()['count'];

$bookings_query = "SELECT COUNT(*) as count FROM bookings WHERE date >= CURDATE()";
$bookings_result = $conn->query($bookings_query);
$upcoming_bookings = $bookings_result->fetch_assoc()['count'];

$users_query = "SELECT COUNT(*) as count FROM user_accounts";
$users_result = $conn->query($users_query);
$users_count = $users_result->fetch_assoc()['count'];

// Get recent orders
$recent_orders_query = "SELECT o.*, u.name as user_name, u.user_type, u.role, i.name as item_name 
                         FROM orders o 
                         JOIN user_accounts u ON o.user_id = u.id 
                         JOIN inventory i ON o.inventory_id = i.id 
                         ORDER BY o.created_at DESC LIMIT 5";
$recent_orders = $conn->query($recent_orders_query);

// Get upcoming bookings
$upcoming_bookings_query = "SELECT b.*, u.name as user_name, u.organization 
                           FROM bookings b 
                           JOIN user_accounts u ON b.user_id = u.id 
                           WHERE b.date >= CURDATE() 
                           ORDER BY b.date ASC LIMIT 5";
$upcoming_bookings_result = $conn->query($upcoming_bookings_query);

// Get oval field bookings with filters
$oval_status_filter = isset($_GET['oval_status']) ? sanitize_input($_GET['oval_status']) : '';
$oval_date_filter = isset($_GET['oval_date']) ? sanitize_input($_GET['oval_date']) : '';

$oval_bookings_query = "SELECT b.booking_id, b.user_id, b.facility_type, b.date, b.start_time, b.end_time, 
                        b.purpose, b.attendees, b.status, b.additional_info, b.created_at, b.or_number,
                        COALESCE(u.name, 'Unknown User') as user_name, 
                        u.email as user_email, 
                        u.organization 
                        FROM bookings b
                        LEFT JOIN user_accounts u ON b.user_id = u.id
                        WHERE (b.facility_type = 'oval' OR b.booking_id LIKE 'OVAL-%')";

$oval_params = [];
$oval_types = "";

if (!empty($oval_status_filter)) {
    $oval_bookings_query .= " AND b.status = ?";
    $oval_params[] = $oval_status_filter;
    $oval_types .= "s";
}

if (!empty($oval_date_filter)) {
    $oval_bookings_query .= " AND b.date = ?";
    $oval_params[] = $oval_date_filter;
    $oval_types .= "s";
}

$oval_bookings_query .= " ORDER BY b.date DESC, b.created_at DESC";

$oval_stmt = $conn->prepare($oval_bookings_query);
if (!empty($oval_params)) {
    $oval_stmt->bind_param($oval_types, ...$oval_params);
}
$oval_stmt->execute();
$oval_bookings_result = $oval_stmt->get_result();

// Get oval event types
$oval_event_types_query = "SELECT * FROM oval_event_types ORDER BY name ASC";
$oval_event_types_result = $conn->query($oval_event_types_query);
?>


<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>
                <div class="flex items-center gap-3">
                    <?php require_once '../includes/notification_bell.php'; ?>
                    <span class="text-gray-700 hidden sm:inline"><?php echo $user_name; ?></span>
                    <button class="md:hidden rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-500" id="menu-button">
                        <span class="sr-only">Open menu</span>
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Main content -->
        <main class="flex-1 overflow-y-auto p-4">
            <div class="max-w-7xl mx-auto">
                <!-- Stats cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">Pending Orders</p>
                                <h3 class="text-2xl font-bold"><?php echo $pending_count; ?></h3>
                            </div>
                            <div class="bg-emerald-100 p-3 rounded-full">
                                <i class="fas fa-shopping-cart text-emerald-600"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">Inventory Items</p>
                                <h3 class="text-2xl font-bold"><?php echo $inventory_count; ?></h3>
                            </div>
                            <div class="bg-blue-100 p-3 rounded-full">
                                <i class="fas fa-box text-blue-600"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">Upcoming reservation</p>
                                <h3 class="text-2xl font-bold"><?php echo $upcoming_bookings; ?></h3>
                            </div>
                            <div class="bg-purple-100 p-3 rounded-full">
                                <i class="fas fa-calendar-alt text-purple-600"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 text-sm">Active Users</p>
                                <h3 class="text-2xl font-bold"><?php echo $users_count; ?></h3>
                            </div>
                            <div class="bg-yellow-100 p-3 rounded-full">
                                <i class="fas fa-users text-yellow-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent orders -->
                <div class="bg-white rounded-lg shadow mb-6">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <h3 class="text-lg font-medium text-gray-900">Recent Orders</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($recent_orders->num_rows > 0): ?>
                                    <?php while ($order = $recent_orders->fetch_assoc()): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['order_id']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($order['item_name']); ?>
                                                <?php if (!empty($order['size'])): ?>
                                                    <span class="text-xs text-gray-400">(<?php echo htmlspecialchars($order['size']); ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($order['user_name']); ?>
                                                <?php 
                                                // Display role for student type users, otherwise display user_type
                                                if ($order['user_type'] === 'student' && !empty($order['role'])) {
                                                    $roleLabels = ['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'];
                                                    $displayRole = $roleLabels[$order['role']] ?? 'Student';
                                                    echo '<span class="text-xs text-gray-400">(' . $displayRole . ')</span>';
                                                } else {
                                                    echo '<span class="text-xs text-gray-400">(' . ucfirst($order['user_type']) . ')</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $order['quantity']; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">₱<?php echo number_format($order['total_price'], 2); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($order['status'] == 'pending'): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                                                <?php elseif ($order['status'] == 'completed'): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Completed</span>
                                                <?php else: ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Cancelled</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No recent orders found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 py-3 bg-gray-50 text-right sm:px-6">
                        <a href="orders.php" class="text-sm font-medium text-emerald-600 hover:text-emerald-500">View all orders</a>
                    </div>
                </div>
                
                <!-- Upcoming bookings -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <h3 class="text-lg font-medium text-gray-900">Upcoming reservation</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reservation ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requester</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($upcoming_bookings_result->num_rows > 0): ?>
                                    <?php while ($booking = $upcoming_bookings_result->fetch_assoc()): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $booking['booking_id']; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $booking['facility_type']; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo $booking['user_name']; ?>
                                                <?php if (!empty($booking['organization'])): ?>
                                                    <span class="text-xs text-gray-400">(<?php echo $booking['organization']; ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo format_date($booking['date']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($booking['status'] == 'pending'): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                                                <?php elseif ($booking['status'] == 'confirmed'): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Confirmed</span>
                                                <?php else: ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Rejected</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No upcoming reservation found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- <div class="px-4 py-3 bg-gray-50 text-right sm:px-6">
                        <a href="reservation.php" class="text-sm font-medium text-emerald-600 hover:text-emerald-500">View all reservation</a>
                    </div> -->
                </div>
                
                <!-- Oval Field Management Section -->
                <div class="bg-white rounded-lg shadow mt-6">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Oval Field Management</h3>
                                <p class="mt-1 text-sm text-gray-500">Manage oval field reservations and event types</p>
                            </div>
                            <button type="button" onclick="document.getElementById('eventTypesSection').classList.toggle('hidden')" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-cog mr-2"></i>Manage Event Types
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                        <form method="GET" action="dashboard.php" class="flex flex-wrap gap-4">
                            <div>
                                <label for="oval_status" class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                                <select id="oval_status" name="oval_status" class="rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $oval_status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $oval_status_filter === 'confirmed' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $oval_status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="rescheduled" <?php echo $oval_status_filter === 'rescheduled' ? 'selected' : ''; ?>>Rescheduled</option>
                                    <option value="cancelled" <?php echo $oval_status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div>
                                <label for="oval_date" class="block text-xs font-medium text-gray-700 mb-1">Date</label>
                                <input type="date" id="oval_date" name="oval_date" value="<?php echo htmlspecialchars($oval_date_filter); ?>" class="rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500">
                                    <i class="fas fa-filter mr-1"></i>Filter
                                </button>
                                <a href="dashboard.php" class="ml-2 px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                    Clear
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Oval Bookings Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Booking ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purpose</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendees</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($oval_bookings_result->num_rows > 0): ?>
                                    <?php while ($booking = $oval_bookings_result->fetch_assoc()): ?>
                                        <?php
                                        $additional_info = [];
                                        if (!empty($booking['additional_info'])) {
                                            try {
                                                $additional_info = json_decode($booking['additional_info'], true) ?? [];
                                            } catch (Exception $e) {
                                                $additional_info = [];
                                            }
                                        }
                                        $admin_remarks = $additional_info['admin_remarks'] ?? '';
                                        ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($booking['booking_id']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($booking['user_name']); ?>
                                                <?php if (!empty($booking['organization'])): ?>
                                                    <span class="text-xs text-gray-400">(<?php echo htmlspecialchars($booking['organization']); ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo format_date($booking['date']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('g:i A', strtotime($booking['start_time'])); ?> - <?php echo date('g:i A', strtotime($booking['end_time'])); ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($booking['purpose'] ?? 'N/A'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $booking['attendees'] ?? 'N/A'; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php
                                                // Get status - explicitly check status field
                                                $status_raw = $booking['status'] ?? null;
                                                $status = '';
                                                
                                                // Debug: Log status if needed (remove in production)
                                                // error_log("Booking ID: " . $booking['booking_id'] . ", Status: " . var_export($status_raw, true));
                                                
                                                if (!empty($status_raw)) {
                                                    $status = trim(strtolower($status_raw));
                                                } else {
                                                    $status = 'pending'; // Default if status is missing
                                                }
                                                
                                                $status_classes = [
                                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                                    'confirmed' => 'bg-green-100 text-green-800',
                                                    'rejected' => 'bg-red-100 text-red-800',
                                                    'rescheduled' => 'bg-blue-100 text-blue-800',
                                                    'cancelled' => 'bg-gray-100 text-gray-800',
                                                    'canceled' => 'bg-gray-100 text-gray-800'
                                                ];
                                                $status_labels = [
                                                    'pending' => 'Pending',
                                                    'confirmed' => 'Approved',
                                                    'rejected' => 'Rejected',
                                                    'rescheduled' => 'Rescheduled',
                                                    'cancelled' => 'Cancelled',
                                                    'canceled' => 'Cancelled'
                                                ];
                                                $status_class = isset($status_classes[$status]) ? $status_classes[$status] : 'bg-gray-100 text-gray-800';
                                                $status_label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars($status_label); ?></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <button type="button" onclick="openOvalViewModal(<?php echo htmlspecialchars(json_encode($booking)); ?>)" class="text-blue-600 hover:text-blue-900 mr-3">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($status === 'pending'): ?>
                                                    <button type="button" onclick="openOvalApproveModal('<?php echo htmlspecialchars($booking['booking_id']); ?>', '<?php echo htmlspecialchars($booking['user_type'] ?? 'external'); ?>')" class="text-green-600 hover:text-green-900 mr-3">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button type="button" onclick="openOvalRejectModal('<?php echo htmlspecialchars($booking['booking_id']); ?>')" class="text-red-600 hover:text-red-900 mr-3">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (in_array($status, ['pending', 'confirmed', 'rescheduled'])): ?>
                                                    <button type="button" onclick="openOvalRescheduleModal('<?php echo htmlspecialchars($booking['booking_id']); ?>', '<?php echo $booking['date']; ?>', '<?php echo $booking['start_time']; ?>', '<?php echo $booking['end_time']; ?>')" class="text-blue-600 hover:text-blue-900 mr-3">
                                                        <i class="fas fa-calendar-alt"></i> Reschedule
                                                    </button>
                                                    <button type="button" onclick="openOvalCancelModal('<?php echo htmlspecialchars($booking['booking_id']); ?>')" class="text-gray-600 hover:text-gray-900">
                                                        <i class="fas fa-ban"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">No oval field bookings found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Event Types Management Section -->
                    <div id="eventTypesSection" class="hidden px-4 py-5 border-t border-gray-200">
                        <h4 class="text-md font-medium text-gray-900 mb-4">Manage Purpose/Event Types</h4>
                        <div class="mb-4">
                            <button type="button" onclick="openAddOvalEventTypeModal()" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500">
                                <i class="fas fa-plus mr-2"></i>Add Event Type
                            </button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if ($oval_event_types_result->num_rows > 0): ?>
                                        <?php while ($event_type = $oval_event_types_result->fetch_assoc()): ?>
                                            <?php
                                            $status_class = $event_type['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                                            $status_text = $event_type['is_active'] ? 'Active' : 'Inactive';
                                            ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $event_type['id']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($event_type['name']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button type="button" onclick="openEditOvalEventTypeModal(<?php echo htmlspecialchars(json_encode($event_type)); ?>)" class="text-blue-600 hover:text-blue-900 mr-3">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <?php if ($event_type['name'] !== 'Other'): ?>
                                                        <button type="button" onclick="openDeleteOvalEventTypeModal(<?php echo $event_type['id']; ?>, '<?php echo htmlspecialchars($event_type['name']); ?>')" class="text-red-600 hover:text-red-900 mr-3">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" onclick="toggleOvalEventTypeStatus(<?php echo $event_type['id']; ?>)" class="text-<?php echo $event_type['is_active'] ? 'yellow' : 'green'; ?>-600 hover:text-<?php echo $event_type['is_active'] ? 'yellow' : 'green'; ?>-900">
                                                        <?php echo $event_type['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No event types found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- View Oval Booking Modal -->
<div id="ovalViewModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Oval Field Reservation Details</h3>
            <button type="button" onclick="closeOvalModal('ovalViewModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mt-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500">Booking ID</p>
                    <p id="oval-view-booking-id" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Status</p>
                    <p id="oval-view-status" class="text-base"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Date</p>
                    <p id="oval-view-date" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Time</p>
                    <p id="oval-view-time" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">User</p>
                    <p id="oval-view-user" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Attendees</p>
                    <p id="oval-view-attendees" class="text-base text-gray-900"></p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-sm font-medium text-gray-500">Purpose/Event Type</p>
                    <p id="oval-view-purpose" class="text-base text-gray-900"></p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-sm font-medium text-gray-500">Admin Remarks</p>
                    <p id="oval-view-remarks" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Created At</p>
                    <p id="oval-view-created" class="text-base text-gray-900"></p>
                </div>
                <div id="oval-additional-info-container" class="md:col-span-2">
                    <p class="text-sm font-medium text-gray-500">Additional Information</p>
                    <div id="oval-additional-info" class="text-base text-gray-900 space-y-2"></div>
                </div>
                <div id="oval-letter-container" class="md:col-span-2 hidden">
                    <p class="text-sm font-medium text-gray-500 mb-2">Letter from President</p>
                    <div class="border border-gray-300 rounded-lg p-3 bg-gray-50">
                        <a id="oval-letter-link" href="#" target="_blank" class="flex items-center text-blue-600 hover:text-blue-800 mb-2">
                            <i class="fas fa-file-pdf mr-2"></i>
                            <span id="oval-letter-text">View Letter</span>
                        </a>
                        <div id="oval-letter-image" class="mt-3 hidden">
                            <img id="oval-letter-img" src="" alt="Letter" class="max-w-full h-auto rounded border border-gray-300" style="max-height: 300px; object-fit: contain;">
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button type="button" onclick="closeOvalModal('ovalViewModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Oval Booking Modal -->
<div id="ovalApproveModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Approve Oval Field Reservation</h3>
            <button type="button" onclick="closeOvalModal('ovalApproveModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form action="dashboard.php" method="POST" class="mt-4" id="ovalApproveForm">
            <input type="hidden" name="action" value="approve_oval">
            <input type="hidden" id="oval-approve-booking-id" name="booking_id" value="">
            <input type="hidden" id="oval-approve-user-type" name="user_type" value="">
            
            <!-- OR Number Field (External Users Only) -->
            <div id="oval-or-number-container" class="mb-4 hidden">
                <label for="oval-approve-or-number" class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-receipt text-blue-600 mr-2"></i>Official Receipt (OR) No: <span class="text-red-500">*</span>
                </label>
                <input type="text" id="oval-approve-or-number" name="or_number" 
                       pattern="[0-9]{7}"
                       maxlength="7"
                       inputmode="numeric"
                       onkeypress="return (event.charCode >= 48 && event.charCode <= 57)"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 7)"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500" 
                       placeholder="Enter OR Number (7 digits)">
                <p class="mt-1 text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>Enter the OR number provided by the cashier (7 digits only)
                </p>
            </div>
            
            <div class="mb-4">
                <label for="oval-approve-remarks" class="block text-sm font-medium text-gray-700 mb-1">Remarks (Optional)</label>
                <textarea id="oval-approve-remarks" name="admin_remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500" placeholder="Add any notes or instructions for the user"></textarea>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeOvalModal('ovalApproveModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Approve Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Oval Booking Modal -->
<div id="ovalRejectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Reject Oval Field Reservation</h3>
            <button type="button" onclick="closeOvalModal('ovalRejectModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form action="dashboard.php" method="POST" class="mt-4">
            <input type="hidden" name="action" value="reject_oval">
            <input type="hidden" id="oval-reject-booking-id" name="booking_id" value="">
            
            <div class="mb-4">
                <label for="oval-reject-remarks" class="block text-sm font-medium text-gray-700 mb-1">Reason for Rejection</label>
                <textarea id="oval-reject-remarks" name="admin_remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Explain why the reservation is being rejected" required></textarea>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeOvalModal('ovalRejectModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Reject Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reschedule Oval Booking Modal -->
<div id="ovalRescheduleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Reschedule Oval Field Reservation</h3>
            <button type="button" onclick="closeOvalModal('ovalRescheduleModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form action="dashboard.php" method="POST" class="mt-4">
            <input type="hidden" name="action" value="reschedule_oval">
            <input type="hidden" id="oval-reschedule-booking-id" name="booking_id" value="">
            
            <div class="mb-4">
                <label for="oval-reschedule-date" class="block text-sm font-medium text-gray-700 mb-1">New Date</label>
                <input type="date" id="oval-reschedule-date" name="new_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" min="<?php echo date('Y-m-d', strtotime('+3 days')); ?>">
                <p class="mt-1 text-xs text-gray-500">Or select from calendar below (minimum 3 days in advance)</p>
            </div>
            
            <!-- Availability Calendar -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Available Dates Calendar</label>
                <div id="oval-reschedule-calendar" class="bg-white p-2 rounded-lg border border-gray-300"></div>
                <div id="oval-reschedule-availability" class="mt-3 hidden">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <h4 class="text-sm font-semibold text-blue-800 mb-2">Availability for <span id="oval-avail-date" class="font-normal text-blue-600"></span></h4>
                        <div id="oval-loading-indicator" class="text-center py-2 hidden">
                            <div class="inline-block animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                            <p class="mt-1 text-xs text-gray-600">Checking availability...</p>
                        </div>
                        <div id="oval-availability-info" class="text-sm text-gray-700"></div>
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="oval-reschedule-start-time" class="block text-sm font-medium text-gray-700 mb-1">New Start Time</label>
                    <input type="time" id="oval-reschedule-start-time" name="new_start_time" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label for="oval-reschedule-end-time" class="block text-sm font-medium text-gray-700 mb-1">New End Time</label>
                    <input type="time" id="oval-reschedule-end-time" name="new_end_time" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            
            <div class="mb-4">
                <label for="oval-reschedule-remarks" class="block text-sm font-medium text-gray-700 mb-1">Remarks (Optional)</label>
                <textarea id="oval-reschedule-remarks" name="admin_remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Add any notes about the rescheduling"></textarea>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeOvalModal('ovalRescheduleModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Reschedule Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Oval Booking Modal -->
<div id="ovalCancelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Cancel Oval Field Reservation</h3>
            <button type="button" onclick="closeOvalModal('ovalCancelModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form action="dashboard.php" method="POST" class="mt-4">
            <input type="hidden" name="action" value="cancel_oval">
            <input type="hidden" id="oval-cancel-booking-id" name="booking_id" value="">
            
            <div class="mb-4">
                <label for="oval-cancel-remarks" class="block text-sm font-medium text-gray-700 mb-1">Reason for Cancellation</label>
                <textarea id="oval-cancel-remarks" name="admin_remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-500" placeholder="Explain why the reservation is being cancelled" required></textarea>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeOvalModal('ovalCancelModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Back
                </button>
                <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Cancel Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Oval Event Type Modal -->
<div id="addOvalEventTypeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Add New Event Type</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeAddOvalEventTypeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="add_oval_event_type">
            <div class="mb-4">
                <label for="add-oval-event-type-name" class="block text-sm font-medium text-gray-700 mb-1">Event Type Name</label>
                <input type="text" id="add-oval-event-type-name" name="event_type_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50" placeholder="e.g., Soccer Tournament">
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeAddOvalEventTypeModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-emerald-600 text-white py-2 px-4 rounded-md hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    Add
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Oval Event Type Modal -->
<div id="editOvalEventTypeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Edit Event Type</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeEditOvalEventTypeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="update_oval_event_type">
            <input type="hidden" name="event_type_id" id="edit-oval-event-type-id">
            <div class="mb-4">
                <label for="edit-oval-event-type-name" class="block text-sm font-medium text-gray-700 mb-1">Event Type Name</label>
                <input type="text" id="edit-oval-event-type-name" name="event_type_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeEditOvalEventTypeModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-emerald-600 text-white py-2 px-4 rounded-md hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Oval Event Type Modal -->
<div id="deleteOvalEventTypeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Delete Event Type</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeDeleteOvalEventTypeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="delete_oval_event_type">
            <input type="hidden" name="event_type_id" id="delete-oval-event-type-id">
            <div class="mb-4">
                <p class="text-sm text-gray-700">Are you sure you want to delete the event type <strong id="delete-oval-event-type-name"></strong>?</p>
                <p class="text-xs text-gray-500 mt-2">This action cannot be undone.</p>
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeDeleteOvalEventTypeModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.getElementById('menu-button').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
    });
    
    // Oval Field Management Modal Functions
    function openOvalViewModal(booking) {
        // Parse booking if it's a string
        if (typeof booking === 'string') {
            booking = JSON.parse(booking);
        }
        
        document.getElementById('oval-view-booking-id').textContent = booking.booking_id || 'N/A';
        
        const statusElement = document.getElementById('oval-view-status');
        const status = (booking.status || booking.booking_status || 'pending').toLowerCase().trim();
        
        // Status labels mapping
        const statusLabels = {
            'pending': 'Pending',
            'confirmed': 'Approved',
            'rejected': 'Rejected',
            'rescheduled': 'Rescheduled',
            'cancelled': 'Cancelled',
            'canceled': 'Cancelled'
        };
        
        statusElement.textContent = statusLabels[status] || status.charAt(0).toUpperCase() + status.slice(1);
        
        // Set status color
        statusElement.className = 'text-base';
        if (status === 'pending') {
            statusElement.classList.add('text-yellow-600');
        } else if (status === 'confirmed') {
            statusElement.classList.add('text-green-600');
        } else if (status === 'rejected') {
            statusElement.classList.add('text-red-600');
        } else if (status === 'rescheduled') {
            statusElement.classList.add('text-blue-600');
        } else {
            statusElement.classList.add('text-gray-600');
        }
        
        const bookingDate = new Date(booking.date);
        document.getElementById('oval-view-date').textContent = bookingDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        
        const startTime = formatOvalTime(booking.start_time);
        const endTime = formatOvalTime(booking.end_time);
        document.getElementById('oval-view-time').textContent = startTime + ' - ' + endTime;
        
        document.getElementById('oval-view-user').textContent = booking.user_name + (booking.organization ? ` (${booking.organization})` : '');
        document.getElementById('oval-view-attendees').textContent = booking.attendees || 'N/A';
        document.getElementById('oval-view-purpose').textContent = booking.purpose || 'N/A';
        
        // Parse additional info for admin remarks
        let adminRemarks = '';
        try {
            const additionalInfo = booking.additional_info ? (typeof booking.additional_info === 'string' ? JSON.parse(booking.additional_info) : booking.additional_info) : {};
            adminRemarks = additionalInfo.admin_remarks || '';
        } catch (e) {
            adminRemarks = '';
        }
        document.getElementById('oval-view-remarks').textContent = adminRemarks || 'No remarks';
        
        const createdDate = new Date(booking.created_at);
        document.getElementById('oval-view-created').textContent = createdDate.toLocaleString();
        
        // Display additional information
        const additionalInfoContainer = document.getElementById('oval-additional-info-container');
        const additionalInfoElement = document.getElementById('oval-additional-info');
        additionalInfoElement.innerHTML = '';
        
        // Display letter if available
        const letterContainer = document.getElementById('oval-letter-container');
        const letterLink = document.getElementById('oval-letter-link');
        const letterText = document.getElementById('oval-letter-text');
        const letterImage = document.getElementById('oval-letter-image');
        const letterImg = document.getElementById('oval-letter-img');
        
        try {
            let additionalInfo = booking.additional_info;
            if (typeof additionalInfo === 'string') {
                additionalInfo = JSON.parse(additionalInfo);
            }
            
            if (additionalInfo && typeof additionalInfo === 'object') {
                let hasInfo = false;
                
                // Extract letter_path if it exists
                const letterPath = additionalInfo.letter_path || null;
                
                for (const [key, value] of Object.entries(additionalInfo)) {
                    // Skip admin_remarks and letter_path from additional info display
                    if (key !== 'admin_remarks' && key !== 'letter_path' && value) {
                        hasInfo = true;
                        const infoItem = document.createElement('p');
                        infoItem.innerHTML = `<span class="font-medium">${formatOvalLabel(key)}:</span> ${value}`;
                        additionalInfoElement.appendChild(infoItem);
                    }
                }
                
                additionalInfoContainer.style.display = hasInfo ? 'block' : 'none';
                
                // Display letter if available
                if (letterPath) {
                    const letterPathFull = '../' + letterPath;
                    letterLink.href = letterPathFull;
                    letterText.textContent = 'View Letter';
                    
                    // Check if it's an image to show preview
                    const isImage = /\.(jpg|jpeg|png|gif)$/i.test(letterPath);
                    if (isImage) {
                        letterImg.src = letterPathFull;
                        letterImage.classList.remove('hidden');
                        letterLink.querySelector('i').className = 'fas fa-file-image text-blue-500 mr-2';
                    } else {
                        letterImage.classList.add('hidden');
                        letterLink.querySelector('i').className = 'fas fa-file-pdf text-red-500 mr-2';
                    }
                    
                    letterContainer.classList.remove('hidden');
                } else {
                    letterContainer.classList.add('hidden');
                }
            } else {
                additionalInfoContainer.style.display = 'none';
                letterContainer.classList.add('hidden');
            }
        } catch (e) {
            additionalInfoContainer.style.display = 'none';
            letterContainer.classList.add('hidden');
        }
        
        document.getElementById('ovalViewModal').classList.remove('hidden');
    }
    
    function formatOvalTime(timeString) {
        if (!timeString) return '';
        const [hours, minutes] = timeString.split(':');
        const date = new Date();
        date.setHours(parseInt(hours));
        date.setMinutes(parseInt(minutes));
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    function formatOvalLabel(key) {
        return key.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
    }
    
    function openOvalApproveModal(bookingId, userType) {
        document.getElementById('oval-approve-booking-id').value = bookingId;
        document.getElementById('oval-approve-remarks').value = '';
        document.getElementById('oval-approve-or-number').value = '';
        document.getElementById('oval-approve-user-type').value = userType || 'external';
        
        const orContainer = document.getElementById('oval-or-number-container');
        const orInput = document.getElementById('oval-approve-or-number');
        
        // Show OR number field only for external users
        if (userType === 'external') {
            orContainer.classList.remove('hidden');
            orInput.required = true;
        } else {
            orContainer.classList.add('hidden');
            orInput.required = false;
        }
        
        document.getElementById('ovalApproveModal').classList.remove('hidden');
    }
    
    function openOvalRejectModal(bookingId) {
        document.getElementById('oval-reject-booking-id').value = bookingId;
        document.getElementById('oval-reject-remarks').value = '';
        document.getElementById('ovalRejectModal').classList.remove('hidden');
    }
    
    let ovalRescheduleCalendar = null;
    
    function openOvalRescheduleModal(bookingId, currentDate, currentStartTime, currentEndTime) {
        document.getElementById('oval-reschedule-booking-id').value = bookingId;
        document.getElementById('oval-reschedule-date').value = currentDate;
        document.getElementById('oval-reschedule-start-time').value = currentStartTime;
        document.getElementById('oval-reschedule-end-time').value = currentEndTime;
        document.getElementById('oval-reschedule-remarks').value = '';
        
        // Hide availability section initially
        document.getElementById('oval-reschedule-availability').classList.add('hidden');
        
        // Initialize or update calendar
        const calendarEl = document.getElementById('oval-reschedule-calendar');
        if (ovalRescheduleCalendar) {
            ovalRescheduleCalendar.destroy();
        }
        
        // Calculate minimum date (3 days from today - same rule as booking)
        const today = new Date();
        const minDate = new Date(today);
        minDate.setDate(today.getDate() + 3); // 3 days advance booking requirement
        
        // Fetch booked dates (excluding current booking) to disable them in calendar
        let bookedDatesArray = [];
        fetch(`get_oval_booked_dates.php?exclude_booking_id=${encodeURIComponent(bookingId)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.booked_dates) {
                    bookedDatesArray = data.booked_dates;
                }
                // Initialize calendar with disabled dates
                initializeRescheduleCalendar();
            })
            .catch(error => {
                console.error('Error fetching booked dates:', error);
                // Initialize calendar even if fetch fails
                initializeRescheduleCalendar();
            });
        
        function initializeRescheduleCalendar() {
            // Check if flatpickr is available
            if (typeof flatpickr !== 'undefined') {
                ovalRescheduleCalendar = flatpickr(calendarEl, {
                    inline: true,
                    minDate: minDate,
                    dateFormat: "Y-m-d",
                    defaultDate: currentDate,
                    disable: bookedDatesArray, // Disable all booked dates
                    onChange: function(selectedDates, dateStr) {
                        if (selectedDates.length > 0) {
                            document.getElementById('oval-reschedule-date').value = dateStr;
                            checkOvalAvailabilityForReschedule(dateStr, bookingId);
                        }
                    }
                });
            } else {
                // Fallback: load flatpickr if not available
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css';
                document.head.appendChild(link);
                
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/flatpickr';
                script.onload = function() {
                    ovalRescheduleCalendar = flatpickr(calendarEl, {
                        inline: true,
                        minDate: minDate,
                        dateFormat: "Y-m-d",
                        defaultDate: currentDate,
                        disable: bookedDatesArray, // Disable all booked dates
                        onChange: function(selectedDates, dateStr) {
                            if (selectedDates.length > 0) {
                                document.getElementById('oval-reschedule-date').value = dateStr;
                                checkOvalAvailabilityForReschedule(dateStr, bookingId);
                            }
                        }
                    });
                };
                document.head.appendChild(script);
            }
        }
        
        document.getElementById('ovalRescheduleModal').classList.remove('hidden');
    }
    
    function checkOvalAvailabilityForReschedule(dateStr, bookingId) {
        const availabilityDiv = document.getElementById('oval-reschedule-availability');
        const loadingIndicator = document.getElementById('oval-loading-indicator');
        const availabilityInfo = document.getElementById('oval-availability-info');
        const dateSpan = document.getElementById('oval-avail-date');
        
        // Show availability section
        availabilityDiv.classList.remove('hidden');
        loadingIndicator.classList.remove('hidden');
        availabilityInfo.innerHTML = '';
        
        // Format date for display
        const selectedDate = new Date(dateStr);
        const formattedDate = selectedDate.toLocaleDateString('en-US', { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
        dateSpan.textContent = formattedDate;
        
        // Fetch availability
        const url = `get_oval_availability.php?date=${encodeURIComponent(dateStr)}&exclude_booking_id=${encodeURIComponent(bookingId)}`;
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                loadingIndicator.classList.add('hidden');
                
                if (data.error) {
                    availabilityInfo.innerHTML = `<p class="text-red-600">${data.error}</p>`;
                    return;
                }
                
                if (data.is_blocked) {
                    availabilityInfo.innerHTML = `
                        <div class="text-red-600">
                            <i class="fas fa-ban mr-2"></i>
                            <strong>Date Blocked:</strong> ${data.blocked_info?.event_name || 'School Event'}
                            ${data.blocked_info?.description ? '<p class="text-sm mt-1">' + data.blocked_info.description + '</p>' : ''}
                        </div>
                    `;
                    return;
                }
                
                // Check for available sessions
                const availableSessions = data.sessions.filter(s => s.available);
                const wholeDayAvailable = availableSessions.find(s => s.type === 'whole_day' && s.available);
                
                if (wholeDayAvailable) {
                    availabilityInfo.innerHTML = `
                        <div class="text-green-600 mb-2">
                            <i class="fas fa-check-circle mr-2"></i>
                            <strong>Available for Whole Day Booking</strong>
                        </div>
                        <p class="text-sm text-gray-600">8:00 AM - 6:00 PM</p>
                    `;
                } else if (availableSessions.length > 0) {
                    availabilityInfo.innerHTML = `
                        <div class="text-yellow-600 mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Partially Available</strong>
                        </div>
                        <p class="text-sm text-gray-600 mb-2">Available sessions:</p>
                        <ul class="text-sm text-gray-600 list-disc list-inside">
                            ${availableSessions.map(s => `<li>${s.label}</li>`).join('')}
                        </ul>
                    `;
                } else {
                    availabilityInfo.innerHTML = `
                        <div class="text-red-600">
                            <i class="fas fa-times-circle mr-2"></i>
                            <strong>Fully Booked</strong>
                            <p class="text-sm mt-1">This date is completely booked. Please select another date.</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                loadingIndicator.classList.add('hidden');
                availabilityInfo.innerHTML = `
                    <p class="text-red-600">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Error checking availability. Please try again.
                    </p>
                `;
                console.error('Error fetching availability:', error);
            });
    }
    
    function openOvalCancelModal(bookingId) {
        document.getElementById('oval-cancel-booking-id').value = bookingId;
        document.getElementById('oval-cancel-remarks').value = '';
        document.getElementById('ovalCancelModal').classList.remove('hidden');
    }
    
    function closeOvalModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
        // Clean up calendar if reschedule modal is closed
        if (modalId === 'ovalRescheduleModal' && ovalRescheduleCalendar) {
            ovalRescheduleCalendar.destroy();
            ovalRescheduleCalendar = null;
        }
    }
    
    // Oval Event Type Management Functions
    function openAddOvalEventTypeModal() {
        document.getElementById('add-oval-event-type-name').value = '';
        document.getElementById('addOvalEventTypeModal').classList.remove('hidden');
    }
    
    function closeAddOvalEventTypeModal() {
        document.getElementById('addOvalEventTypeModal').classList.add('hidden');
    }
    
    function openEditOvalEventTypeModal(eventType) {
        // Parse if string
        if (typeof eventType === 'string') {
            eventType = JSON.parse(eventType);
        }
        document.getElementById('edit-oval-event-type-id').value = eventType.id;
        document.getElementById('edit-oval-event-type-name').value = eventType.name;
        document.getElementById('editOvalEventTypeModal').classList.remove('hidden');
    }
    
    function closeEditOvalEventTypeModal() {
        document.getElementById('editOvalEventTypeModal').classList.add('hidden');
    }
    
    function openDeleteOvalEventTypeModal(eventTypeId, eventTypeName) {
        document.getElementById('delete-oval-event-type-id').value = eventTypeId;
        document.getElementById('delete-oval-event-type-name').textContent = eventTypeName;
        document.getElementById('deleteOvalEventTypeModal').classList.remove('hidden');
    }
    
    function closeDeleteOvalEventTypeModal() {
        document.getElementById('deleteOvalEventTypeModal').classList.add('hidden');
    }
    
    function toggleOvalEventTypeStatus(eventTypeId) {
        if (confirm('Are you sure you want to toggle the status of this event type?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'dashboard.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'toggle_oval_event_type_status';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'event_type_id';
            idInput.value = eventTypeId;
            
            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

    <!-- Flatpickr for calendar -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
