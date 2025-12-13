<?php
// admin/bus.php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

$title = "Bus Management System";
$page_title = $title;
$base_url = "..";

$error = '';
$success = '';

// Ensure or_number column exists in bus_schedules table
$check_or_column = $conn->query("SHOW COLUMNS FROM bus_schedules LIKE 'or_number'");
if ($check_or_column->num_rows == 0) {
    $conn->query("ALTER TABLE bus_schedules ADD COLUMN or_number VARCHAR(50) NULL AFTER approval_document");
}

// Get success/error messages from URL parameters (for redirects)
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_bus') {
            $bus_number = trim($_POST['bus_number']);
            $vehicle_type = trim($_POST['vehicle_type']);
            $capacity = intval($_POST['capacity']);
            $status = $_POST['status'];
            $plate_number = isset($_POST['plate_number']) ? trim($_POST['plate_number']) : '';
            
            // Validate bus_number - no symbols allowed
            $invalid_symbols = ['!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '_', '+', '=', '{', '}', '[', ']', ':', ';', '"', "'", '<', '>', '?', '/', '\\', '|', '`', '~', ',', '.'];
            $bus_number_has_symbols = false;
            foreach ($invalid_symbols as $symbol) {
                if (strpos($bus_number, $symbol) !== false) {
                    $bus_number_has_symbols = true;
                    break;
                }
            }
            
            // Validate plate_number - no symbols allowed
            $plate_number_has_symbols = false;
            if (!empty($plate_number)) {
                foreach ($invalid_symbols as $symbol) {
                    if (strpos($plate_number, $symbol) !== false) {
                        $plate_number_has_symbols = true;
                        break;
                    }
                }
            }
            
            // Ensure plate_number column exists
            $check_column = $conn->query("SHOW COLUMNS FROM buses LIKE 'plate_number'");
            if ($check_column->num_rows == 0) {
                $conn->query("ALTER TABLE buses ADD COLUMN plate_number VARCHAR(20) NULL AFTER bus_number");
            }
            
            // Validate inputs
            if ($bus_number_has_symbols) {
                $error = 'Bus Number cannot contain symbols (! @ # $ % ^ & * ( ) _ + = { } [ ] : ; " \' < > ? / \\ | ` ~ , .).';
            } elseif ($plate_number_has_symbols) {
                $error = 'Plate Number cannot contain symbols (! @ # $ % ^ & * ( ) _ + = { } [ ] : ; " \' < > ? / \\ | ` ~ , .).';
            } elseif (empty($bus_number) || empty($vehicle_type) || $capacity <= 0) {
                $error = 'Please fill in all fields correctly.';
            } else {
                // Check if bus number already exists
                $check_stmt = $conn->prepare("SELECT id FROM buses WHERE bus_number = ?");
                $check_stmt->bind_param("s", $bus_number);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $error = 'Bus number already exists. Please use a different number.';
                } else {
                    // Insert new bus with plate number
                    $insert_stmt = $conn->prepare("INSERT INTO buses (bus_number, plate_number, vehicle_type, capacity, status) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->bind_param("sssis", $bus_number, $plate_number, $vehicle_type, $capacity, $status);
                    
                    if ($insert_stmt->execute()) {
                        $success = 'Bus added successfully!';
                    } else {
                        $error = 'Error adding bus: ' . $conn->error;
                    }
                }
            }
        } elseif ($_POST['action'] === 'update_bus') {
            $bus_id = intval($_POST['bus_id']);
            $bus_number = trim($_POST['bus_number']);
            $plate_number = isset($_POST['plate_number']) ? trim($_POST['plate_number']) : '';
            $vehicle_type = trim($_POST['vehicle_type']);
            $capacity = intval($_POST['capacity']);
            $status = $_POST['status'];
            
            // Validate bus_number - no symbols allowed
            $invalid_symbols = ['!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '_', '+', '=', '{', '}', '[', ']', ':', ';', '"', "'", '<', '>', '?', '/', '\\', '|', '`', '~', ',', '.'];
            $bus_number_has_symbols = false;
            foreach ($invalid_symbols as $symbol) {
                if (strpos($bus_number, $symbol) !== false) {
                    $bus_number_has_symbols = true;
                    break;
                }
            }
            
            // Validate plate_number - no symbols allowed
            $plate_number_has_symbols = false;
            if (!empty($plate_number)) {
                foreach ($invalid_symbols as $symbol) {
                    if (strpos($plate_number, $symbol) !== false) {
                        $plate_number_has_symbols = true;
                        break;
                    }
                }
            }
            
            // Validate inputs
            if ($bus_number_has_symbols) {
                $error = 'Bus Number cannot contain symbols (! @ # $ % ^ & * ( ) _ + = { } [ ] : ; " \' < > ? / \\ | ` ~ , .).';
            } elseif ($plate_number_has_symbols) {
                $error = 'Plate Number cannot contain symbols (! @ # $ % ^ & * ( ) _ + = { } [ ] : ; " \' < > ? / \\ | ` ~ , .).';
            } elseif (empty($bus_number) || empty($vehicle_type) || $capacity <= 0) {
                $error = 'Please fill in all required fields correctly.';
            } else {
                // Check if bus number already exists (excluding current bus)
                $check_stmt = $conn->prepare("SELECT id FROM buses WHERE bus_number = ? AND id != ?");
                $check_stmt->bind_param("si", $bus_number, $bus_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $error = 'Bus number already exists. Please use a different number.';
                } else {
                    // Update bus details
                    $update_stmt = $conn->prepare("UPDATE buses SET bus_number = ?, plate_number = ?, vehicle_type = ?, capacity = ?, status = ? WHERE id = ?");
                    $update_stmt->bind_param("sssisi", $bus_number, $plate_number, $vehicle_type, $capacity, $status, $bus_id);
                    
                    if ($update_stmt->execute()) {
                        // Redirect to refresh the page and show updated data
                        header("Location: bus.php?tab=buses&success=" . urlencode('Bus updated successfully!'));
                        exit();
                    } else {
                        $error = 'Error updating bus: ' . $conn->error;
                    }
                }
            }
        } elseif ($_POST['action'] === 'update_bus_status') {
            $bus_id = intval($_POST['bus_id']);
            $new_status = $_POST['new_status'];
            
            $update_stmt = $conn->prepare("UPDATE buses SET status = ? WHERE id = ?");
            $update_stmt->bind_param("si", $new_status, $bus_id);
            
            if ($update_stmt->execute()) {
                $success = 'Bus status updated successfully!';
            } else {
                $error = 'Error updating bus status: ' . $conn->error;
            }
        } elseif ($_POST['action'] === 'delete_bus') {
            $bus_id = intval($_POST['bus_id']);
            
            // Start transaction to ensure data integrity
            $conn->begin_transaction();
            
            try {
                // Delete associated bookings first
                $delete_bookings = $conn->prepare("DELETE FROM bus_bookings WHERE bus_id = ?");
                $delete_bookings->bind_param("i", $bus_id);
                $delete_bookings->execute();
                
                // Now delete the bus
                $delete_stmt = $conn->prepare("DELETE FROM buses WHERE id = ?");
                $delete_stmt->bind_param("i", $bus_id);
                
                if ($delete_stmt->execute()) {
                    $conn->commit();
                    // Redirect to refresh the page and show updated count
                    header("Location: bus.php?tab=buses&success=" . urlencode('Bus deleted successfully! All associated bookings have been removed.'));
                    exit();
                } else {
                    throw new Exception('Error deleting bus: ' . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error deleting bus: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'update_cost_settings') {
            // Create settings table if not exists
            $conn->query("CREATE TABLE IF NOT EXISTS bus_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(50) UNIQUE NOT NULL,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            
            // Get and validate all cost settings
            $runtime_liters = floatval($_POST['runtime_liters']);
            $maintenance_cost = floatval($_POST['maintenance_cost']);
            $standby_cost = floatval($_POST['standby_cost']);
            $additive_cost = floatval($_POST['additive_cost']);
            $rate_per_bus = floatval($_POST['rate_per_bus']);
            
            $errors = [];
            if ($runtime_liters < 0) $errors[] = 'Run Time must be 0 or greater.';
            if ($maintenance_cost < 0) $errors[] = 'Maintenance Cost must be 0 or greater.';
            if ($standby_cost < 0) $errors[] = 'Standby Cost must be 0 or greater.';
            if ($additive_cost < 0) $errors[] = 'Additive Cost must be 0 or greater.';
            if ($rate_per_bus < 0) $errors[] = 'Rate per Bus must be 0 or greater.';
            
            if (empty($errors)) {
                $settings = [
                    'runtime_liters' => $runtime_liters,
                    'maintenance_cost' => $maintenance_cost,
                    'standby_cost' => $standby_cost,
                    'additive_cost' => $additive_cost,
                    'rate_per_bus' => $rate_per_bus
                ];
                
                $all_success = true;
                foreach ($settings as $key => $value) {
                    $stmt = $conn->prepare("INSERT INTO bus_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->bind_param("sss", $key, $value, $value);
                    if (!$stmt->execute()) {
                        $all_success = false;
                        break;
                    }
                }
                
                if ($all_success) {
                    $success = 'Cost breakdown settings updated successfully!';
                } else {
                    $error = 'Error updating cost settings: ' . $conn->error;
                }
            } else {
                $error = implode(' ', $errors);
            }
        } elseif ($_POST['action'] === 'approve_schedule') {
            $schedule_id = intval($_POST['schedule_id']);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Update schedule status (no OR number required)
                $update_stmt = $conn->prepare("UPDATE bus_schedules SET status = 'approved' WHERE id = ?");
                $update_stmt->bind_param("i", $schedule_id);
                $update_stmt->execute();
                
                // Get schedule details
                $schedule_query = "SELECT * FROM bus_schedules WHERE id = ?";
                $schedule_stmt = $conn->prepare($schedule_query);
                $schedule_stmt->bind_param("i", $schedule_id);
                $schedule_stmt->execute();
                $schedule = $schedule_stmt->get_result()->fetch_assoc();
                
                // Check if bus booking already exists (created when student submitted)
                $existing_booking = $conn->prepare("SELECT bus_id FROM bus_bookings WHERE schedule_id = ?");
                $existing_booking->bind_param("i", $schedule_id);
                $existing_booking->execute();
                $existing_result = $existing_booking->get_result();
                
                if ($existing_result->num_rows > 0) {
                    // Booking already exists - just ensure bus status is booked
                $bus_ids = [];
                    while ($row = $existing_result->fetch_assoc()) {
                        $bus_ids[] = $row['bus_id'];
                    }
                    
                    // Update bus status to booked (in case it wasn't updated)
                    if (count($bus_ids) > 0) {
                        $bus_update_stmt = $conn->prepare("UPDATE buses SET status = 'booked' WHERE id IN (" . implode(',', array_fill(0, count($bus_ids), '?')) . ")");
                        $bus_update_stmt->bind_param(str_repeat('i', count($bus_ids)), ...$bus_ids);
                        $bus_update_stmt->execute();
                    }
                } else {
                    // No existing booking - create new ones using the requested bus number or available buses
                    $bus_ids = [];
                    
                    if (!empty($schedule['bus_no'])) {
                        // Use the specific bus number requested
                        $requested_bus = $conn->prepare("SELECT id FROM buses WHERE bus_number = ?");
                        $requested_bus->bind_param("s", $schedule['bus_no']);
                        $requested_bus->execute();
                        $requested_result = $requested_bus->get_result();
                        
                        if ($requested_result->num_rows > 0) {
                            $bus_row = $requested_result->fetch_assoc();
                            $bus_ids[] = $bus_row['id'];
                        }
                    }
                    
                    // If we need more buses or didn't find the requested one, get available buses
                    if (count($bus_ids) < $schedule['no_of_vehicles']) {
                        $needed = $schedule['no_of_vehicles'] - count($bus_ids);
                        $available_buses = $conn->query("SELECT id FROM buses WHERE status = 'available' LIMIT " . $needed);
                while ($bus = $available_buses->fetch_assoc()) {
                    $bus_ids[] = $bus['id'];
                        }
                }
                
                if (count($bus_ids) >= $schedule['no_of_vehicles']) {
                    foreach ($bus_ids as $bus_id) {
                        $booking_stmt = $conn->prepare("INSERT INTO bus_bookings (schedule_id, bus_id, booking_date, status) VALUES (?, ?, ?, 'active')");
                        $booking_stmt->bind_param("iis", $schedule_id, $bus_id, $schedule['date_covered']);
                        $booking_stmt->execute();
                    }
                    
                    // Update bus status to booked
                    $bus_update_stmt = $conn->prepare("UPDATE buses SET status = 'booked' WHERE id IN (" . implode(',', array_fill(0, count($bus_ids), '?')) . ")");
                    $bus_update_stmt->bind_param(str_repeat('i', count($bus_ids)), ...$bus_ids);
                    $bus_update_stmt->execute();
                } else {
                    throw new Exception('Not enough buses available for this booking.');
                }
                }
                
                    // Update billing statement payment status to 'paid' since OR Number is provided
                    $payment_date = date('Y-m-d H:i:s');
                    $update_payment = $conn->prepare("UPDATE billing_statements SET payment_status = 'paid', payment_date = ? WHERE schedule_id = ?");
                    $update_payment->bind_param("si", $payment_date, $schedule_id);
                    $update_payment->execute();
                
                    $conn->commit();
                    
                    // Send notification to user
                    require_once '../includes/notification_functions.php';
                    $schedule_user_id = $schedule['user_id'];
                    $date_formatted = date('F j, Y', strtotime($schedule['date_covered']));
                    create_notification($schedule_user_id, "Bus Schedule Approved", "Your bus schedule request for {$date_formatted} (Destination: {$schedule['destination']}) has been approved! User may proceed with the trip according to instructions.", "success", "student/bus.php");
                    
                    $success = 'Schedule approved successfully! User may proceed with the trip according to instructions.';
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = $e->getMessage();
                }
        } elseif ($_POST['action'] === 'reject_schedule') {
            $schedule_id = intval($_POST['schedule_id']);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Update schedule status
            $update_stmt = $conn->prepare("UPDATE bus_schedules SET status = 'rejected' WHERE id = ?");
            $update_stmt->bind_param("i", $schedule_id);
                $update_stmt->execute();
                
                // Get bus IDs from bookings for this schedule
                $bus_ids_query = $conn->prepare("SELECT bus_id FROM bus_bookings WHERE schedule_id = ?");
                $bus_ids_query->bind_param("i", $schedule_id);
                $bus_ids_query->execute();
                $bus_ids_result = $bus_ids_query->get_result();
                
                $bus_ids = [];
                while ($row = $bus_ids_result->fetch_assoc()) {
                    $bus_ids[] = $row['bus_id'];
                }
                
                // Update bus status back to available if booking is rejected
                if (count($bus_ids) > 0) {
                    $bus_update_stmt = $conn->prepare("UPDATE buses SET status = 'available' WHERE id IN (" . implode(',', array_fill(0, count($bus_ids), '?')) . ")");
                    $bus_update_stmt->bind_param(str_repeat('i', count($bus_ids)), ...$bus_ids);
                    $bus_update_stmt->execute();
                }
                
                $conn->commit();
                
                // Send notification to user
                require_once '../includes/notification_functions.php';
                $reject_schedule = $conn->prepare("SELECT user_id, date_covered, destination FROM bus_schedules WHERE id = ?");
                $reject_schedule->bind_param("i", $schedule_id);
                $reject_schedule->execute();
                $reject_result = $reject_schedule->get_result();
                if ($reject_data = $reject_result->fetch_assoc()) {
                    $date_formatted = date('F j, Y', strtotime($reject_data['date_covered']));
                    create_notification($reject_data['user_id'], "Bus Schedule Rejected", "Your bus schedule request for {$date_formatted} (Destination: {$reject_data['destination']}) has been rejected.", "error", "student/bus.php");
                }
                
                $success = 'Schedule rejected successfully!';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error rejecting schedule: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'complete_schedule') {
            $schedule_id = intval($_POST['schedule_id']);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Update schedule status to completed
                $update_stmt = $conn->prepare("UPDATE bus_schedules SET status = 'completed' WHERE id = ?");
                $update_stmt->bind_param("i", $schedule_id);
                $update_stmt->execute();
                
                // Get bus IDs from bookings for this schedule
                $bus_ids_query = $conn->prepare("SELECT bus_id FROM bus_bookings WHERE schedule_id = ?");
                $bus_ids_query->bind_param("i", $schedule_id);
                $bus_ids_query->execute();
                $bus_ids_result = $bus_ids_query->get_result();
                
                $bus_ids = [];
                while ($row = $bus_ids_result->fetch_assoc()) {
                    $bus_ids[] = $row['bus_id'];
                }
                
                // Update bus status back to available after trip completion
                if (count($bus_ids) > 0) {
                    $bus_update_stmt = $conn->prepare("UPDATE buses SET status = 'available' WHERE id IN (" . implode(',', array_fill(0, count($bus_ids), '?')) . ")");
                    $bus_update_stmt->bind_param(str_repeat('i', count($bus_ids)), ...$bus_ids);
                    $bus_update_stmt->execute();
                }
                
                $conn->commit();
                
                // Send notification to user
                require_once '../includes/notification_functions.php';
                $complete_schedule = $conn->prepare("SELECT user_id, date_covered, destination FROM bus_schedules WHERE id = ?");
                $complete_schedule->bind_param("i", $schedule_id);
                $complete_schedule->execute();
                $complete_result = $complete_schedule->get_result();
                if ($complete_data = $complete_result->fetch_assoc()) {
                    $date_formatted = date('F j, Y', strtotime($complete_data['date_covered']));
                    create_notification($complete_data['user_id'], "Bus Trip Completed", "Your bus trip for {$date_formatted} (Destination: {$complete_data['destination']}) has been completed. The bus trip has been finished.", "success", "student/bus.php");
                }
                
                $success = 'Schedule marked as completed successfully! The bus trip has been finished.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error completing schedule: ' . $e->getMessage();
            }
        }
    }
}

// Check if filter is set (default to "all" to show all requests)
$view_filter = isset($_GET['view']) ? $_GET['view'] : 'all';

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'schedules';


// Get current cost breakdown settings
$cost_settings = [
    'runtime_liters' => 25.00,
    'maintenance_cost' => 5000.00,
    'standby_cost' => 1500.00,
    'additive_cost' => 1500.00,
    'rate_per_bus' => 1500.00
];

// Ensure bus_settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS bus_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Load cost settings from database
foreach ($cost_settings as $key => $default_value) {
    $setting_query = $conn->query("SELECT setting_value FROM bus_settings WHERE setting_key = '$key'");
    if ($setting_query && $setting_query->num_rows > 0) {
        $cost_settings[$key] = floatval($setting_query->fetch_assoc()['setting_value']);
    }
}

// Ensure plate_number column exists
$check_column = $conn->query("SHOW COLUMNS FROM buses LIKE 'plate_number'");
if ($check_column->num_rows == 0) {
    $conn->query("ALTER TABLE buses ADD COLUMN plate_number VARCHAR(20) NULL AFTER bus_number");
}

// Auto-add the requested vehicles if they don't exist
// NOTE: This auto-adds buses on every page load. If you delete these buses, they will be re-added.
// To permanently remove this feature, comment out or remove the code below.
// $vehicles_to_add = [
//     ['bus_number' => 'van-20', 'vehicle_type' => 'Van', 'capacity' => 20, 'plate_number' => ''],
//     ['bus_number' => 'travis-15', 'vehicle_type' => 'Travis', 'capacity' => 15, 'plate_number' => ''],
//     ['bus_number' => '49', 'vehicle_type' => 'Bus', 'capacity' => 49, 'plate_number' => '']
// ];
// 
// foreach ($vehicles_to_add as $vehicle) {
//     $check_stmt = $conn->prepare("SELECT id FROM buses WHERE bus_number = ?");
//     $check_stmt->bind_param("s", $vehicle['bus_number']);
//     $check_stmt->execute();
//     $result = $check_stmt->get_result();
//     
//     if ($result->num_rows == 0) {
//         // Vehicle doesn't exist, add it
//         $insert_stmt = $conn->prepare("INSERT INTO buses (bus_number, plate_number, vehicle_type, capacity, status) VALUES (?, ?, ?, ?, 'available')");
//         $insert_stmt->bind_param("sssi", $vehicle['bus_number'], $vehicle['plate_number'], $vehicle['vehicle_type'], $vehicle['capacity']);
//         $insert_stmt->execute();
//     }
// }

// Get all buses for management
$all_buses = $conn->query("SELECT * FROM buses ORDER BY bus_number ASC");
$total_buses_count = $all_buses->num_rows;

include '../includes/header.php';
?>
<style>
    /* Make sidebar not scrollable in bus.php - override all overflow classes */
    #sidebar {
        overflow: hidden !important;
        overflow-y: hidden !important;
        overflow-x: hidden !important;
    }
    /* Target nav element with highest specificity */
    #sidebar nav,
    #sidebar nav.overflow-y-auto,
    div#sidebar nav.overflow-y-auto {
        overflow: hidden !important;
        overflow-y: hidden !important;
        overflow-x: hidden !important;
    }
    /* Ensure the sidebar container itself doesn't scroll */
    .flex.min-h-screen > #sidebar,
    div.flex.min-h-screen > div#sidebar {
        overflow: hidden !important;
    }
</style>
<script>
    // Force remove overflow-y-auto class from sidebar nav when page loads
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarNav = document.querySelector('#sidebar nav');
        if (sidebarNav) {
            sidebarNav.classList.remove('overflow-y-auto');
            sidebarNav.style.overflow = 'hidden';
            sidebarNav.style.overflowY = 'hidden';
            sidebarNav.style.overflowX = 'hidden';
        }
    });
</script>
<div class="flex min-h-screen">
    <?php include '../includes/admin_sidebar.php'; ?>
    <main class="flex-1 p-8 bg-gray-100">
        <div class="mb-6">
            <h2 class="text-3xl font-bold text-blue-900 flex items-center mb-4">
                <i class="fas fa-bus mr-3"></i>Bus Management System
            </h2>
            
            <!-- Tab Navigation -->
            <div class="flex space-x-1 bg-white rounded-lg p-1 shadow">
                <a href="?tab=schedules<?php echo $view_filter !== 'all' ? '&view=' . $view_filter : ''; ?>" 
                   class="flex-1 px-4 py-2 rounded-md text-center transition <?php echo $active_tab === 'schedules' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-100'; ?>">
                    <i class="fas fa-calendar-alt mr-2"></i>Bus Schedules
                </a>
                <a href="?tab=buses" 
                   class="flex-1 px-4 py-2 rounded-md text-center transition <?php echo $active_tab === 'buses' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-100'; ?>">
                    <i class="fas fa-bus mr-2"></i>Manage Buses
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium"><?php echo $success; ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium"><?php echo $error; ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- SCHEDULES TAB -->
        <?php if ($active_tab === 'schedules'): ?>
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-semibold text-gray-800 flex items-center">
                <span class="ml-2 text-lg font-normal text-gray-500">
                    <?php 
                    if ($view_filter === 'current_month') {
                        echo '(' . date('F Y') . ')';
                    } elseif ($view_filter === 'pending') {
                        echo '(Pending Requests)';
                    } elseif ($view_filter === 'approved') {
                        echo '(Approved Requests)';
                    } else {
                        echo '(All Requests)';
                    }
                    ?>
                </span>
            </h3>
            <div class="flex flex-wrap gap-2">
                <a href="?tab=schedules&view=all" class="px-4 py-2 rounded-lg <?php echo $view_filter === 'all' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                    <i class="fas fa-list mr-2"></i>All
                </a>
                <a href="?tab=schedules&view=pending" class="px-4 py-2 rounded-lg <?php echo $view_filter === 'pending' ? 'bg-yellow-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                    <i class="fas fa-clock mr-2"></i>Pending
                </a>
                <a href="?tab=schedules&view=approved" class="px-4 py-2 rounded-lg <?php echo $view_filter === 'approved' ? 'bg-green-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                    <i class="fas fa-check-circle mr-2"></i>Approved
                </a>
                <a href="?tab=schedules&view=current_month" class="px-4 py-2 rounded-lg <?php echo $view_filter === 'current_month' ? 'bg-purple-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                    <i class="fas fa-calendar mr-2"></i>Current Month
                </a>
            </div>
        </div>
        <?php
        // Get bus availability statistics
        $bus_stats_query = "SELECT 
            COUNT(*) as total_buses,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_buses,
            SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as booked_buses,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_buses
            FROM buses";
        $bus_stats_result = $conn->query($bus_stats_query);
        $bus_stats = $bus_stats_result->fetch_assoc();
        
        // Get current month and year
        $current_month = date('m');
        $current_year = date('Y');
        
        // Count schedules by status (ALL TIME or FILTERED)
        if ($view_filter === 'current_month') {
            $count_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM bus_schedules WHERE MONTH(date_covered) = ? AND YEAR(date_covered) = ?";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->bind_param("ii", $current_month, $current_year);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $schedule_stats = $count_result->fetch_assoc();
            
            // Get schedules for this month with billing info
            // Pagination setup
            $rows_per_page = 10;
            $current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $offset = ($current_page - 1) * $rows_per_page;
            
            // Count total for this month
            $count_sql = "SELECT COUNT(*) as total 
                         FROM bus_schedules 
                         WHERE MONTH(date_covered) = ? AND YEAR(date_covered) = ?";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->bind_param("ii", $current_month, $current_year);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $total_rows = $count_result->fetch_assoc()['total'];
            $total_pages = ceil($total_rows / $rows_per_page);
            
            $list_sql = "SELECT bs.*, bst.total_amount, u.name as user_name, u.email as user_email, u.user_type, b.plate_number
                         FROM bus_schedules bs 
                         LEFT JOIN billing_statements bst ON bs.id = bst.schedule_id 
                         LEFT JOIN user_accounts u ON bs.user_id = u.id
                         LEFT JOIN buses b ON bs.bus_no = b.bus_number
                         WHERE MONTH(bs.date_covered) = ? AND YEAR(bs.date_covered) = ? 
                         ORDER BY bs.id DESC, bs.created_at DESC 
                         LIMIT ? OFFSET ?";
            $list_stmt = $conn->prepare($list_sql);
            $list_stmt->bind_param("iiii", $current_month, $current_year, $rows_per_page, $offset);
            $list_stmt->execute();
            $list_result = $list_stmt->get_result();
        } elseif ($view_filter === 'pending') {
            // Show PENDING requests only
            // Pagination setup
            $rows_per_page = 10;
            $current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $offset = ($current_page - 1) * $rows_per_page;
            
            $count_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM bus_schedules WHERE status = 'pending'";
            $count_result = $conn->query($count_sql);
            $schedule_stats = $count_result->fetch_assoc();
            $total_rows = $schedule_stats['total'];
            $total_pages = ceil($total_rows / $rows_per_page);
            
            // Get pending schedules with billing info and user info
            $list_sql = "SELECT bs.*, bst.total_amount, u.name as user_name, u.email as user_email, u.user_type, b.plate_number
                         FROM bus_schedules bs 
                         LEFT JOIN billing_statements bst ON bs.id = bst.schedule_id 
                         LEFT JOIN user_accounts u ON bs.user_id = u.id
                         LEFT JOIN buses b ON bs.bus_no = b.bus_number
                         WHERE bs.status = 'pending'
                         ORDER BY bs.id DESC, bs.created_at DESC 
                         LIMIT ? OFFSET ?";
            $list_stmt = $conn->prepare($list_sql);
            $list_stmt->bind_param("ii", $rows_per_page, $offset);
            $list_stmt->execute();
            $list_result = $list_stmt->get_result();
        } elseif ($view_filter === 'approved') {
            // Show APPROVED requests only
            // Pagination setup
            $rows_per_page = 10;
            $current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $offset = ($current_page - 1) * $rows_per_page;
            
            $count_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM bus_schedules WHERE status = 'approved'";
            $count_result = $conn->query($count_sql);
            $schedule_stats = $count_result->fetch_assoc();
            $total_rows = $schedule_stats['total'];
            $total_pages = ceil($total_rows / $rows_per_page);
            
            // Get approved schedules with billing info and user info
            $list_sql = "SELECT bs.*, bst.total_amount, u.name as user_name, u.email as user_email, u.user_type, b.plate_number
                         FROM bus_schedules bs 
                         LEFT JOIN billing_statements bst ON bs.id = bst.schedule_id 
                         LEFT JOIN user_accounts u ON bs.user_id = u.id
                         LEFT JOIN buses b ON bs.bus_no = b.bus_number
                         WHERE bs.status = 'approved'
                         ORDER BY bs.id DESC, bs.created_at DESC 
                         LIMIT ? OFFSET ?";
            $list_stmt = $conn->prepare($list_sql);
            $list_stmt->bind_param("ii", $rows_per_page, $offset);
            $list_stmt->execute();
            $list_result = $list_stmt->get_result();
        } else {
            // Show ALL requests (default)
            // Pagination setup
            $rows_per_page = 10;
            $current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $offset = ($current_page - 1) * $rows_per_page;
            
            $count_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM bus_schedules";
            $count_result = $conn->query($count_sql);
            $schedule_stats = $count_result->fetch_assoc();
            $total_rows = $schedule_stats['total'];
            $total_pages = ceil($total_rows / $rows_per_page);
            
            // Get all schedules with billing info and user info
            // Ensure status is always returned, defaulting to 'pending' if NULL
            $list_sql = "SELECT bs.id, bs.user_id, bs.user_type, bs.client, bs.destination, bs.purpose, 
                         bs.date_covered, bs.vehicle, bs.bus_no, bs.no_of_days, bs.no_of_vehicles, 
                         COALESCE(bs.status, 'pending') as status, bs.approval_document, bs.or_number,
                         bs.created_at, bs.updated_at, bst.total_amount, u.name as user_name, 
                         u.email as user_email, b.plate_number
                         FROM bus_schedules bs 
                         LEFT JOIN billing_statements bst ON bs.id = bst.schedule_id 
                         LEFT JOIN user_accounts u ON bs.user_id = u.id
                         LEFT JOIN buses b ON bs.bus_no = b.bus_number
                         ORDER BY bs.id DESC, bs.created_at DESC 
                         LIMIT ? OFFSET ?";
            $list_stmt = $conn->prepare($list_sql);
            $list_stmt->bind_param("ii", $rows_per_page, $offset);
            $list_stmt->execute();
            $list_result = $list_stmt->get_result();
        }
        ?>
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Bus Availability -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-bus text-blue-600 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Available Buses</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $bus_stats['available_buses']; ?>/<?php echo $bus_stats['total_buses']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Total Requests -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-list text-green-600 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Total Requests</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $schedule_stats['total']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pending Requests -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-clock text-yellow-600 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Pending</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $schedule_stats['pending']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Approved Requests -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Approved</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $schedule_stats['approved']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="font-bold text-lg mb-4 flex items-center"><i class="fas fa-list mr-2"></i>Schedules List</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm border rounded-lg overflow-hidden">
                    <thead>
                        <tr class="bg-blue-100 text-blue-900">
                            <th class="border px-4 py-2">Client</th>
                            <th class="border px-4 py-2">Destination</th>
                            <th class="border px-4 py-2">Purpose</th>
                            <th class="border px-4 py-2">Date Covered</th>
                            <th class="border px-4 py-2">Vehicle</th>
                            <th class="border px-4 py-2">Bus No.</th>
                            <th class="border px-4 py-2">No. of Days</th>
                            <th class="border px-4 py-2">No. of Vehicles</th>
                            <th class="border px-4 py-2">Status</th>
                            <th class="border px-4 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($list_result->num_rows > 0): ?>
                            <?php while ($row = $list_result->fetch_assoc()): ?>
                                <tr class="hover:bg-blue-50 transition">
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($row['client']); ?></td>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($row['destination']); ?></td>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($row['purpose']); ?></td>
                                    <td class="border px-4 py-2"><?php echo date('M d, Y', strtotime($row['date_covered'])); ?></td>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($row['vehicle']); ?></td>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($row['bus_no']); ?></td>
                                    <td class="border px-4 py-2 text-center"><?php echo htmlspecialchars($row['no_of_days']); ?></td>
                                    <td class="border px-4 py-2 text-center"><?php echo htmlspecialchars($row['no_of_vehicles']); ?></td>
                                    <td class="border px-4 py-2 text-center">
                                        <?php
                                        $status_value = isset($row['status']) ? trim(strtolower($row['status'])) : 'pending';
                                        $status_class = '';
                                        $status_text = 'Pending';
                                        
                                        switch ($status_value) {
                                            case 'pending':
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                $status_text = 'Pending';
                                                break;
                                            case 'approved':
                                                $status_class = 'bg-green-100 text-green-800';
                                                $status_text = 'Approved';
                                                break;
                                            case 'completed':
                                                $status_class = 'bg-blue-100 text-blue-800';
                                                $status_text = 'Completed';
                                                break;
                                            case 'rejected':
                                                $status_class = 'bg-red-100 text-red-800';
                                                $status_text = 'Rejected';
                                                break;
                                            case 'cancelled':
                                            case 'canceled':
                                                $status_class = 'bg-gray-100 text-gray-800';
                                                $status_text = 'Cancelled';
                                                break;
                                            default:
                                                // For any unknown status, default to Cancelled
                                                $status_class = 'bg-gray-100 text-gray-800';
                                                $status_text = 'Cancelled';
                                                break;
                                        }
                                        ?>
                                        <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td class="border px-4 py-2 text-center">
                                        <div class="flex space-x-2 justify-center">
                                            <button type="button" class="text-blue-600 hover:text-blue-900 view-schedule-btn" 
                                                    data-schedule='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>'
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($row['status'] === 'pending'): ?>
                                                <button type="button" class="text-green-600 hover:text-green-900" 
                                                        onclick="openApproveModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['client'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['destination'], ENT_QUOTES); ?>')"
                                                        title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="text-red-600 hover:text-red-900"
                                                        onclick="openRejectModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['client'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['destination'], ENT_QUOTES); ?>')"
                                                        title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php elseif ($row['status'] === 'approved'): ?>
                                                <button type="button" class="text-blue-600 hover:text-blue-900" 
                                                        onclick="openCompleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['client'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['destination'], ENT_QUOTES); ?>')"
                                                        title="Mark as Completed">
                                                    <i class="fas fa-check-double"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="11" class="border px-4 py-2 text-center text-gray-500">
                                <?php if ($view_filter === 'current_month'): ?>
                                    No schedules found for this month. <a href="?tab=schedules&view=all" class="text-blue-600 hover:underline">View all requests</a>
                                <?php elseif ($view_filter === 'pending'): ?>
                                    No pending requests found. <a href="?tab=schedules&view=all" class="text-blue-600 hover:underline">View all requests</a>
                                <?php elseif ($view_filter === 'approved'): ?>
                                    No approved requests found. <a href="?tab=schedules&view=all" class="text-blue-600 hover:underline">View all requests</a>
                                <?php else: ?>
                                    No bus requests found in the system.
                                <?php endif; ?>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if (isset($total_pages) && $total_pages > 1): ?>
                <div class="mt-6 px-4 py-4 border-t border-gray-200 flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Showing 
                        <span class="font-medium"><?php echo $offset + 1; ?></span> 
                        to 
                        <span class="font-medium"><?php echo min($offset + $rows_per_page, $total_rows); ?></span> 
                        of 
                        <span class="font-medium"><?php echo $total_rows; ?></span> 
                        results
                    </div>
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <!-- Previous Button -->
                        <a href="?tab=schedules<?php echo $view_filter !== 'all' ? '&view=' . $view_filter : ''; ?>&page=<?php echo max(1, $current_page - 1); ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 <?php echo $current_page == 1 ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                            <span class="sr-only">Previous</span>
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <!-- Page Numbers -->
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <a href="?tab=schedules<?php echo $view_filter !== 'all' ? '&view=' . $view_filter : ''; ?>&page=1" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?tab=schedules<?php echo $view_filter !== 'all' ? '&view=' . $view_filter : ''; ?>&page=<?php echo $i; ?>" 
                               class="<?php echo $i == $current_page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'; ?> relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                            <?php endif; ?>
                            <a href="?tab=schedules<?php echo $view_filter !== 'all' ? '&view=' . $view_filter : ''; ?>&page=<?php echo $total_pages; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50"><?php echo $total_pages; ?></a>
                        <?php endif; ?>
                        
                        <!-- Next Button -->
                        <a href="?tab=schedules<?php echo $view_filter !== 'all' ? '&view=' . $view_filter : ''; ?>&page=<?php echo min($total_pages, $current_page + 1); ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 <?php echo $current_page == $total_pages ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                            <span class="sr-only">Next</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; // End schedules tab ?>
        
        <!-- MANAGE BUSES TAB -->
        <?php if ($active_tab === 'buses'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Add New Bus Form -->
            <div class="lg:col-span-1 bg-white shadow rounded-lg p-6">
                <h3 class="font-bold text-lg mb-4 flex items-center text-blue-900">
                    <i class="fas fa-plus-circle mr-2"></i>Add New Bus
                </h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_bus">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bus Number <span class="text-red-500">*</span></label>
                        <input type="text" name="bus_number" id="add-bus-number" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="e.g., 4, 5, Bus-001"
                               oninput="preventSymbols(this)"
                               onkeypress="return !/[!@#$%^&*()_+={}\[\]:;&quot;'<>?\/\\|`~,.]/.test(event.key)">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Plate Number</label>
                        <input type="text" name="plate_number" id="add-plate-number"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="e.g., ABC-1234"
                               oninput="preventSymbols(this)"
                               onkeypress="return !/[!@#$%^&*()_+={}\[\]:;&quot;'<>?\/\\|`~,.]/.test(event.key)">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Type <span class="text-red-500">*</span></label>
                        <input type="hidden" name="vehicle_type" value="Bus">
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-700">
                            BUS
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Capacity (Seats) <span class="text-red-500">*</span></label>
                        <input type="number" name="capacity" required min="1"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="e.g., 50">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status <span class="text-red-500">*</span></label>
                        <select name="status" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="available">Available</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="out_of_service">Out of Service</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 transition">
                        <i class="fas fa-plus mr-2"></i>Add Bus
                    </button>
                </form>
            </div>
            
            <!-- Buses List -->
            <div class="lg:col-span-2 bg-white shadow rounded-lg p-6">
                <h3 class="font-bold text-lg mb-4 flex items-center text-blue-900">
                    <i class="fas fa-list mr-2"></i>All Buses (<?php echo $total_buses_count; ?>)
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm border rounded-lg overflow-hidden">
                        <thead>
                            <tr class="bg-blue-100 text-blue-900">
                                <th class="border px-4 py-2">Bus #</th>
                                <th class="border px-4 py-2">Plate Number</th>
                                <th class="border px-4 py-2">Type</th>
                                <th class="border px-4 py-2">Capacity</th>
                                <th class="border px-4 py-2">Status</th>
                                <th class="border px-4 py-2">Added</th>
                                <th class="border px-4 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($all_buses->num_rows > 0): ?>
                                <?php while ($bus = $all_buses->fetch_assoc()): ?>
                                    <tr class="hover:bg-blue-50 transition">
                                        <td class="border px-4 py-2 font-semibold"><?php echo htmlspecialchars($bus['bus_number']); ?></td>
                                        <td class="border px-4 py-2"><?php echo !empty($bus['plate_number']) ? htmlspecialchars($bus['plate_number']) : '-'; ?></td>
                                        <td class="border px-4 py-2"><?php echo htmlspecialchars($bus['vehicle_type']); ?></td>
                                        <td class="border px-4 py-2 text-center"><?php echo $bus['capacity']; ?> seats</td>
                                        <td class="border px-4 py-2 text-center">
                                            <?php
                                            $status_class = '';
                                            switch($bus['status']) {
                                                case 'available':
                                                    $status_class = 'bg-green-100 text-green-800';
                                                    break;
                                                case 'booked':
                                                    $status_class = 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'maintenance':
                                                    $status_class = 'bg-orange-100 text-orange-800';
                                                    break;
                                                case 'out_of_service':
                                                    $status_class = 'bg-red-100 text-red-800';
                                                    break;
                                                default:
                                                    $status_class = 'bg-gray-100 text-gray-800';
                                            }
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $bus['status'])); ?>
                                            </span>
                                        </td>
                                        <td class="border px-4 py-2 text-sm text-gray-600">
                                            <?php echo date('M d, Y', strtotime($bus['created_at'])); ?>
                                        </td>
                                        <td class="border px-4 py-2 text-center">
                                            <div class="flex space-x-2 justify-center">
                                                <button onclick="openEditBusModal(<?php echo $bus['id']; ?>, '<?php echo htmlspecialchars($bus['bus_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bus['plate_number'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bus['vehicle_type'], ENT_QUOTES); ?>', <?php echo $bus['capacity']; ?>, '<?php echo $bus['status']; ?>')" 
                                                        class="text-blue-600 hover:text-blue-900" title="Edit Bus">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteBus(<?php echo $bus['id']; ?>, '<?php echo htmlspecialchars($bus['bus_number']); ?>')" 
                                                        class="text-red-600 hover:text-red-900" title="Delete Bus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="border px-4 py-8 text-center text-gray-500">
                                        <i class="fas fa-bus text-4xl mb-2"></i>
                                        <p>No buses found. Add your first bus above!</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; // End buses tab ?>
        
    </main>
</div>

<!-- View Schedule Details Modal -->
<div id="viewModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 overflow-y-auto p-4">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl my-8 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center px-6 py-4 border-b flex-shrink-0">
            <h3 class="text-xl font-semibold text-gray-900">Bus Schedule Details</h3>
            <button type="button" onclick="closeViewModal()" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 px-6 py-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500">Schedule ID</p>
                    <p id="view-schedule-id" class="text-base text-gray-900 font-semibold"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Status</p>
                    <p id="view-status" class="text-base"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Client</p>
                    <p id="view-client" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Destination</p>
                    <p id="view-destination" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Purpose</p>
                    <p id="view-purpose" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Date Covered</p>
                    <p id="view-date" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Vehicle Type</p>
                    <p id="view-vehicle" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Bus Number</p>
                    <p id="view-bus-no" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Number of Days</p>
                    <p id="view-days" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Number of Vehicles</p>
                    <p id="view-vehicles" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Requested By</p>
                    <p id="view-user" class="text-base text-gray-900"></p>
                    <p id="view-user-email" class="text-sm text-gray-500"></p>
                </div>
                <div id="view-or-container" class="hidden">
                    <p class="text-sm font-medium text-gray-500">
                        <i class="fas fa-receipt text-green-600 mr-1"></i>OR Number
                    </p>
                    <p id="view-or-number" class="text-base font-semibold text-blue-600"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Last Updated</p>
                    <p id="view-updated" class="text-base text-gray-900"></p>
                </div>
            </div>
            
            <!-- Approval Document Section -->
            <div id="approval-document-section" class="mt-6 border-t pt-4 hidden">
                <div class="mb-3">
                    <p class="text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-file-alt text-blue-600 mr-2"></i>President Approval Document
                    </p>
                </div>
                <div id="approval-document-container" class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <!-- Image preview for image files -->
                    <div id="approval-image-preview" class="hidden">
                        <img id="approval-image" src="" alt="Approval Document" class="max-w-full h-auto max-h-96 object-contain rounded-lg border border-gray-300 shadow-sm">
                        <div class="mt-3 flex justify-end">
                            <a id="approval-download-link-img" href="" download class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i class="fas fa-download mr-2"></i>Download Document
                            </a>
                        </div>
                    </div>
                    <!-- PDF preview for PDF files -->
                    <div id="approval-pdf-preview" class="hidden">
                        <div class="flex items-center justify-between p-4 bg-white rounded-md border border-gray-300">
                            <div class="flex items-center">
                                <i class="fas fa-file-pdf text-red-600 text-3xl mr-3"></i>
                                <div>
                                    <p class="text-sm font-medium text-gray-900" id="approval-pdf-name">Approval Document</p>
                                    <p class="text-xs text-gray-500">PDF Document</p>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                <a id="approval-view-link" href="" target="_blank" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    <i class="fas fa-eye mr-2"></i>View
                                </a>
                                <a id="approval-download-link-pdf" href="" download class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i class="fas fa-download mr-2"></i>Download
                                </a>
                            </div>
                        </div>
                    </div>
                    <!-- No document message -->
                    <div id="approval-no-document" class="text-center py-4 text-gray-500 hidden">
                        <i class="fas fa-file-slash text-gray-400 text-3xl mb-2"></i>
                        <p class="text-sm">No approval document uploaded</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex justify-end space-x-3 border-t px-6 py-4 flex-shrink-0 bg-gray-50 rounded-b-lg">
            <button type="button" onclick="closeViewModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Reject Schedule Confirmation Modal -->
<div id="rejectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-medium text-gray-900">Reject Bus Schedule</h3>
            </div>
        </div>
        
        <div class="mb-6">
            <p class="text-sm text-gray-600 mb-4">Are you sure you want to reject this bus schedule?</p>
            <div class="bg-gray-50 rounded-lg p-4 space-y-2">
                <div>
                    <p class="text-xs font-medium text-gray-500">Client:</p>
                    <p class="text-sm text-gray-900" id="reject-client"></p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500">Destination:</p>
                    <p class="text-sm text-gray-900" id="reject-destination"></p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                <i class="fas fa-info-circle mr-1"></i>
                The student will be notified that their request has been rejected.
            </p>
        </div>
        
        <form method="POST" id="rejectForm">
            <input type="hidden" name="action" value="reject_schedule">
            <input type="hidden" name="schedule_id" id="reject-schedule-id">
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    <i class="fas fa-times mr-2"></i>
                    Yes, Reject Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Complete Schedule Modal -->
<div id="completeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                <i class="fas fa-check-double text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-medium text-gray-900">Complete Bus Trip</h3>
            </div>
        </div>
        
        <div class="mb-6">
            <div class="bg-gray-50 rounded-lg p-4 space-y-2 mb-4">
                <div>
                    <p class="text-xs font-medium text-gray-500">Client:</p>
                    <p class="text-sm text-gray-900" id="complete-client"></p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500">Destination:</p>
                    <p class="text-sm text-gray-900" id="complete-destination"></p>
                </div>
            </div>
            
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-blue-800">
                            Mark this trip as completed?
                        </p>
                        <p class="text-xs text-blue-600 mt-1">
                            The bus trip has been finished. This will mark the schedule as completed and make the bus available again.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <form method="POST" id="completeForm">
            <input type="hidden" name="action" value="complete_schedule">
            <input type="hidden" name="schedule_id" id="complete-schedule-id">
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeCompleteModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-check-double mr-1"></i>
                    Yes, Mark as Completed
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Approve Schedule Modal -->
<div id="approveModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-medium text-gray-900">Approve Bus Schedule</h3>
            </div>
        </div>
        
        <div class="mb-6">
            <div class="bg-gray-50 rounded-lg p-4 space-y-2 mb-4">
                <div>
                    <p class="text-xs font-medium text-gray-500">Client:</p>
                    <p class="text-sm text-gray-900" id="approve-client"></p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500">Destination:</p>
                    <p class="text-sm text-gray-900" id="approve-destination"></p>
                </div>
            </div>
            
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-question-circle text-blue-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-blue-800">
                            Are you sure you want to approve this?
                        </p>
                        <p class="text-xs text-blue-600 mt-1">
                            Please ensure you have reviewed the president approval document before approving.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <form method="POST" id="approveForm">
            <input type="hidden" name="action" value="approve_schedule">
            <input type="hidden" name="schedule_id" id="approve-schedule-id">
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeApproveModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                    Cancel
                </button>
                <button type="button" onclick="submitApproval()" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i class="fas fa-check mr-2"></i>
                    Yes, Approve Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Bus Modal -->
<div id="editBusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                <i class="fas fa-edit text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-medium text-gray-900">Edit Bus</h3>
            </div>
        </div>
        
        <form method="POST" id="editBusForm">
            <input type="hidden" name="action" value="update_bus">
            <input type="hidden" name="bus_id" id="edit-bus-id">
            
            <div class="space-y-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bus Number <span class="text-red-500">*</span></label>
                    <input type="text" name="bus_number" id="edit-bus-number" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g., 4, 5, Bus-001"
                           oninput="preventSymbols(this)"
                           onkeypress="return !/[!@#$%^&*()_+={}\[\]:;&quot;'<>?\/\\|`~,.]/.test(event.key)">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Plate Number</label>
                    <input type="text" name="plate_number" id="edit-plate-number"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g., ABC-1234"
                           oninput="preventSymbols(this)"
                           onkeypress="return !/[!@#$%^&*()_+={}\[\]:;&quot;'<>?\/\\|`~,.]/.test(event.key)">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Type <span class="text-red-500">*</span></label>
                    <input type="hidden" name="vehicle_type" id="edit-vehicle-type" value="Bus">
                    <div class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-700">
                        BUS
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Capacity <span class="text-red-500">*</span></label>
                    <input type="number" name="capacity" id="edit-capacity" required min="1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g., 50">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status <span class="text-red-500">*</span></label>
                    <select name="status" id="edit-status" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="available">Available</option>
                        <option value="booked">Booked</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="out_of_service">Out of Service</option>
                    </select>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeEditBusModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                    <i class="fas fa-save mr-2"></i>Update Bus
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Change Bus Status Modal -->
<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                <i class="fas fa-edit text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-medium text-gray-900">Change Bus Status</h3>
            </div>
        </div>
        
        <div class="mb-6">
            <p class="text-sm text-gray-600 mb-4">Update the status of Bus <strong id="status-bus-number"></strong></p>
            <form method="POST" id="statusForm">
                <input type="hidden" name="action" value="update_bus_status">
                <input type="hidden" name="bus_id" id="status-bus-id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">New Status</label>
                    <select name="new_status" id="status-new-status" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="available">Available</option>
                        <option value="booked">Booked</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="out_of_service">Out of Service</option>
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeStatusModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i>Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Bus Confirmation Modal -->
<div id="deleteBusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-medium text-gray-900">Delete Bus</h3>
            </div>
        </div>
        
        <div class="mb-6">
            <p class="text-sm text-gray-600 mb-2">
                Are you sure you want to delete <strong id="delete-bus-number"></strong>?
            </p>
            <p class="text-sm text-red-600 font-semibold">
                ⚠️ Warning: This action cannot be undone and will also delete all associated bookings.
            </p>
        </div>
        
        <form method="POST" id="deleteBusForm">
            <input type="hidden" name="action" value="delete_bus">
            <input type="hidden" name="bus_id" id="delete-bus-id">
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeDeleteBusModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700">
                    <i class="fas fa-trash mr-2"></i>Delete Bus
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// View Schedule Functions
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners to all view buttons
    document.querySelectorAll('.view-schedule-btn').forEach(button => {
        button.addEventListener('click', function() {
            const scheduleData = JSON.parse(this.getAttribute('data-schedule'));
            openViewModal(scheduleData);
        });
    });
});

function openViewModal(schedule) {
    // Populate modal with schedule data
    document.getElementById('view-schedule-id').textContent = schedule.id;
    document.getElementById('view-client').textContent = schedule.client || '-';
    document.getElementById('view-destination').textContent = schedule.destination || '-';
    document.getElementById('view-purpose').textContent = schedule.purpose || '-';
    document.getElementById('view-date').textContent = schedule.date_covered ? new Date(schedule.date_covered).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '-';
    document.getElementById('view-vehicle').textContent = schedule.vehicle || '-';
    document.getElementById('view-bus-no').textContent = schedule.bus_no || '-';
    document.getElementById('view-days').textContent = schedule.no_of_days || '-';
    document.getElementById('view-vehicles').textContent = schedule.no_of_vehicles || '-';
    document.getElementById('view-user').textContent = schedule.user_name || 'N/A';
    document.getElementById('view-user-email').textContent = schedule.user_email || '';
    
    // Display OR Number if available
    const orContainer = document.getElementById('view-or-container');
    const orNumberElement = document.getElementById('view-or-number');
    if (schedule.or_number && schedule.or_number.trim() !== '') {
        orNumberElement.textContent = schedule.or_number;
        orContainer.classList.remove('hidden');
    } else {
        orContainer.classList.add('hidden');
    }
    
    // Format date with time
    if (schedule.updated_at) {
        const date = new Date(schedule.updated_at);
        const formattedDate = date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'numeric', 
            day: 'numeric' 
        });
        const formattedTime = date.toLocaleTimeString('en-US', { 
            hour: 'numeric', 
            minute: '2-digit',
            hour12: true
        });
        document.getElementById('view-updated').textContent = formattedDate + ', ' + formattedTime;
    } else {
        document.getElementById('view-updated').textContent = '-';
    }
    
    // Set status with styling
    const statusElement = document.getElementById('view-status');
    const status = (schedule.status || 'unknown').toLowerCase();
    let statusText = 'Pending';
    
    statusElement.className = 'text-base px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full';
    switch(status) {
        case 'pending':
            statusElement.classList.add('bg-yellow-100', 'text-yellow-800');
            statusText = 'Pending';
            break;
        case 'approved':
            statusElement.classList.add('bg-green-100', 'text-green-800');
            statusText = 'Approved';
            break;
        case 'completed':
            statusElement.classList.add('bg-blue-100', 'text-blue-800');
            statusText = 'Completed';
            break;
        case 'rejected':
            statusElement.classList.add('bg-red-100', 'text-red-800');
            statusText = 'Rejected';
            break;
        case 'cancelled':
        case 'canceled':
            statusElement.classList.add('bg-gray-100', 'text-gray-800');
            statusText = 'Cancelled';
            break;
        default:
            // For any unknown status, default to Cancelled
            statusElement.classList.add('bg-gray-100', 'text-gray-800');
            statusText = 'Cancelled';
            break;
    }
    statusElement.textContent = statusText;
    
    // Handle approval document display
    const approvalSection = document.getElementById('approval-document-section');
    const approvalImagePreview = document.getElementById('approval-image-preview');
    const approvalPdfPreview = document.getElementById('approval-pdf-preview');
    const approvalNoDocument = document.getElementById('approval-no-document');
    
    // Hide all preview sections first
    approvalImagePreview.classList.add('hidden');
    approvalPdfPreview.classList.add('hidden');
    approvalNoDocument.classList.add('hidden');
    
    if (schedule.approval_document && schedule.approval_document.trim() !== '') {
        approvalSection.classList.remove('hidden');
        const docPath = '../' + schedule.approval_document;
        const fileName = schedule.approval_document.split('/').pop();
        const fileExtension = fileName.split('.').pop().toLowerCase();
        
        // Check if it's an image or PDF
        if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) {
            // Display as image
            document.getElementById('approval-image').src = docPath;
            document.getElementById('approval-download-link-img').href = docPath;
            document.getElementById('approval-download-link-img').download = fileName;
            approvalImagePreview.classList.remove('hidden');
        } else if (fileExtension === 'pdf') {
            // Display PDF controls
            document.getElementById('approval-pdf-name').textContent = fileName;
            document.getElementById('approval-view-link').href = docPath;
            document.getElementById('approval-download-link-pdf').href = docPath;
            document.getElementById('approval-download-link-pdf').download = fileName;
            approvalPdfPreview.classList.remove('hidden');
        } else {
            // Unknown file type
            approvalNoDocument.classList.remove('hidden');
        }
    } else {
        // No document uploaded
        approvalSection.classList.remove('hidden');
        approvalNoDocument.classList.remove('hidden');
    }
    
    document.getElementById('viewModal').classList.remove('hidden');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.add('hidden');
}

function openRejectModal(scheduleId, client, destination) {
    document.getElementById('reject-schedule-id').value = scheduleId;
    document.getElementById('reject-client').textContent = client;
    document.getElementById('reject-destination').textContent = destination;
    document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
}

// Approve Schedule Functions
function openApproveModal(scheduleId, client, destination) {
    document.getElementById('approve-schedule-id').value = scheduleId;
    document.getElementById('approve-client').textContent = client;
    document.getElementById('approve-destination').textContent = destination;
    document.getElementById('approveModal').classList.remove('hidden');
}

function closeApproveModal() {
    document.getElementById('approveModal').classList.add('hidden');
}

function submitApproval() {
    // Submit the form directly
    document.getElementById('approveForm').submit();
}

// Complete Schedule Functions
function openCompleteModal(scheduleId, client, destination) {
    document.getElementById('complete-schedule-id').value = scheduleId;
    document.getElementById('complete-client').textContent = client;
    document.getElementById('complete-destination').textContent = destination;
    document.getElementById('completeModal').classList.remove('hidden');
}

function closeCompleteModal() {
    document.getElementById('completeModal').classList.add('hidden');
}

// Bus Status Management Functions
function openStatusModal(busId, busNumber, currentStatus) {
    document.getElementById('status-bus-id').value = busId;
    document.getElementById('status-bus-number').textContent = busNumber;
    document.getElementById('status-new-status').value = currentStatus;
    document.getElementById('statusModal').classList.remove('hidden');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
}

// Prevent symbols function
function preventSymbols(input) {
    const invalidSymbols = /[!@#$%^&*()_+={}\[\]:;"'<>?\/\\|`~,.]/g;
    input.value = input.value.replace(invalidSymbols, '');
}

// Bus Edit Functions
function openEditBusModal(busId, busNumber, plateNumber, vehicleType, capacity, status) {
    document.getElementById('edit-bus-id').value = busId;
    document.getElementById('edit-bus-number').value = busNumber;
    document.getElementById('edit-plate-number').value = plateNumber || '';
    document.getElementById('edit-vehicle-type').value = vehicleType;
    document.getElementById('edit-capacity').value = capacity;
    document.getElementById('edit-status').value = status;
    document.getElementById('editBusModal').classList.remove('hidden');
}

function closeEditBusModal() {
    document.getElementById('editBusModal').classList.add('hidden');
}

// Bus Deletion Functions
function deleteBus(busId, busNumber) {
    document.getElementById('delete-bus-id').value = busId;
    document.getElementById('delete-bus-number').textContent = 'Bus ' + busNumber;
    document.getElementById('deleteBusModal').classList.remove('hidden');
}

function closeDeleteBusModal() {
    document.getElementById('deleteBusModal').classList.add('hidden');
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    if (event.target.id === 'viewModal') {
        closeViewModal();
    }
    if (event.target.id === 'rejectModal') {
        closeRejectModal();
    }
    if (event.target.id === 'statusModal') {
        closeStatusModal();
    }
    if (event.target.id === 'editBusModal') {
        closeEditBusModal();
    }
    if (event.target.id === 'deleteBusModal') {
        closeDeleteBusModal();
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeViewModal();
        closeRejectModal();
        closeStatusModal();
        closeEditBusModal();
        closeDeleteBusModal();
    }
});

// Prevent symbols in Bus Number and Plate Number fields on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add Bus form fields
    const addBusNumber = document.getElementById('add-bus-number');
    const addPlateNumber = document.getElementById('add-plate-number');
    
    if (addBusNumber) {
        addBusNumber.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const cleanedText = pastedText.replace(/[!@#$%^&*()_+={}\[\]:;"'<>?\/\\|`~,.]/g, '');
            this.value = cleanedText;
        });
    }
    
    if (addPlateNumber) {
        addPlateNumber.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const cleanedText = pastedText.replace(/[!@#$%^&*()_+={}\[\]:;"'<>?\/\\|`~,.]/g, '');
            this.value = cleanedText;
        });
    }
    
    // Edit Bus form fields (will be available when modal opens)
    const editBusNumber = document.getElementById('edit-bus-number');
    const editPlateNumber = document.getElementById('edit-plate-number');
    
    if (editBusNumber) {
        editBusNumber.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const cleanedText = pastedText.replace(/[!@#$%^&*()_+={}\[\]:;"'<>?\/\\|`~,.]/g, '');
            this.value = cleanedText;
        });
    }
    
    if (editPlateNumber) {
        editPlateNumber.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const cleanedText = pastedText.replace(/[!@#$%^&*()_+={}\[\]:;"'<>?\/\\|`~,.]/g, '');
            this.value = cleanedText;
        });
    }
});
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html> 