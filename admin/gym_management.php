<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

// Get user data based on active user type (admin or secretary)
$active_type = $_SESSION['active_user_type'];
$user_id = $_SESSION['user_sessions'][$active_type]['user_id'];
$user_name = $_SESSION['user_sessions'][$active_type]['user_name'];

$page_title = "Gym Management - CHMSU BAO";
$base_url = "..";

// Create gym_event_types table if it doesn't exist
$create_table_sql = "CREATE TABLE IF NOT EXISTS gym_event_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$conn->query($create_table_sql);

// Create gym_pricing_settings table if it doesn't exist
$create_pricing_table_sql = "CREATE TABLE IF NOT EXISTS gym_pricing_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value DECIMAL(10, 2) DEFAULT 0.00,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$conn->query($create_pricing_table_sql);

// Insert default pricing if table is empty
$check_pricing = $conn->query("SELECT COUNT(*) as count FROM gym_pricing_settings");
if ($check_pricing && $check_pricing->fetch_assoc()['count'] == 0) {
    $default_pricing = [
        ['key' => 'gymnasium_per_hour', 'value' => 700.00, 'description' => 'Gymnasium rental cost per hour'],
        ['key' => 'sound_system_per_hour', 'value' => 150.00, 'description' => 'Sound system rental cost per hour'],
        ['key' => 'electricity_per_hour', 'value' => 150.00, 'description' => 'Electricity cost per hour'],
        ['key' => 'chair_free_limit', 'value' => 200.00, 'description' => 'Number of free chairs'],
        ['key' => 'chair_cost_per_unit', 'value' => 8.00, 'description' => 'Cost per chair beyond free limit']
    ];
    $pricing_stmt = $conn->prepare("INSERT INTO gym_pricing_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
    foreach ($default_pricing as $pricing) {
        $pricing_stmt->bind_param("sds", $pricing['key'], $pricing['value'], $pricing['description']);
        $pricing_stmt->execute();
    }
}

// Create gym_equipment_services table if it doesn't exist
$create_equipment_table_sql = "CREATE TABLE IF NOT EXISTS gym_equipment_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    type ENUM('equipment', 'service') DEFAULT 'equipment',
    cost_per_hour DECIMAL(10, 2) DEFAULT 0.00,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$conn->query($create_equipment_table_sql);

// Insert default equipment/services if table is empty
$check_equipment_defaults = $conn->query("SELECT COUNT(*) as count FROM gym_equipment_services");
if ($check_equipment_defaults && $check_equipment_defaults->fetch_assoc()['count'] == 0) {
    $default_equipment = [
        ['name' => 'Sound System', 'type' => 'equipment', 'cost_per_hour' => 150.00, 'description' => 'Audio system for events'],
        ['name' => 'Electricity', 'type' => 'service', 'cost_per_hour' => 150.00, 'description' => 'Electrical power supply'],
        ['name' => 'Chairs', 'type' => 'equipment', 'cost_per_hour' => 0.00, 'description' => 'Seating chairs (first 200 free, then ₱8 per chair)']
    ];
    $equipment_stmt = $conn->prepare("INSERT INTO gym_equipment_services (name, type, cost_per_hour, description) VALUES (?, ?, ?, ?)");
    foreach ($default_equipment as $equip) {
        $equipment_stmt->bind_param("ssds", $equip['name'], $equip['type'], $equip['cost_per_hour'], $equip['description']);
        $equipment_stmt->execute();
    }
}

// Insert default event types if table is empty
$check_defaults = $conn->query("SELECT COUNT(*) as count FROM gym_event_types");
if ($check_defaults && $check_defaults->fetch_assoc()['count'] == 0) {
    $default_types = [
        'Badminton', 'Basketball', 'Volleyball', 'Graduation Ceremony',
        'Sports Tournament', 'Conference', 'Cultural Event', 'School Program', 'Other'
    ];
    $stmt = $conn->prepare("INSERT INTO gym_event_types (name) VALUES (?)");
    foreach ($default_types as $type) {
        $stmt->bind_param("s", $type);
        $stmt->execute();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Approve booking
        if ($_POST['action'] === 'approve' && isset($_POST['booking_id'])) {
            $booking_id = sanitize_input($_POST['booking_id']);
            $remarks = sanitize_input($_POST['remarks'] ?? '');
            $user_type = sanitize_input($_POST['user_type'] ?? 'student');
            
            // Get user type from booking to verify
            $get_user_type = $conn->prepare("SELECT u.user_type FROM bookings b JOIN user_accounts u ON b.user_id = u.id WHERE b.booking_id = ?");
            $get_user_type->bind_param("s", $booking_id);
            $get_user_type->execute();
            $user_type_result = $get_user_type->get_result();
            
            $or_number = '';
            if ($user_type_result->num_rows > 0) {
                $booking_user_type = $user_type_result->fetch_assoc()['user_type'];
                // Require OR number only for external users
                if ($booking_user_type === 'external') {
            $or_number = sanitize_input($_POST['or_number'] ?? '');
                    if (empty($or_number)) {
                        $_SESSION['error'] = "OR number is required for external users.";
                        header("Location: gym_management.php");
                        exit();
                    } elseif (!preg_match('/^[0-9]{7}$/', $or_number)) {
                        $_SESSION['error'] = "OR number must be exactly 7 digits.";
                        header("Location: gym_management.php");
                        exit();
                    }
                }
            }
            
            // Update booking with status, OR number (if external), and remarks
            $stmt = $conn->prepare("UPDATE bookings SET status = 'confirmed', or_number = ?, additional_info = JSON_SET(COALESCE(additional_info, '{}'), '$.admin_remarks', ?) WHERE booking_id = ? AND facility_type = 'gym'");
            $stmt->bind_param("sss", $or_number, $remarks, $booking_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Reservation has been approved successfully.";
                
                // Send notification to user (only if user exists)
                require_once '../includes/notification_functions.php';
                $get_booking = $conn->prepare("SELECT b.user_id, b.date, u.id as user_exists FROM bookings b LEFT JOIN user_accounts u ON b.user_id = u.id WHERE b.booking_id = ?");
                $get_booking->bind_param("s", $booking_id);
                $get_booking->execute();
                $booking_result = $get_booking->get_result();
                if ($booking_data = $booking_result->fetch_assoc()) {
                    // Only create notification if user exists
                    if (!empty($booking_data['user_exists'])) {
                    $date_formatted = date('F j, Y', strtotime($booking_data['date']));
                    // Determine correct link based on user type
                    $get_user_type_link = $conn->prepare("SELECT u.user_type FROM bookings b JOIN user_accounts u ON b.user_id = u.id WHERE b.booking_id = ?");
                    $get_user_type_link->bind_param("s", $booking_id);
                    $get_user_type_link->execute();
                    $user_type_link_result = $get_user_type_link->get_result();
                    $notification_link = "student/gym.php"; // Default for students
                    if ($user_type_link_result->num_rows > 0) {
                        $user_type_for_link = $user_type_link_result->fetch_assoc()['user_type'];
                        if ($user_type_for_link === 'external') {
                            $notification_link = "external/gym.php";
                        } elseif ($user_type_for_link === 'student') {
                            $notification_link = "student/gym.php";
                        }
                    }
                    create_notification($booking_data['user_id'], "Gym Reservation Approved", "Your gym reservation (ID: {$booking_id}) for {$date_formatted} has been approved!", "success", $notification_link);
                    }
                }
            } else {
                $_SESSION['error'] = "Error approving reservation: " . $conn->error;
            }
        }

        // Reject booking
        elseif ($_POST['action'] === 'reject' && isset($_POST['booking_id'])) {
            $booking_id = sanitize_input($_POST['booking_id']);
            $remarks = sanitize_input($_POST['remarks'] ?? '');
            
            if (empty($remarks)) {
                $_SESSION['error'] = "Rejection reason is required.";
            } else {
                $stmt = $conn->prepare("UPDATE bookings SET status = 'rejected', additional_info = JSON_SET(COALESCE(additional_info, '{}'), '$.admin_remarks', ?) WHERE booking_id = ? AND facility_type = 'gym'");
                $stmt->bind_param("ss", $remarks, $booking_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Reservation has been rejected.";
                    
                    // Send notification to user (only if user exists)
                    require_once '../includes/notification_functions.php';
                    $get_booking = $conn->prepare("SELECT b.user_id, b.date, u.id as user_exists FROM bookings b LEFT JOIN user_accounts u ON b.user_id = u.id WHERE b.booking_id = ?");
                    $get_booking->bind_param("s", $booking_id);
                    $get_booking->execute();
                    $booking_result = $get_booking->get_result();
                    if ($booking_data = $booking_result->fetch_assoc()) {
                        // Only create notification if user exists
                        if (!empty($booking_data['user_exists'])) {
                        $date_formatted = date('F j, Y', strtotime($booking_data['date']));
                        $reason = !empty($remarks) ? " Reason: {$remarks}" : "";
                        // Determine correct link based on user type
                        $get_user_type_link = $conn->prepare("SELECT u.user_type FROM bookings b JOIN user_accounts u ON b.user_id = u.id WHERE b.booking_id = ?");
                        $get_user_type_link->bind_param("s", $booking_id);
                        $get_user_type_link->execute();
                        $user_type_link_result = $get_user_type_link->get_result();
                        $notification_link = "student/gym.php"; // Default for students
                        if ($user_type_link_result->num_rows > 0) {
                            $user_type_for_link = $user_type_link_result->fetch_assoc()['user_type'];
                            if ($user_type_for_link === 'external') {
                                $notification_link = "external/gym.php";
                            } elseif ($user_type_for_link === 'student') {
                                $notification_link = "student/gym.php";
                            }
                        }
                        create_notification($booking_data['user_id'], "Gym Reservation Rejected", "Your gym reservation (ID: {$booking_id}) for {$date_formatted} has been rejected.{$reason}", "error", $notification_link);
                        }
                    }
                } else {
                    $_SESSION['error'] = "Error rejecting reservation: " . $conn->error;
                }
            }
        }
        
        // Reschedule booking
        elseif ($_POST['action'] === 'reschedule' && isset($_POST['booking_id'])) {
            $booking_id = sanitize_input($_POST['booking_id']);
            $new_date = sanitize_input($_POST['new_date']);
            $new_start_time = sanitize_input($_POST['new_start_time']);
            $new_end_time = sanitize_input($_POST['new_end_time']);
            $reschedule_reason = sanitize_input($_POST['reschedule_reason'] ?? '');
            
            // Validate inputs
            if (empty($new_date) || empty($new_start_time) || empty($new_end_time)) {
                $_SESSION['error'] = "Please fill in all required fields (date, start time, and end time).";
            } elseif ($new_start_time >= $new_end_time) {
                $_SESSION['error'] = "End time must be after start time.";
            } else {
                // Check if booking exists
                $check_stmt = $conn->prepare("SELECT * FROM bookings WHERE booking_id = ? AND facility_type = 'gym'");
                $check_stmt->bind_param("s", $booking_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $booking = $check_result->fetch_assoc();
                    
                    // Check if new date/time slot is available (exclude current booking)
                    $availability_check = $conn->prepare("SELECT * FROM bookings 
                        WHERE facility_type = 'gym' 
                        AND date = ? 
                        AND booking_id != ? 
                        AND status IN ('pending', 'confirmed', 'approved') 
                        AND ((start_time <= ? AND end_time > ?) 
                        OR (start_time < ? AND end_time >= ?) 
                        OR (start_time >= ? AND end_time <= ?))");
                    $availability_check->bind_param("ssssssss", $new_date, $booking_id, $new_start_time, $new_start_time, $new_end_time, $new_end_time, $new_start_time, $new_end_time);
                    $availability_check->execute();
                    $availability_result = $availability_check->get_result();
                    
                    if ($availability_result->num_rows > 0) {
                        $_SESSION['error'] = "The selected date and time slot is already booked. Please choose another time.";
                    } else {
                        // Check if date is blocked by school event
                        $blocked_check = $conn->prepare("SELECT * FROM gym_blocked_dates 
                            WHERE ? BETWEEN start_date AND end_date 
                            AND is_active = 1");
                        $blocked_check->bind_param("s", $new_date);
                        $blocked_check->execute();
                        $blocked_result = $blocked_check->get_result();
                        
                        if ($blocked_result->num_rows > 0) {
                            $_SESSION['error'] = "The selected date is blocked due to a school event. Please choose another date.";
                        } else {
                            // Update booking with new date and time
                            $update_stmt = $conn->prepare("UPDATE bookings 
                                SET date = ?, start_time = ?, end_time = ?, 
                                additional_info = JSON_SET(COALESCE(additional_info, '{}'), '$.reschedule_reason', ?, '$.rescheduled_by_admin', 1, '$.rescheduled_at', NOW()),
                                updated_at = NOW() 
                                WHERE booking_id = ? AND facility_type = 'gym'");
                            $update_stmt->bind_param("sssss", $new_date, $new_start_time, $new_end_time, $reschedule_reason, $booking_id);
                            
                            if ($update_stmt->execute()) {
                                $_SESSION['success'] = "Reservation has been rescheduled successfully.";
                                
                                // Send notification to user
                                require_once '../includes/notification_functions.php';
                                $booking_user_id = $booking['user_id'];
                                $date_formatted = date('F j, Y', strtotime($new_date));
                                $old_date_formatted = date('F j, Y', strtotime($booking['date']));
                                
                                // Determine correct link based on user type
                                $get_user_type_link = $conn->prepare("SELECT u.user_type FROM bookings b JOIN user_accounts u ON b.user_id = u.id WHERE b.booking_id = ?");
                                $get_user_type_link->bind_param("s", $booking_id);
                                $get_user_type_link->execute();
                                $user_type_link_result = $get_user_type_link->get_result();
                                $notification_link = "student/gym.php"; // Default for students
                                if ($user_type_link_result->num_rows > 0) {
                                    $user_type_for_link = $user_type_link_result->fetch_assoc()['user_type'];
                                    if ($user_type_for_link === 'external') {
                                        $notification_link = "external/gym.php";
                                    } elseif ($user_type_for_link === 'student') {
                                        $notification_link = "student/gym.php";
                                    } elseif ($user_type_for_link === 'faculty' || $user_type_for_link === 'staff') {
                                        $notification_link = "faculty/gym.php";
                                    }
                                }
                                
                                $reason_text = !empty($reschedule_reason) ? " Reason: {$reschedule_reason}" : "";
                                create_notification($booking_user_id, "Gym Reservation Rescheduled", "Your gym reservation (ID: {$booking_id}) has been rescheduled from {$old_date_formatted} to {$date_formatted}.{$reason_text}", "info", $notification_link);
                            } else {
                                $_SESSION['error'] = "Error rescheduling reservation: " . $conn->error;
                            }
                        }
                    }
                } else {
                    $_SESSION['error'] = "Reservation not found.";
                }
            }
        }
        
        // Add new facility
        elseif ($_POST['action'] === 'add_facility') {
            $facility_name = sanitize_input($_POST['facility_name']);
            $capacity = (int)$_POST['capacity'];
            $description = sanitize_input($_POST['description']);
            
            if (empty($facility_name)) {
                $_SESSION['error'] = "Facility name is required.";
            } else {
                $stmt = $conn->prepare("INSERT INTO gym_facilities (name, capacity, description, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
                $stmt->bind_param("sis", $facility_name, $capacity, $description);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "New facility has been added successfully.";
                } else {
                    $_SESSION['error'] = "Error adding facility: " . $conn->error;
                }
            }
        }
        
        // Update facility
        elseif ($_POST['action'] === 'update_facility' && isset($_POST['facility_id'])) {
            $facility_id = (int)$_POST['facility_id'];
            $facility_name = sanitize_input($_POST['facility_name']);
            $capacity = (int)$_POST['capacity'];
            $description = sanitize_input($_POST['description']);
            $status = sanitize_input($_POST['status']);
            
            if (empty($facility_name)) {
                $_SESSION['error'] = "Facility name is required.";
            } else {
                $stmt = $conn->prepare("UPDATE gym_facilities SET name = ?, capacity = ?, description = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sissi", $facility_name, $capacity, $description, $status, $facility_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Facility has been updated successfully.";
                } else {
                    $_SESSION['error'] = "Error updating facility: " . $conn->error;
                }
            }
        }
        
        // Delete facility
        elseif ($_POST['action'] === 'delete_facility' && isset($_POST['facility_id'])) {
            $facility_id = (int)$_POST['facility_id'];
            
            // Check if facility is in use
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM gym_bookings WHERE facility_id = ? AND booking_date >= CURDATE()");
            $check_stmt->bind_param("i", $facility_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] > 0) {
                $_SESSION['error'] = "Cannot delete facility as it has upcoming bookings.";
            } else {
                $stmt = $conn->prepare("DELETE FROM gym_facilities WHERE id = ?");
                $stmt->bind_param("i", $facility_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Facility has been deleted successfully.";
                } else {
                    $_SESSION['error'] = "Error deleting facility: " . $conn->error;
                }
            }
        }
        
        // Add new event type
        elseif ($_POST['action'] === 'add_event_type') {
            $event_type_name = sanitize_input($_POST['event_type_name']);
            
            if (empty($event_type_name)) {
                $_SESSION['error'] = "Event type name is required.";
            } else {
                $stmt = $conn->prepare("INSERT INTO gym_event_types (name) VALUES (?)");
                $stmt->bind_param("s", $event_type_name);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Event type has been added successfully.";
                } else {
                    if ($conn->errno == 1062) {
                        $_SESSION['error'] = "Event type already exists.";
                    } else {
                        $_SESSION['error'] = "Error adding event type: " . $conn->error;
                    }
                }
            }
        }
        
        // Update event type
        elseif ($_POST['action'] === 'update_event_type' && isset($_POST['event_type_id'])) {
            $event_type_id = (int)$_POST['event_type_id'];
            $event_type_name = sanitize_input($_POST['event_type_name']);
            
            if (empty($event_type_name)) {
                $_SESSION['error'] = "Event type name is required.";
            } else {
                $stmt = $conn->prepare("UPDATE gym_event_types SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $event_type_name, $event_type_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Event type has been updated successfully.";
                } else {
                    if ($conn->errno == 1062) {
                        $_SESSION['error'] = "Event type already exists.";
                    } else {
                        $_SESSION['error'] = "Error updating event type: " . $conn->error;
                    }
                }
            }
        }
        
        // Delete event type
        elseif ($_POST['action'] === 'delete_event_type' && isset($_POST['event_type_id'])) {
            $event_type_id = (int)$_POST['event_type_id'];
            
            // Check if event type is in use (don't allow deletion if it's "Other")
            $check_stmt = $conn->prepare("SELECT name FROM gym_event_types WHERE id = ?");
            $check_stmt->bind_param("i", $event_type_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row && $row['name'] === 'Other') {
                $_SESSION['error'] = "Cannot delete 'Other' event type as it is required by the system.";
            } else {
                $stmt = $conn->prepare("DELETE FROM gym_event_types WHERE id = ?");
                $stmt->bind_param("i", $event_type_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Event type has been deleted successfully.";
                } else {
                    $_SESSION['error'] = "Error deleting event type: " . $conn->error;
                }
            }
        }
        
        // Toggle event type status
        elseif ($_POST['action'] === 'toggle_event_type_status' && isset($_POST['event_type_id'])) {
            $event_type_id = (int)$_POST['event_type_id'];
            
            $stmt = $conn->prepare("UPDATE gym_event_types SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param("i", $event_type_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Event type status has been updated successfully.";
            } else {
                $_SESSION['error'] = "Error updating event type status: " . $conn->error;
            }
        }
        
        // Add new equipment/service
        elseif ($_POST['action'] === 'add_equipment_service') {
            $equipment_name = sanitize_input($_POST['equipment_name']);
            $equipment_type = sanitize_input($_POST['equipment_type']);
            $cost_per_hour = isset($_POST['cost_per_hour']) ? (float)$_POST['cost_per_hour'] : 0.00;
            $description = sanitize_input($_POST['description'] ?? '');
            
            if (empty($equipment_name)) {
                $_SESSION['error'] = "Equipment/Service name is required.";
            } else {
                $stmt = $conn->prepare("INSERT INTO gym_equipment_services (name, type, cost_per_hour, description) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssds", $equipment_name, $equipment_type, $cost_per_hour, $description);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Equipment/Service has been added successfully.";
                } else {
                    if ($conn->errno == 1062) {
                        $_SESSION['error'] = "Equipment/Service already exists.";
                    } else {
                        $_SESSION['error'] = "Error adding equipment/service: " . $conn->error;
                    }
                }
            }
        }
        
        // Update equipment/service
        elseif ($_POST['action'] === 'update_equipment_service' && isset($_POST['equipment_id'])) {
            $equipment_id = (int)$_POST['equipment_id'];
            $equipment_name = sanitize_input($_POST['equipment_name']);
            $equipment_type = sanitize_input($_POST['equipment_type']);
            $cost_per_hour = isset($_POST['cost_per_hour']) ? (float)$_POST['cost_per_hour'] : 0.00;
            $description = sanitize_input($_POST['description'] ?? '');
            
            if (empty($equipment_name)) {
                $_SESSION['error'] = "Equipment/Service name is required.";
            } else {
                $stmt = $conn->prepare("UPDATE gym_equipment_services SET name = ?, type = ?, cost_per_hour = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssdsi", $equipment_name, $equipment_type, $cost_per_hour, $description, $equipment_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Equipment/Service has been updated successfully.";
                } else {
                    if ($conn->errno == 1062) {
                        $_SESSION['error'] = "Equipment/Service already exists.";
                    } else {
                        $_SESSION['error'] = "Error updating equipment/service: " . $conn->error;
                    }
                }
            }
        }
        
        // Delete equipment/service
        elseif ($_POST['action'] === 'delete_equipment_service' && isset($_POST['equipment_id'])) {
            $equipment_id = (int)$_POST['equipment_id'];
            
            $stmt = $conn->prepare("DELETE FROM gym_equipment_services WHERE id = ?");
            $stmt->bind_param("i", $equipment_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Equipment/Service has been deleted successfully.";
            } else {
                $_SESSION['error'] = "Error deleting equipment/service: " . $conn->error;
            }
        }
        
        // Toggle equipment/service status
        elseif ($_POST['action'] === 'toggle_equipment_service_status' && isset($_POST['equipment_id'])) {
            $equipment_id = (int)$_POST['equipment_id'];
            
            $stmt = $conn->prepare("UPDATE gym_equipment_services SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param("i", $equipment_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Equipment/Service status has been updated successfully.";
            } else {
                $_SESSION['error'] = "Error updating equipment/service status: " . $conn->error;
            }
        }
        
        // Update pricing settings
        elseif ($_POST['action'] === 'update_pricing') {
            $gymnasium_per_hour = isset($_POST['gymnasium_per_hour']) ? (float)$_POST['gymnasium_per_hour'] : 700.00;
            $sound_system_per_hour = isset($_POST['sound_system_per_hour']) ? (float)$_POST['sound_system_per_hour'] : 150.00;
            $electricity_per_hour = isset($_POST['electricity_per_hour']) ? (float)$_POST['electricity_per_hour'] : 150.00;
            $chair_free_limit = isset($_POST['chair_free_limit']) ? (float)$_POST['chair_free_limit'] : 200.00;
            $chair_cost_per_unit = isset($_POST['chair_cost_per_unit']) ? (float)$_POST['chair_cost_per_unit'] : 8.00;
            
            $pricing_updates = [
                'gymnasium_per_hour' => $gymnasium_per_hour,
                'sound_system_per_hour' => $sound_system_per_hour,
                'electricity_per_hour' => $electricity_per_hour,
                'chair_free_limit' => $chair_free_limit,
                'chair_cost_per_unit' => $chair_cost_per_unit
            ];
            
            $update_stmt = $conn->prepare("UPDATE gym_pricing_settings SET setting_value = ? WHERE setting_key = ?");
            $success = true;
            
            foreach ($pricing_updates as $key => $value) {
                $update_stmt->bind_param("ds", $value, $key);
                if (!$update_stmt->execute()) {
                    $success = false;
                    break;
                }
            }
            
            if ($success) {
                $_SESSION['success'] = "Pricing settings have been updated successfully.";
            } else {
                $_SESSION['error'] = "Error updating pricing settings: " . $conn->error;
            }
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: gym_management.php");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_filter = isset($_GET['date']) ? $_GET['date'] : 'all';
$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';

// Build the query based on filters
$query_conditions = [];
$query_params = [];
$param_types = "";

// Always filter by gym facility type
$query_conditions[] = "b.facility_type = 'gym'";

if ($status_filter != 'all') {
    if ($status_filter == 'approved') {
        $query_conditions[] = "(b.status = 'approved' OR b.status = 'confirmed')";
    } else {
        $query_conditions[] = "b.status = ?";
        $query_params[] = $status_filter;
        $param_types .= "s";
    }
}

if ($date_filter == 'upcoming') {
    $query_conditions[] = "b.date >= CURDATE()";
} elseif ($date_filter == 'past') {
    $query_conditions[] = "b.date < CURDATE()";
} elseif ($date_filter != 'all' && !empty($date_filter)) {
    // If a specific date is selected
    $query_conditions[] = "b.date = ?";
    $query_params[] = $date_filter;
    $param_types .= "s";
}

// Facility filter removed - no longer filtering by facility

// Search filter
if (!empty($search)) {
    $query_conditions[] = "(b.booking_id LIKE ? OR b.purpose LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $param_types .= "ssss";
}

$conditions_sql = !empty($query_conditions) ? "WHERE " . implode(" AND ", $query_conditions) : "";

// Get all requests with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$count_query = "SELECT COUNT(*) as total FROM bookings b LEFT JOIN user_accounts u ON b.user_id = u.id $conditions_sql";
$stmt = $conn->prepare($count_query);
if (!empty($query_params)) {
    $stmt->bind_param($param_types, ...$query_params);
}
$stmt->execute();
$total_result = $stmt->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $per_page);

// Get requests with user information - order by most recent first (created_at DESC, then date DESC)
// Ensure status is always returned, defaulting to 'pending' if NULL
$requests_query = "SELECT b.booking_id, b.user_id, b.facility_type, b.date, b.start_time, b.end_time, 
                    b.purpose, b.attendees, COALESCE(b.status, 'pending') as status, b.additional_info, 
                    b.created_at, b.updated_at, u.name as user_name, u.email as user_email, u.user_type, u.role 
                    FROM bookings b
                    LEFT JOIN user_accounts u ON b.user_id = u.id
                    $conditions_sql
                    ORDER BY b.created_at DESC, b.date DESC
                    LIMIT ?, ?";
$stmt = $conn->prepare($requests_query);
if (!empty($query_params)) {
    $stmt->bind_param($param_types . "ii", ...[...$query_params, $offset, $per_page]);
} else {
    $stmt->bind_param("ii", $offset, $per_page);
}
$stmt->execute();
$requests = $stmt->get_result();

// Get all facilities for filter dropdown
$facilities_query = "SELECT * FROM gym_facilities ORDER BY name ASC";
$facilities_result = $conn->query($facilities_query);

// Get booking statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
              FROM bookings 
              WHERE facility_type = 'gym'";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get facilities for management
$facilities_management_query = "SELECT * FROM gym_facilities ORDER BY name ASC";
$facilities_management_result = $conn->query($facilities_management_query);

// Get event types for management
$event_types_query = "SELECT * FROM gym_event_types ORDER BY name ASC";
$event_types_result = $conn->query($event_types_query);

// Get equipment/services for management
$equipment_services_query = "SELECT * FROM gym_equipment_services ORDER BY type ASC, name ASC";
$equipment_services_result = $conn->query($equipment_services_query);

// Get pricing settings
$pricing_query = "SELECT setting_key, setting_value FROM gym_pricing_settings";
$pricing_result = $conn->query($pricing_query);
$pricing_settings = [];
if ($pricing_result) {
    while ($row = $pricing_result->fetch_assoc()) {
        $pricing_settings[$row['setting_key']] = (float)$row['setting_value'];
    }
}
// Set defaults if not found
if (empty($pricing_settings)) {
    $pricing_settings = [
        'gymnasium_per_hour' => 700.00,
        'sound_system_per_hour' => 150.00,
        'electricity_per_hour' => 150.00,
        'chair_free_limit' => 200.00,
        'chair_cost_per_unit' => 8.00
    ];
}
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100 overflow-hidden">
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Gym Management</h1>
                <div class="flex items-center">
                    <span class="text-gray-700 mr-2"><?php echo $user_name; ?></span>
                    <button class="md:hidden rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500" id="menu-button">
                        <span class="sr-only">Open menu</span>
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Main content -->
        <main class="flex-1 overflow-y-auto p-4">
            <div class="max-w-7xl mx-auto">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                        <p><?php echo $_SESSION['success']; ?></p>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                        <p><?php echo $_SESSION['error']; ?></p>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <!-- Tabs -->
                <div class="mb-6">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                            <button class="tab-button border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" data-tab="bookings">
                                Bookings
                            </button>
                            <button class="tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" data-tab="event-type">
                                Event Type
                            </button>
                            <button class="tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" data-tab="equipment-services">
                                Equipment/Services
                            </button>
                            <button class="tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" data-tab="pricing">
                                Pricing
                            </button>
                        </nav>
                    </div>
                </div>
                
                <!-- Bookings Tab Content -->
                <div id="bookings-tab" class="tab-content">
                    <!-- Booking Statistics -->
                    <div class="mb-6 grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-blue-100 rounded-md p-3">
                                    <i class="fas fa-calendar-check text-blue-600"></i>
                                </div>
                                <div class="ml-4">
                                    <h2 class="text-sm font-medium text-gray-500">Total Bookings</h2>
                                    <p class="text-lg font-semibold text-gray-900"><?php echo $stats['total']; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-yellow-100 rounded-md p-3">
                                    <i class="fas fa-clock text-yellow-600"></i>
                                </div>
                                <div class="ml-4">
                                    <h2 class="text-sm font-medium text-gray-500">Pending</h2>
                                    <p class="text-lg font-semibold text-gray-900"><?php echo $stats['pending']; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-green-100 rounded-md p-3">
                                    <i class="fas fa-check-circle text-green-600"></i>
                                </div>
                                <div class="ml-4">
                                    <h2 class="text-sm font-medium text-gray-500">Approved</h2>
                                    <p class="text-lg font-semibold text-gray-900"><?php echo $stats['approved']; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-red-100 rounded-md p-3">
                                    <i class="fas fa-times-circle text-red-600"></i>
                                </div>
                                <div class="ml-4">
                                    <h2 class="text-sm font-medium text-gray-500">Rejected</h2>
                                    <p class="text-lg font-semibold text-gray-900"><?php echo $stats['rejected']; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-gray-100 rounded-md p-3">
                                    <i class="fas fa-ban text-gray-600"></i>
                                </div>
                                <div class="ml-4">
                                    <h2 class="text-sm font-medium text-gray-500">Cancelled</h2>
                                    <p class="text-lg font-semibold text-gray-900"><?php echo $stats['cancelled']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="mb-6 bg-white rounded-lg shadow p-4">
                        <form action="gym_management.php" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div>
                                <label for="date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="date" id="date" name="date" value="<?php echo $date_filter; ?>" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                            </div>
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input type="text" id="search" name="search" value="<?php echo $search; ?>" placeholder="Search bookings..." class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    <i class="fas fa-filter mr-1"></i> Filter
                                </button>
                                <a href="gym_management.php" class="ml-2 bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                                    <i class="fas fa-sync-alt mr-1"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Bookings Table -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                            <h3 class="text-lg font-medium text-gray-900">Gym Reservations</h3>
                            <p class="mt-1 text-sm text-gray-500">Manage gym reservation requests</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purpose</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendees</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if ($requests->num_rows > 0): ?>
                                        <?php while ($request = $requests->fetch_assoc()): ?>
                                            <?php 
                                            // Get status from request (query ensures it's never NULL)
                                            $status_value = isset($request['status']) ? trim(strtolower($request['status'])) : 'pending';
                                            $status_class = 'bg-gray-100 text-gray-800';
                                            $status_text = 'Pending';
                                            
                                            switch ($status_value) {
                                                case 'pending':
                                                    $status_class = 'bg-yellow-100 text-yellow-800';
                                                    $status_text = 'Pending';
                                                    break;
                                                case 'confirmed':
                                                case 'approved':
                                                    $status_class = 'bg-green-100 text-green-800';
                                                    $status_text = 'Approved';
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
                                            
                                            // Parse additional info if exists
                                            $additional_info = json_decode($request['additional_info'] ?? '{}', true) ?: [];
                                            $admin_remarks = $additional_info['admin_remarks'] ?? '';
                                            
                                            // Get other event type if purpose is "Other"
                                            $other_event_type = '';
                                            if ($request['purpose'] === 'Other' && isset($additional_info['other_event_type']) && !empty($additional_info['other_event_type'])) {
                                                $other_event_type = $additional_info['other_event_type'];
                                            }
                                            
                                            // Get letter path if exists
                                            $letter_path = $additional_info['letter_path'] ?? null;
                                            
                                            // Get total amount from cost_breakdown for external users
                                            $total_amount = null;
                                            if (($request['user_type'] ?? 'student') === 'external' && isset($additional_info['cost_breakdown']['total'])) {
                                                $total_amount = (float)$additional_info['cost_breakdown']['total'];
                                            }
                                            ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $request['booking_id']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="font-medium"><?php echo $request['user_name']; ?></div>
                                                    <div class="text-xs text-gray-400"><?php echo $request['user_email']; ?></div>
                                                    <?php if (!empty($request['organization'])): ?>
                                                        <div class="text-xs text-gray-400"><?php echo $request['organization']; ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div><?php echo date('F j, Y', strtotime($request['date'])); ?></div>
                                                    <div class="text-xs text-gray-400">
                                                        <?php echo date('h:i A', strtotime($request['start_time'])); ?> - 
                                                        <?php echo date('h:i A', strtotime($request['end_time'])); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-500">
                                                    <div><?php echo $request['purpose']; ?></div>
                                                    <?php if ($request['purpose'] === 'Other' && !empty($other_event_type)): ?>
                                                        <div class="text-xs text-gray-400 mt-1">
                                                            <i class="fas fa-info-circle mr-1"></i><?php echo htmlspecialchars($other_event_type); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $request['attendees']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <div class="flex items-center justify-end gap-2">
                                                        <button type="button" class="inline-flex items-center px-3 py-1.5 border border-blue-300 shadow-sm text-sm font-medium rounded-md text-blue-700 bg-blue-50 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" onclick="viewBookingDetails(<?php echo htmlspecialchars(json_encode([
                                                            'id' => $request['booking_id'],
                                                            'user_name' => $request['user_name'],
                                                            'user_email' => $request['user_email'],
                                                            'user_type' => $request['user_type'] ?? 'student',
                                                            'role' => $request['role'] ?? null,
                                                            'facility_name' => $request['facility_type'],
                                                            'booking_date' => $request['date'],
                                                            'time_slot' => date('h:i A', strtotime($request['start_time'])) . ' - ' . date('h:i A', strtotime($request['end_time'])),
                                                            'purpose' => $request['purpose'],
                                                            'other_event_type' => $other_event_type,
                                                            'participants' => $request['attendees'],
                                                            'status' => $request['status'],
                                                            'admin_remarks' => $admin_remarks,
                                                            'letter_path' => $letter_path,
                                                            'total_amount' => $total_amount
                                                        ])); ?>)">
                                                            <i class="fas fa-eye mr-1.5"></i>View
                                                        </button>
                                                        
                                                        <?php if ($request['status'] === 'pending'): ?>
                                                            <button type="button" class="inline-flex items-center px-3 py-1.5 border border-green-300 shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" onclick="confirmAndApprove('<?php echo $request['booking_id']; ?>', '<?php echo $request['user_type'] ?? 'student'; ?>')">
                                                                <i class="fas fa-check mr-1.5"></i>Approve
                                                            </button>
                                                            <button type="button" class="inline-flex items-center px-3 py-1.5 border border-red-300 shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500" onclick="openRejectModal('<?php echo $request['booking_id']; ?>')">
                                                                <i class="fas fa-times mr-1.5"></i>Reject
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (in_array($request['status'], ['pending', 'confirmed', 'approved'])): ?>
                                                            <button type="button" class="inline-flex items-center px-3 py-1.5 border border-blue-300 shadow-sm text-sm font-medium rounded-md text-blue-700 bg-blue-50 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" onclick="openRescheduleModal(<?php echo htmlspecialchars(json_encode([
                                                                'booking_id' => $request['booking_id'],
                                                                'date' => $request['date'],
                                                                'start_time' => $request['start_time'],
                                                                'end_time' => $request['end_time'],
                                                                'user_name' => $request['user_name']
                                                            ])); ?>)">
                                                                <i class="fas fa-calendar-alt mr-1.5"></i>Reschedule
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No reservations found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </a>
                                <?php else: ?>
                                <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-white cursor-not-allowed">
                                    Previous
                                </span>
                                <?php endif; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </a>
                                <?php else: ?>
                                <span class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-white cursor-not-allowed">
                                    Next
                                </span>
                                <?php endif; ?>
                    </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Showing
                                        <span class="font-medium"><?php echo $offset + 1; ?></span>
                                        to
                                        <span class="font-medium"><?php echo min($offset + $per_page, $total_rows); ?></span>
                                        of
                                        <span class="font-medium"><?php echo $total_rows; ?></span>
                                        results
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                        <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Previous</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                        <?php else: ?>
                                        <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                                            <span class="sr-only">Previous</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $page + 2);
                                        
                                        if ($start_page > 1): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>
                                            <?php if ($start_page > 2): ?>
                                                <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="relative inline-flex items-center px-4 py-2 border border-blue-500 bg-blue-50 text-sm font-medium text-blue-600 z-10"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        
                                        <?php if ($end_page < $total_pages): ?>
                                            <?php if ($end_page < $total_pages - 1): ?>
                                                <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                                            <?php endif; ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50"><?php echo $total_pages; ?></a>
                                        <?php endif; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Next</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                        <?php else: ?>
                                        <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                                            <span class="sr-only">Next</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </span>
                                        <?php endif; ?>
                                    </nav>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Event Type Tab Content -->
                <div id="event-type-tab" class="tab-content hidden">
                    <!-- Add Event Type Button -->
                    <div class="mb-6">
                        <button type="button" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" onclick="openAddEventTypeModal()">
                            <i class="fas fa-plus mr-1"></i> Add New Event Type
                        </button>
                    </div>
                    
                    <!-- Event Types Table -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                            <h3 class="text-lg font-medium text-gray-900">Event Types</h3>
                            <p class="mt-1 text-sm text-gray-500">Manage purpose/event types for gym reservations</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if ($event_types_result->num_rows > 0): ?>
                                        <?php while ($event_type = $event_types_result->fetch_assoc()): ?>
                                            <?php 
                                            $status_class = $event_type['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                                            $status_text = $event_type['is_active'] ? 'Active' : 'Inactive';
                                            ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $event_type['id']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($event_type['name']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <button type="button" class="text-blue-600 hover:text-blue-900 mr-3" onclick="openEditEventTypeModal(<?php echo htmlspecialchars(json_encode($event_type)); ?>)">
                                                        Edit
                                                    </button>
                                                    <?php if ($event_type['name'] !== 'Other'): ?>
                                                        <button type="button" class="text-red-600 hover:text-red-900 mr-3" onclick="openDeleteEventTypeModal(<?php echo $event_type['id']; ?>, '<?php echo htmlspecialchars($event_type['name']); ?>')">
                                                            Delete
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="text-<?php echo $event_type['is_active'] ? 'yellow' : 'green'; ?>-600 hover:text-<?php echo $event_type['is_active'] ? 'yellow' : 'green'; ?>-900" onclick="toggleEventTypeStatus(<?php echo $event_type['id']; ?>)">
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
                
                <!-- Equipment/Services Tab Content -->
                <div id="equipment-services-tab" class="tab-content hidden">
                    <!-- Add Equipment/Service Button -->
                    <div class="mb-6">
                        <button type="button" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" onclick="openAddEquipmentServiceModal()">
                            <i class="fas fa-plus mr-1"></i> Add New Equipment/Service
                        </button>
                    </div>
                    
                    <!-- Equipment/Services Table -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                            <h3 class="text-lg font-medium text-gray-900">Equipment/Services Needed</h3>
                            <p class="mt-1 text-sm text-gray-500">Manage equipment and services available for gym reservations</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cost/Hour</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if ($equipment_services_result->num_rows > 0): ?>
                                        <?php while ($equipment = $equipment_services_result->fetch_assoc()): ?>
                                            <?php 
                                            $status_class = $equipment['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                                            $status_text = $equipment['is_active'] ? 'Active' : 'Inactive';
                                            $type_class = $equipment['type'] === 'equipment' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800';
                                            $type_text = ucfirst($equipment['type']);
                                            ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $equipment['id']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($equipment['name']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?>">
                                                        <?php echo $type_text; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">₱<?php echo number_format($equipment['cost_per_hour'], 2); ?></td>
                                                <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($equipment['description'] ?? '-'); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <button type="button" class="text-blue-600 hover:text-blue-900 mr-3" onclick="openEditEquipmentServiceModal(<?php echo htmlspecialchars(json_encode($equipment)); ?>)">
                                                        Edit
                                                    </button>
                                                    <button type="button" class="text-red-600 hover:text-red-900 mr-3" onclick="openDeleteEquipmentServiceModal(<?php echo $equipment['id']; ?>, '<?php echo htmlspecialchars($equipment['name']); ?>')">
                                                        Delete
                                                    </button>
                                                    <button type="button" class="text-<?php echo $equipment['is_active'] ? 'yellow' : 'green'; ?>-600 hover:text-<?php echo $equipment['is_active'] ? 'yellow' : 'green'; ?>-900" onclick="toggleEquipmentServiceStatus(<?php echo $equipment['id']; ?>)">
                                                        <?php echo $equipment['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No equipment/services found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Pricing Tab Content -->
                <div id="pricing-tab" class="tab-content hidden">
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                            <h3 class="text-lg font-medium text-gray-900">Pricing Settings</h3>
                            <p class="mt-1 text-sm text-gray-500">Manage gymnasium and equipment pricing</p>
                        </div>
                        <form method="POST" action="gym_management.php" class="p-6">
                            <input type="hidden" name="action" value="update_pricing">
                            <div class="space-y-6">
                                <div>
                                    <label for="gymnasium_per_hour" class="block text-sm font-medium text-gray-700 mb-2">
                                        Gymnasium Cost per Hour (₱)
                                    </label>
                                    <input type="number" id="gymnasium_per_hour" name="gymnasium_per_hour" 
                                           step="0.01" min="0" 
                                           value="<?php echo number_format($pricing_settings['gymnasium_per_hour'], 2, '.', ''); ?>"
                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                                </div>
                                
                                <div>
                                    <label for="sound_system_per_hour" class="block text-sm font-medium text-gray-700 mb-2">
                                        Sound System Cost per Hour (₱)
                                    </label>
                                    <input type="number" id="sound_system_per_hour" name="sound_system_per_hour" 
                                           step="0.01" min="0" 
                                           value="<?php echo number_format($pricing_settings['sound_system_per_hour'], 2, '.', ''); ?>"
                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                                </div>
                                
                                <div>
                                    <label for="electricity_per_hour" class="block text-sm font-medium text-gray-700 mb-2">
                                        Electricity Cost per Hour (₱)
                                    </label>
                                    <input type="number" id="electricity_per_hour" name="electricity_per_hour" 
                                           step="0.01" min="0" 
                                           value="<?php echo number_format($pricing_settings['electricity_per_hour'], 2, '.', ''); ?>"
                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                                </div>
                                
                                <div>
                                    <label for="chair_free_limit" class="block text-sm font-medium text-gray-700 mb-2">
                                        Free Chairs Limit
                                    </label>
                                    <input type="number" id="chair_free_limit" name="chair_free_limit" 
                                           step="1" min="0" 
                                           value="<?php echo (int)$pricing_settings['chair_free_limit']; ?>"
                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                                    <p class="mt-1 text-xs text-gray-500">Number of chairs provided for free</p>
                                </div>
                                
                                <div>
                                    <label for="chair_cost_per_unit" class="block text-sm font-medium text-gray-700 mb-2">
                                        Cost per Additional Chair (₱)
                                    </label>
                                    <input type="number" id="chair_cost_per_unit" name="chair_cost_per_unit" 
                                           step="0.01" min="0" 
                                           value="<?php echo number_format($pricing_settings['chair_cost_per_unit'], 2, '.', ''); ?>"
                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                                    <p class="mt-1 text-xs text-gray-500">Cost per chair beyond the free limit</p>
                                </div>
                                
                                <div class="flex justify-end pt-4 border-t border-gray-200">
                                    <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                        <i class="fas fa-save mr-1"></i> Save Pricing Settings
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Booking Details Modal -->
<div id="bookingDetailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-md max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-6 pb-4 border-b border-gray-200 flex-shrink-0">
            <h3 class="text-lg font-medium text-gray-900">Booking Details</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeBookingDetailsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="space-y-4 p-6 overflow-y-auto flex-1">
            <div>
                <h4 class="text-sm font-medium text-gray-500">Booking ID</h4>
                <p id="detail-booking-id" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">User</h4>
                <p id="detail-user" class="mt-1 text-sm text-gray-900"></p>
                <p id="detail-email" class="text-xs text-gray-500"></p>
                <p id="detail-user-type" class="text-xs text-gray-500 mt-1"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Facility</h4>
                <p id="detail-facility" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Date & Time</h4>
                <p id="detail-datetime" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Purpose</h4>
                <p id="detail-purpose" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Participants</h4>
                <p id="detail-participants" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Status</h4>
                <p id="detail-status" class="mt-1 text-sm"></p>
            </div>
            <div id="detail-total-container" class="hidden">
                <h4 class="text-sm font-medium text-gray-500">Total Amount</h4>
                <p id="detail-total" class="mt-1 text-sm text-gray-900 font-semibold"></p>
            </div>
            <div id="detail-letter-container" class="hidden">
                <h4 class="text-sm font-medium text-gray-500 mb-2">Letter from President</h4>
                <div class="border border-gray-300 rounded-lg p-3 bg-gray-50">
                    <a id="detail-letter-link" href="#" target="_blank" class="flex items-center text-blue-600 hover:text-blue-800 mb-2">
                        <i class="fas fa-file-pdf mr-2"></i>
                        <span id="detail-letter-text">View Letter</span>
                    </a>
                    <div id="detail-letter-image" class="mt-3 hidden">
                        <img id="detail-letter-img" src="" alt="Letter" class="max-w-full h-auto rounded border border-gray-300" style="max-height: 250px; object-fit: contain;">
                    </div>
                </div>
            </div>
            <div id="detail-remarks-container">
                <h4 class="text-sm font-medium text-gray-500">Admin Remarks</h4>
                <p id="detail-remarks" class="mt-1 text-sm text-gray-900"></p>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2 p-6 pt-4 border-t border-gray-200 flex-shrink-0">
            <button type="button" id="print-receipt-btn" class="hidden inline-flex items-center px-4 py-2 border border-blue-300 shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" onclick="printGymReceipt()">
                <i class="fas fa-print mr-2"></i>Print Receipt
            </button>
            <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeBookingDetailsModal()">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <!-- Header with Icon -->
        <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-4 rounded-t-lg">
            <div class="flex justify-between items-center">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-check-circle text-white text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white">Approve Booking</h3>
                        <p id="approve-user-type-label" class="text-sm text-green-100"></p>
                    </div>
                </div>
                <button type="button" class="text-white hover:text-gray-200 transition-colors" onclick="closeApproveModal()">
                    <i class="fas fa-times text-xl"></i>
            </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-6">
            <!-- Confirmation Message for Student/Faculty/Staff -->
            <div id="approve-confirmation-section" class="hidden mb-6">
                <div class="text-center mb-6">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                        <i class="fas fa-question-circle text-blue-600 text-4xl"></i>
                    </div>
                    <h4 class="text-xl font-semibold text-gray-800 mb-2">Are you sure you want to approve this booking?</h4>
                    <p class="text-sm text-gray-600 mb-4">This action will confirm the gym reservation request.</p>
                    </div>
                
                <!-- Booking Info Preview -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500">Booking ID:</span>
                        <span class="text-sm font-semibold text-gray-900" id="approve-preview-booking-id"></span>
                </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-gray-500">User Type:</span>
                        <span class="text-sm font-semibold text-gray-900" id="approve-preview-user-type"></span>
            </div>
            </div>
        </div>
        
            <form id="approveForm" method="POST" action="gym_management.php" onsubmit="return validateApproveForm(event)">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" id="approve_booking_id" name="booking_id">
                <input type="hidden" id="approve_user_type" name="user_type" value="">
                
                <!-- OR Number Field (External Users Only) -->
                <div id="or_number_container" class="mb-4 hidden">
                    <label for="approve_or_number" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-receipt text-blue-600 mr-2"></i>Official Receipt (OR) No: <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="approve_or_number" name="or_number" 
                       pattern="[0-9]{7}"
                       maxlength="7"
                       inputmode="numeric"
                       onkeypress="return (event.charCode >= 48 && event.charCode <= 57)"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 7)"
                           class="w-full px-4 py-2.5 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                       placeholder="Enter OR Number (7 digits)">
                    <p class="mt-2 text-xs text-gray-500 flex items-center">
                        <i class="fas fa-info-circle mr-1"></i>Enter the OR number provided by the cashier (7 digits only)
                    </p>
            </div>
                
                <!-- Remarks Field (External Users Only) -->
                <div id="remarks_container" class="mb-6 hidden">
                    <label for="approve_remarks" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-comment-alt text-gray-500 mr-2"></i>Remarks (Optional)
                    </label>
                    <textarea id="approve_remarks" name="remarks" rows="3" 
                              class="w-full px-4 py-2.5 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none"
                              placeholder="Add any additional notes or remarks..."></textarea>
            </div>
                
                <!-- Action Buttons -->
                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeApproveModal()"
                            class="px-5 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors font-medium">
                        <i class="fas fa-times mr-2"></i>Cancel
                </button>
                    <button type="submit" id="approve-submit-btn"
                            class="px-5 py-2.5 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg hover:from-green-700 hover:to-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-all font-medium shadow-md hover:shadow-lg">
                        <i class="fas fa-check-circle mr-2"></i><span id="approve-btn-text">Approve Booking</span>
                </button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Reject Booking</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeRejectModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" id="reject_booking_id" name="booking_id">
            <div class="mb-4">
                <label for="reject_remarks" class="block text-sm font-medium text-gray-700 mb-1">Reason for Rejection</label>
                <textarea id="reject_remarks" name="remarks" rows="3" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"></textarea>
                <p class="mt-1 text-xs text-gray-500">Please provide a reason for rejecting this booking request.</p>
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeRejectModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Reject Booking
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reschedule Modal -->
<div id="rescheduleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 overflow-y-auto">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-4xl my-8">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Reschedule Booking</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeRescheduleModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Left Column: Form -->
            <div>
                <form method="POST" action="gym_management.php" id="rescheduleForm">
                    <input type="hidden" name="action" value="reschedule">
                    <input type="hidden" id="reschedule_booking_id" name="booking_id">
                    
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-3">
                            <strong>Current Booking:</strong><br>
                            <span id="reschedule_current_info"></span>
                        </p>
                    </div>
                    
                    <div class="mb-4">
                        <label for="reschedule_new_date" class="block text-sm font-medium text-gray-700 mb-1">New Date <span class="text-red-500">*</span></label>
                        <input type="date" id="reschedule_new_date" name="new_date" required min="<?php echo date('Y-m-d'); ?>" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                        <p class="mt-1 text-xs text-gray-500">Select a date to see available time slots</p>
                    </div>
                    
                    <div class="mb-4">
                        <label for="reschedule_new_start_time" class="block text-sm font-medium text-gray-700 mb-1">New Start Time <span class="text-red-500">*</span></label>
                        <input type="time" id="reschedule_new_start_time" name="new_start_time" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                    </div>
                    
                    <div class="mb-4">
                        <label for="reschedule_new_end_time" class="block text-sm font-medium text-gray-700 mb-1">New End Time <span class="text-red-500">*</span></label>
                        <input type="time" id="reschedule_new_end_time" name="new_end_time" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                    </div>
                    
                    <div class="mb-4">
                        <label for="reschedule_reason" class="block text-sm font-medium text-gray-700 mb-1">Reason for Rescheduling (Optional)</label>
                        <textarea id="reschedule_reason" name="reschedule_reason" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="Explain why this booking is being rescheduled"></textarea>
                        <p class="mt-1 text-xs text-gray-500">This reason will be sent to the user via notification.</p>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeRescheduleModal()">
                            Cancel
                        </button>
                        <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            <i class="fas fa-calendar-alt mr-1.5"></i>Reschedule Booking
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Right Column: Availability Display -->
            <div>
                <div class="mb-4">
                    <h4 class="text-md font-semibold text-gray-900 mb-2">
                        <i class="fas fa-calendar-check mr-2"></i>Availability for Selected Date
                    </h4>
                    <p class="text-xs text-gray-500 mb-3">Click on an available time slot to auto-fill the time fields</p>
                </div>
                
                <!-- Loading indicator -->
                <div id="reschedule_availability_loading" class="hidden text-center py-4">
                    <div class="inline-block h-6 w-6 border-2 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
                    <p class="mt-2 text-sm text-gray-600">Loading availability...</p>
                </div>
                
                <!-- Availability content -->
                <div id="reschedule_availability_content" class="hidden">
                    <!-- Blocked date message -->
                    <div id="reschedule_blocked_message" class="hidden p-4 bg-red-50 border-2 border-red-300 rounded-lg mb-4">
                        <div class="text-center">
                            <div class="text-3xl mb-2" id="reschedule_blocked_icon">🚫</div>
                            <div class="font-bold text-red-600 mb-1">DATE BLOCKED</div>
                            <div class="font-semibold text-gray-800" id="reschedule_blocked_event"></div>
                            <div class="text-sm text-gray-600 mt-1" id="reschedule_blocked_desc"></div>
                        </div>
                    </div>
                    
                    <!-- Available sessions -->
                    <div id="reschedule_available_sessions" class="space-y-2 mb-4">
                        <h5 class="text-sm font-semibold text-gray-700 mb-2">Available Time Slots:</h5>
                        <div id="reschedule_sessions_list" class="space-y-2"></div>
                    </div>
                    
                    <!-- Booked slots -->
                    <div id="reschedule_booked_slots" class="mt-4">
                        <h5 class="text-sm font-semibold text-gray-700 mb-2">Booked Time Slots:</h5>
                        <div id="reschedule_booked_list" class="space-y-1"></div>
                        <p id="reschedule_no_bookings" class="text-sm text-gray-500 hidden">No bookings for this date</p>
                    </div>
                </div>
                
                <!-- No date selected message -->
                <div id="reschedule_no_date_message" class="text-center py-8 text-gray-500">
                    <i class="fas fa-calendar-alt text-4xl mb-3 text-gray-300"></i>
                    <p class="text-sm">Please select a date to view availability</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Facility Modal -->
<div id="addFacilityModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Add New Facility</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeAddFacilityModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="add_facility">
            <div class="mb-4">
                <label for="facility_name" class="block text-sm font-medium text-gray-700 mb-1">Facility Name</label>
                <input type="text" id="facility_name" name="facility_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
            </div>
            <div class="mb-4">
                <label for="capacity" class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                <input type="number" id="capacity" name="capacity" min="1" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
            </div>
            <div class="mb-4">
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea id="description" name="description" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"></textarea>
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeAddFacilityModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Add Facility
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Facility Modal -->
<div id="editFacilityModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Edit Facility</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeEditFacilityModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="update_facility">
            <input type="hidden" id="edit_facility_id" name="facility_id">
            <div class="mb-4">
                <label for="edit_facility_name" class="block text-sm font-medium text-gray-700 mb-1">Facility Name</label>
                <input type="text" id="edit_facility_name" name="facility_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
            </div>
            <div class="mb-4">
                <label for="edit_capacity" class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                <input type="number" id="edit_capacity" name="capacity" min="1" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
            </div>
            <div class="mb-4">
                <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea id="edit_description" name="description" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"></textarea>
            </div>
            <div class="mb-4">
                <label for="edit_status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select id="edit_status" name="status" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeEditFacilityModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Update Facility
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Facility Modal -->
<div id="deleteFacilityModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Delete Facility</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeDeleteFacilityModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="delete_facility">
            <input type="hidden" id="delete_facility_id" name="facility_id">
            <p class="mb-4 text-sm text-gray-700">Are you sure you want to delete the facility "<span id="delete_facility_name"></span>"? This action cannot be undone.</p>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeDeleteFacilityModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Delete Facility
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Event Type Modal -->
<div id="addEventTypeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Add New Event Type</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeAddEventTypeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="add_event_type">
            <div class="mb-4">
                <label for="add-event-type-name" class="block text-sm font-medium text-gray-700 mb-1">Event Type Name</label>
                <input type="text" id="add-event-type-name" name="event_type_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="e.g., Tennis">
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeAddEventTypeModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Add
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Event Type Modal -->
<div id="editEventTypeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Edit Event Type</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeEditEventTypeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="update_event_type">
            <input type="hidden" name="event_type_id" id="edit-event-type-id">
            <div class="mb-4">
                <label for="edit-event-type-name" class="block text-sm font-medium text-gray-700 mb-1">Event Type Name</label>
                <input type="text" id="edit-event-type-name" name="event_type_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeEditEventTypeModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Event Type Modal -->
<div id="deleteEventTypeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Delete Event Type</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeDeleteEventTypeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <p class="text-gray-700 mb-4">Are you sure you want to delete "<span id="delete-event-type-name"></span>"?</p>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="delete_event_type">
            <input type="hidden" name="event_type_id" id="delete-event-type-id">
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeDeleteEventTypeModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Equipment/Service Modal -->
<div id="addEquipmentServiceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Add New Equipment/Service</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeAddEquipmentServiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="add_equipment_service">
            <div class="mb-4">
                <label for="add-equipment-name" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input type="text" id="add-equipment-name" name="equipment_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="e.g., Sound System">
            </div>
            <div class="mb-4">
                <label for="add-equipment-type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select id="add-equipment-type" name="equipment_type" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                    <option value="equipment">Equipment</option>
                    <option value="service">Service</option>
                </select>
            </div>
            <div class="mb-4">
                <label for="add-cost-per-hour" class="block text-sm font-medium text-gray-700 mb-1">Cost per Hour (₱)</label>
                <input type="number" id="add-cost-per-hour" name="cost_per_hour" step="0.01" min="0" value="0.00" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="0.00">
            </div>
            <div class="mb-4">
                <label for="add-equipment-description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                <textarea id="add-equipment-description" name="description" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="Enter description..."></textarea>
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeAddEquipmentServiceModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Add
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Equipment/Service Modal -->
<div id="editEquipmentServiceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Edit Equipment/Service</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeEditEquipmentServiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="update_equipment_service">
            <input type="hidden" name="equipment_id" id="edit-equipment-id">
            <div class="mb-4">
                <label for="edit-equipment-name" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input type="text" id="edit-equipment-name" name="equipment_name" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
            </div>
            <div class="mb-4">
                <label for="edit-equipment-type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select id="edit-equipment-type" name="equipment_type" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                    <option value="equipment">Equipment</option>
                    <option value="service">Service</option>
                </select>
            </div>
            <div class="mb-4">
                <label for="edit-cost-per-hour" class="block text-sm font-medium text-gray-700 mb-1">Cost per Hour (₱)</label>
                <input type="number" id="edit-cost-per-hour" name="cost_per_hour" step="0.01" min="0" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
            </div>
            <div class="mb-4">
                <label for="edit-equipment-description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                <textarea id="edit-equipment-description" name="description" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"></textarea>
            </div>
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeEditEquipmentServiceModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Equipment/Service Modal -->
<div id="deleteEquipmentServiceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Delete Equipment/Service</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeDeleteEquipmentServiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <p class="text-gray-700 mb-4">Are you sure you want to delete "<span id="delete-equipment-name"></span>"?</p>
        <form method="POST" action="gym_management.php">
            <input type="hidden" name="action" value="delete_equipment_service">
            <input type="hidden" name="equipment_id" id="delete-equipment-id">
            <div class="flex justify-end">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeDeleteEquipmentServiceModal()">
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
    
    // Tab switching
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => {
                btn.classList.remove('border-blue-500', 'text-blue-600');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            tabContents.forEach(content => {
                content.classList.add('hidden');
            });
            
            // Add active class to clicked button and show corresponding content
            button.classList.remove('border-transparent', 'text-gray-500');
            button.classList.add('border-blue-500', 'text-blue-600');
            
            const tabId = button.getAttribute('data-tab');
            document.getElementById(`${tabId}-tab`).classList.remove('hidden');
        });
    });
    
    // Helper function to format user name with role/type
    function formatUserName(userName, userType, role) {
        const roleLabels = {
            'student': 'Student',
            'faculty': 'Faculty',
            'staff': 'Staff'
        };
        
        const userTypeLabels = {
            'admin': 'BAO Admin',
            'secretary': 'BAO Secretary',
            'staff': 'Staff',
            'external': 'External User'
        };
        
        // For students, use role if available
        if (userType === 'student' && role && roleLabels[role]) {
            return userName + ' (' + roleLabels[role] + ')';
        }
        
        // For other user types, use user_type label
        if (userTypeLabels[userType]) {
            return userName + ' (' + userTypeLabels[userType] + ')';
        }
        
        // Fallback
        return userName;
    }
    
    // Store current booking ID for print receipt
    let currentBookingIdForPrint = '';
    
    // Booking details modal functions
    function viewBookingDetails(booking) {
        currentBookingIdForPrint = booking.id;
        document.getElementById('detail-booking-id').textContent = booking.id;
        document.getElementById('detail-user').textContent = formatUserName(booking.user_name, booking.user_type || 'student', booking.role || null);
        document.getElementById('detail-email').textContent = booking.user_email;
        
        // Display user type
        const userTypeElement = document.getElementById('detail-user-type');
        const userType = booking.user_type || 'student';
        const role = booking.role || null;
        
        if (userType === 'student' && role) {
            const roleLabels = {
                'student': 'Student',
                'faculty': 'Faculty',
                'staff': 'Staff'
            };
            userTypeElement.textContent = 'User Type: ' + (roleLabels[role] || 'Student');
        } else {
            const userTypeLabels = {
                'admin': 'BAO Admin',
                'secretary': 'BAO Secretary',
                'staff': 'Staff',
                'external': 'External User'
            };
            userTypeElement.textContent = 'User Type: ' + (userTypeLabels[userType] || userType);
        }
        
        // Show/hide print receipt button based on user type
        const printReceiptBtn = document.getElementById('print-receipt-btn');
        if (userType === 'external') {
            printReceiptBtn.classList.remove('hidden');
        } else {
            printReceiptBtn.classList.add('hidden');
        }
        
        // Show/hide total amount based on user type (external only)
        const totalContainer = document.getElementById('detail-total-container');
        const totalElement = document.getElementById('detail-total');
        if (userType === 'external' && booking.total_amount !== null && booking.total_amount !== undefined) {
            totalElement.textContent = '₱' + parseFloat(booking.total_amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            totalContainer.classList.remove('hidden');
        } else {
            totalContainer.classList.add('hidden');
        }
        
        document.getElementById('detail-facility').textContent = booking.facility_name;
        
        const bookingDate = new Date(booking.booking_date);
        document.getElementById('detail-datetime').textContent = bookingDate.toLocaleDateString('en-US', { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        }) + ', ' + booking.time_slot;
        
        // Display purpose with other_event_type if "Other" is selected
        let purposeText = booking.purpose;
        if (booking.purpose === 'Other' && booking.other_event_type) {
            purposeText = booking.purpose + ' (' + booking.other_event_type + ')';
        }
        document.getElementById('detail-purpose').textContent = purposeText;
        document.getElementById('detail-participants').textContent = booking.participants + ' participants';
        
        // Set status with appropriate styling
        const statusElement = document.getElementById('detail-status');
        let statusText = booking.status.charAt(0).toUpperCase() + booking.status.slice(1);
        
        // Change "Confirmed" to "Approved" for display
        if (booking.status === 'confirmed') {
            statusText = 'Approved';
        }
        
        statusElement.textContent = statusText;
        
        // Reset classes
        statusElement.className = 'mt-1 text-sm px-2 inline-flex text-xs leading-5 font-semibold rounded-full';
        
        // Add appropriate class based on status
        switch (booking.status) {
            case 'pending':
                statusElement.classList.add('bg-yellow-100', 'text-yellow-800');
                break;
            case 'confirmed':
            case 'approved':
                statusElement.classList.add('bg-green-100', 'text-green-800');
                break;
            case 'rejected':
                statusElement.classList.add('bg-red-100', 'text-red-800');
                break;
            case 'cancelled':
                statusElement.classList.add('bg-gray-100', 'text-gray-800');
                break;
        }
        
        // Display letter if available
        const letterContainer = document.getElementById('detail-letter-container');
        const letterLink = document.getElementById('detail-letter-link');
        const letterText = document.getElementById('detail-letter-text');
        const letterImage = document.getElementById('detail-letter-image');
        const letterImg = document.getElementById('detail-letter-img');
        
        if (booking.letter_path) {
            const letterPath = '../' + booking.letter_path;
            letterLink.href = letterPath;
            letterText.textContent = 'View Letter';
            
            // Check if it's an image to show preview
            const isImage = /\.(jpg|jpeg|png|gif)$/i.test(booking.letter_path);
            if (isImage) {
                letterImg.src = letterPath;
                letterImage.classList.remove('hidden');
            } else {
                letterImage.classList.add('hidden');
            }
            
            letterContainer.classList.remove('hidden');
        } else {
            letterContainer.classList.add('hidden');
        }
        
        // Show/hide remarks
        const remarksContainer = document.getElementById('detail-remarks-container');
        const remarksElement = document.getElementById('detail-remarks');
        
        if (booking.admin_remarks) {
            remarksElement.textContent = booking.admin_remarks;
            remarksContainer.classList.remove('hidden');
        } else {
            remarksContainer.classList.add('hidden');
        }
        
        document.getElementById('bookingDetailsModal').classList.remove('hidden');
    }
    
    function closeBookingDetailsModal() {
        document.getElementById('bookingDetailsModal').classList.add('hidden');
    }
    
    // Approve modal functions
    function confirmAndApprove(bookingId, userType) {
        openApproveModal(bookingId, userType);
    }
    
    function openApproveModal(bookingId, userType) {
        document.getElementById('approve_booking_id').value = bookingId;
        document.getElementById('approve_user_type').value = userType;
        document.getElementById('approve_remarks').value = '';
        
        // Get user type label
        const userTypeLabels = {
            'student': 'Student',
            'faculty': 'Faculty',
            'staff': 'Staff',
            'external': 'External User'
        };
        const userTypeLabel = userTypeLabels[userType] || 'User';
        document.getElementById('approve-user-type-label').textContent = userTypeLabel + ' Booking';
        
        // Show/hide elements based on user type
        const orNumberContainer = document.getElementById('or_number_container');
        const orNumberInput = document.getElementById('approve_or_number');
        const confirmationSection = document.getElementById('approve-confirmation-section');
        const remarksContainer = document.getElementById('remarks_container');
        const approveBtnText = document.getElementById('approve-btn-text');
        
        if (userType === 'external') {
            // External users: Show OR number field and remarks, hide confirmation
            orNumberContainer.classList.remove('hidden');
            orNumberInput.required = true;
            orNumberInput.value = '';
            remarksContainer.classList.remove('hidden');
            confirmationSection.classList.add('hidden');
            approveBtnText.textContent = 'Approve Booking';
        } else {
            // Student/Faculty/Staff: Show confirmation, hide OR number and remarks
            orNumberContainer.classList.add('hidden');
            orNumberInput.required = false;
            orNumberInput.value = '';
            remarksContainer.classList.add('hidden');
            const remarksField = document.getElementById('approve_remarks');
            if (remarksField) {
                remarksField.value = ''; // Clear remarks
            }
            confirmationSection.classList.remove('hidden');
            document.getElementById('approve-preview-booking-id').textContent = bookingId;
            document.getElementById('approve-preview-user-type').textContent = userTypeLabel;
            approveBtnText.textContent = 'Yes, Approve';
        }
        
        document.getElementById('approveModal').classList.remove('hidden');
    }
    
    function closeApproveModal() {
        document.getElementById('approveModal').classList.add('hidden');
    }
    
    // Validate approve form
    function validateApproveForm(event) {
        const userType = document.getElementById('approve_user_type').value;
        const orNumberInput = document.getElementById('approve_or_number');
        const remarksTextarea = document.getElementById('approve_remarks');
        
        // If external user, OR number is required and must be exactly 7 digits
        if (userType === 'external') {
            const orNumber = orNumberInput.value.trim();
            if (!orNumber || orNumber === '') {
                if (event) event.preventDefault();
                alert('OR number is required for external users.');
                orNumberInput.focus();
                return false;
            } else if (orNumber.length !== 7 || !/^[0-9]{7}$/.test(orNumber)) {
                if (event) event.preventDefault();
                alert('OR number must be exactly 7 digits.');
                orNumberInput.focus();
                return false;
            }
        } else {
            // For student/faculty/staff, ensure remarks is empty
            if (remarksTextarea) {
                remarksTextarea.value = '';
            }
        }
        
        // Allow form to submit
        return true;
    }
    
    // Setup OR number validation (7 digits only)
    document.addEventListener('DOMContentLoaded', function() {
        const orNumberInput = document.getElementById('approve_or_number');
        if (orNumberInput) {
            orNumberInput.addEventListener('input', function() {
                // Remove any non-numeric characters and limit to 7 digits
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 7);
            });
            
            orNumberInput.addEventListener('blur', function() {
                const value = this.value.trim();
                if (value.length > 0 && value.length !== 7) {
                    alert('OR number must be exactly 7 digits.');
                    this.focus();
                }
            });
            
            orNumberInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const cleanedText = pastedText.replace(/[^0-9]/g, '').slice(0, 7);
                this.value = cleanedText;
            });
        }
    });
    
    // Reject modal functions
    function openRejectModal(bookingId) {
        document.getElementById('reject_booking_id').value = bookingId;
        document.getElementById('rejectModal').classList.remove('hidden');
    }
    
    function closeRejectModal() {
        document.getElementById('rejectModal').classList.add('hidden');
    }
    
    // Reschedule modal functions
    // Store current booking ID for availability check
    let currentRescheduleBookingId = '';
    
    function openRescheduleModal(booking) {
        currentRescheduleBookingId = booking.booking_id;
        document.getElementById('reschedule_booking_id').value = booking.booking_id;
        
        // Format current booking info
        const currentDate = new Date(booking.date);
        const formattedDate = currentDate.toLocaleDateString('en-US', { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
        const startTime = formatTime(booking.start_time);
        const endTime = formatTime(booking.end_time);
        
        document.getElementById('reschedule_current_info').textContent = 
            `${formattedDate} from ${startTime} to ${endTime} (${booking.user_name})`;
        
        // Set default values to current booking
        document.getElementById('reschedule_new_date').value = booking.date;
        document.getElementById('reschedule_new_start_time').value = booking.start_time.substring(0, 5);
        document.getElementById('reschedule_new_end_time').value = booking.end_time.substring(0, 5);
        document.getElementById('reschedule_reason').value = '';
        
        // Set minimum date to today
        document.getElementById('reschedule_new_date').min = new Date().toISOString().split('T')[0];
        
        // Reset availability display
        resetRescheduleAvailability();
        
        // Load availability for current date
        if (booking.date) {
            loadRescheduleAvailability(booking.date);
        }
        
        document.getElementById('rescheduleModal').classList.remove('hidden');
    }
    
    function closeRescheduleModal() {
        document.getElementById('rescheduleModal').classList.add('hidden');
        // Reset form
        document.getElementById('rescheduleForm').reset();
        resetRescheduleAvailability();
        currentRescheduleBookingId = '';
    }
    
    // Reset availability display
    function resetRescheduleAvailability() {
        document.getElementById('reschedule_availability_loading').classList.add('hidden');
        document.getElementById('reschedule_availability_content').classList.add('hidden');
        document.getElementById('reschedule_no_date_message').classList.remove('hidden');
        document.getElementById('reschedule_blocked_message').classList.add('hidden');
        document.getElementById('reschedule_sessions_list').innerHTML = '';
        document.getElementById('reschedule_booked_list').innerHTML = '';
    }
    
    // Load availability for selected date
    function loadRescheduleAvailability(date) {
        if (!date) {
            resetRescheduleAvailability();
            return;
        }
        
        // Show loading
        document.getElementById('reschedule_availability_loading').classList.remove('hidden');
        document.getElementById('reschedule_availability_content').classList.add('hidden');
        document.getElementById('reschedule_no_date_message').classList.add('hidden');
        
        // Build URL with booking_id to exclude current booking
        const url = `get_gym_availability.php?date=${encodeURIComponent(date)}${currentRescheduleBookingId ? '&exclude_booking_id=' + encodeURIComponent(currentRescheduleBookingId) : ''}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                document.getElementById('reschedule_availability_loading').classList.add('hidden');
                document.getElementById('reschedule_availability_content').classList.remove('hidden');
                
                // Check if date is blocked
                if (data.is_blocked) {
                    document.getElementById('reschedule_blocked_message').classList.remove('hidden');
                    const eventIcon = data.blocked_info?.event_type === 'ceremony' ? '🎓' : 
                                     data.blocked_info?.event_type === 'intramurals' ? '🏅' : '🚫';
                    document.getElementById('reschedule_blocked_icon').textContent = eventIcon;
                    document.getElementById('reschedule_blocked_event').textContent = data.blocked_info?.event_name || 'School Event';
                    document.getElementById('reschedule_blocked_desc').textContent = data.message || '';
                    document.getElementById('reschedule_available_sessions').classList.add('hidden');
                    document.getElementById('reschedule_booked_slots').classList.add('hidden');
                    return;
                } else {
                    document.getElementById('reschedule_blocked_message').classList.add('hidden');
                    document.getElementById('reschedule_available_sessions').classList.remove('hidden');
                    document.getElementById('reschedule_booked_slots').classList.remove('hidden');
                }
                
                // Display available sessions
                const sessionsList = document.getElementById('reschedule_sessions_list');
                sessionsList.innerHTML = '';
                
                if (data.sessions && data.sessions.length > 0) {
                    data.sessions.forEach(session => {
                        if (session.available && session.type !== 'whole_day') {
                            const sessionDiv = document.createElement('div');
                            sessionDiv.className = 'p-2 border border-green-300 rounded-md bg-green-50 hover:bg-green-100 cursor-pointer transition-colors';
                            sessionDiv.onclick = () => selectRescheduleTimeSlot(session.start, session.end);
                            
                            const timeText = formatTimeSlot(session.start, session.end);
                            sessionDiv.innerHTML = `
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-sm font-medium text-green-800">${session.label}</div>
                                        <div class="text-xs text-green-600">${timeText}</div>
                                    </div>
                                    <i class="fas fa-check-circle text-green-600"></i>
                                </div>
                            `;
                            sessionsList.appendChild(sessionDiv);
                        }
                    });
                    
                    if (sessionsList.children.length === 0) {
                        sessionsList.innerHTML = '<p class="text-sm text-gray-500">No available time slots for this date</p>';
                    }
                } else {
                    sessionsList.innerHTML = '<p class="text-sm text-gray-500">No available time slots for this date</p>';
                }
                
                // Display booked slots
                const bookedList = document.getElementById('reschedule_booked_list');
                bookedList.innerHTML = '';
                
                if (data.booked && data.booked.length > 0) {
                    document.getElementById('reschedule_no_bookings').classList.add('hidden');
                    data.booked.forEach(booked => {
                        // Skip lunch break (12:00-13:00) as it's not a real booking
                        if (booked.start === '12:00:00' && booked.end === '13:00:00') {
                            return;
                        }
                        
                        const bookedDiv = document.createElement('div');
                        bookedDiv.className = 'p-2 border border-red-200 rounded-md bg-red-50';
                        const timeText = formatTimeSlot(booked.start, booked.end);
                        bookedDiv.innerHTML = `
                            <div class="text-sm text-red-800">
                                <i class="fas fa-times-circle mr-1"></i>${timeText}
                            </div>
                        `;
                        bookedList.appendChild(bookedDiv);
                    });
                } else {
                    document.getElementById('reschedule_no_bookings').classList.remove('hidden');
                }
            })
            .catch(error => {
                console.error('Error loading availability:', error);
                document.getElementById('reschedule_availability_loading').classList.add('hidden');
                document.getElementById('reschedule_availability_content').innerHTML = 
                    '<p class="text-sm text-red-600">Error loading availability. Please try again.</p>';
            });
    }
    
    // Select time slot from availability
    function selectRescheduleTimeSlot(startTime, endTime) {
        document.getElementById('reschedule_new_start_time').value = startTime.substring(0, 5);
        document.getElementById('reschedule_new_end_time').value = endTime.substring(0, 5);
        
        // Highlight selected slot
        const sessions = document.querySelectorAll('#reschedule_sessions_list > div');
        sessions.forEach(session => {
            session.classList.remove('ring-2', 'ring-blue-500');
            const timeText = session.textContent;
            if (timeText.includes(startTime.substring(0, 5)) && timeText.includes(endTime.substring(0, 5))) {
                session.classList.add('ring-2', 'ring-blue-500');
            }
        });
    }
    
    // Format time slot for display
    function formatTimeSlot(start, end) {
        const startFormatted = formatTime(start);
        const endFormatted = formatTime(end);
        return `${startFormatted} - ${endFormatted}`;
    }
    
    // Add event listener for date change
    document.addEventListener('DOMContentLoaded', function() {
        const dateInput = document.getElementById('reschedule_new_date');
        if (dateInput) {
            dateInput.addEventListener('change', function() {
                const selectedDate = this.value;
                if (selectedDate) {
                    loadRescheduleAvailability(selectedDate);
                } else {
                    resetRescheduleAvailability();
                }
            });
        }
    });
    
    // Helper function to format time
    function formatTime(timeString) {
        if (!timeString) return '';
        const [hours, minutes] = timeString.split(':');
        const date = new Date();
        date.setHours(parseInt(hours));
        date.setMinutes(parseInt(minutes));
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    // Validate reschedule form
    document.getElementById('rescheduleForm')?.addEventListener('submit', function(e) {
        const newDate = document.getElementById('reschedule_new_date').value;
        const newStartTime = document.getElementById('reschedule_new_start_time').value;
        const newEndTime = document.getElementById('reschedule_new_end_time').value;
        
        if (!newDate || !newStartTime || !newEndTime) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }
        
        if (newStartTime >= newEndTime) {
            e.preventDefault();
            alert('End time must be after start time.');
            return false;
        }
        
        // Check if date is in the past
        const selectedDate = new Date(newDate);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        selectedDate.setHours(0, 0, 0, 0);
        
        if (selectedDate < today) {
            e.preventDefault();
            alert('Cannot reschedule to a past date.');
            return false;
        }
        
        return true;
    });
    
    // Add facility modal functions
    function openAddFacilityModal() {
        document.getElementById('addFacilityModal').classList.remove('hidden');
    }
    
    function closeAddFacilityModal() {
        document.getElementById('addFacilityModal').classList.add('hidden');
    }
    
    // Edit facility modal functions
    function openEditFacilityModal(facility) {
        document.getElementById('edit_facility_id').value = facility.id;
        document.getElementById('edit_facility_name').value = facility.name;
        document.getElementById('edit_capacity').value = facility.capacity;
        document.getElementById('edit_description').value = facility.description;
        document.getElementById('edit_status').value = facility.status;
        
        document.getElementById('editFacilityModal').classList.remove('hidden');
    }
    
    function closeEditFacilityModal() {
        document.getElementById('editFacilityModal').classList.add('hidden');
    }
    
    // Delete facility modal functions
    function openDeleteFacilityModal(facilityId, facilityName) {
        document.getElementById('delete_facility_id').value = facilityId;
        document.getElementById('delete_facility_name').textContent = facilityName;
        
        document.getElementById('deleteFacilityModal').classList.remove('hidden');
    }
    
    function closeDeleteFacilityModal() {
        document.getElementById('deleteFacilityModal').classList.add('hidden');
    }
    
    // Add event type modal functions
    function openAddEventTypeModal() {
        document.getElementById('addEventTypeModal').classList.remove('hidden');
        document.getElementById('add-event-type-name').value = '';
    }
    
    function closeAddEventTypeModal() {
        document.getElementById('addEventTypeModal').classList.add('hidden');
    }
    
    // Edit event type modal functions
    function openEditEventTypeModal(eventType) {
        document.getElementById('edit-event-type-id').value = eventType.id;
        document.getElementById('edit-event-type-name').value = eventType.name;
        document.getElementById('editEventTypeModal').classList.remove('hidden');
    }
    
    function closeEditEventTypeModal() {
        document.getElementById('editEventTypeModal').classList.add('hidden');
    }
    
    // Delete event type modal functions
    function openDeleteEventTypeModal(eventTypeId, eventTypeName) {
        document.getElementById('delete-event-type-id').value = eventTypeId;
        document.getElementById('delete-event-type-name').textContent = eventTypeName;
        document.getElementById('deleteEventTypeModal').classList.remove('hidden');
    }
    
    function closeDeleteEventTypeModal() {
        document.getElementById('deleteEventTypeModal').classList.add('hidden');
    }
    
    // Toggle event type status
    function toggleEventTypeStatus(eventTypeId) {
        if (confirm('Are you sure you want to change the status of this event type?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'gym_management.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'toggle_event_type_status';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'event_type_id';
            idInput.value = eventTypeId;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Print receipt function
    function printGymReceipt() {
        if (currentBookingIdForPrint) {
            window.open('print_gym_receipt.php?booking_id=' + currentBookingIdForPrint, '_blank');
        } else {
            alert('Booking ID not available');
        }
    }
    
    // Add equipment/service modal functions
    function openAddEquipmentServiceModal() {
        document.getElementById('addEquipmentServiceModal').classList.remove('hidden');
        document.getElementById('add-equipment-name').value = '';
        document.getElementById('add-equipment-type').value = 'equipment';
        document.getElementById('add-cost-per-hour').value = '0.00';
        document.getElementById('add-equipment-description').value = '';
    }
    
    function closeAddEquipmentServiceModal() {
        document.getElementById('addEquipmentServiceModal').classList.add('hidden');
    }
    
    // Edit equipment/service modal functions
    function openEditEquipmentServiceModal(equipment) {
        document.getElementById('edit-equipment-id').value = equipment.id;
        document.getElementById('edit-equipment-name').value = equipment.name;
        document.getElementById('edit-equipment-type').value = equipment.type;
        document.getElementById('edit-cost-per-hour').value = equipment.cost_per_hour;
        document.getElementById('edit-equipment-description').value = equipment.description || '';
        document.getElementById('editEquipmentServiceModal').classList.remove('hidden');
    }
    
    function closeEditEquipmentServiceModal() {
        document.getElementById('editEquipmentServiceModal').classList.add('hidden');
    }
    
    // Delete equipment/service modal functions
    function openDeleteEquipmentServiceModal(equipmentId, equipmentName) {
        document.getElementById('delete-equipment-id').value = equipmentId;
        document.getElementById('delete-equipment-name').textContent = equipmentName;
        document.getElementById('deleteEquipmentServiceModal').classList.remove('hidden');
    }
    
    function closeDeleteEquipmentServiceModal() {
        document.getElementById('deleteEquipmentServiceModal').classList.add('hidden');
    }
    
    // Toggle equipment/service status
    function toggleEquipmentServiceStatus(equipmentId) {
        if (confirm('Are you sure you want to change the status of this equipment/service?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'gym_management.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'toggle_equipment_service_status';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'equipment_id';
            idInput.value = equipmentId;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
