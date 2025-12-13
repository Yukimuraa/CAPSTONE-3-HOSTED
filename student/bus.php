<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/negros_occidental_locations.php';

// Check if user is student
require_student();

$page_title = "Bus Schedule - CHMSU BAO";
$base_url = "..";

$error = '';
$success = '';

// Check for cancel success message
if (isset($_GET['cancel_success']) && $_GET['cancel_success'] == 1) {
    $success = 'Bus request cancelled successfully!';
}

// Create uploads directory if it doesn't exist
$upload_dir = "../uploads/bus_approvals/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Ensure approval_document column exists in bus_schedules table
$check_column = $conn->query("SHOW COLUMNS FROM bus_schedules LIKE 'approval_document'");
if ($check_column->num_rows == 0) {
    $conn->query("ALTER TABLE bus_schedules ADD COLUMN approval_document VARCHAR(255) NULL AFTER status");
}

// Ensure bus_settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS bus_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Get user profile picture
$user_stmt = $conn->prepare("SELECT profile_pic FROM user_accounts WHERE id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$profile_pic = $user_data['profile_pic'] ?? '';

// Function to check bus availability
function checkBusAvailability($conn, $date_covered, $no_of_vehicles) {
    // Get total available buses (exclude maintenance and out_of_service)
    $total_buses_query = "SELECT COUNT(*) as total FROM buses WHERE status = 'available'";
    $total_buses_result = $conn->query($total_buses_query);
    $total_buses = $total_buses_result->fetch_assoc()['total'];
    
    // Get booked buses for the specific date
    $booked_query = "SELECT COUNT(DISTINCT bb.bus_id) as booked 
                     FROM bus_bookings bb 
                     JOIN bus_schedules bs ON bb.schedule_id = bs.id 
                     WHERE bs.date_covered = ? AND bb.status = 'active'";
    $booked_stmt = $conn->prepare($booked_query);
    $booked_stmt->bind_param("s", $date_covered);
    $booked_stmt->execute();
    $booked_result = $booked_stmt->get_result();
    $booked_buses = $booked_result->fetch_assoc()['booked'];
    
    $available_buses = $total_buses - $booked_buses;
    
    return [
        'total_buses' => $total_buses,
        'booked_buses' => $booked_buses,
        'available_buses' => $available_buses,
        'can_book' => $available_buses >= $no_of_vehicles
    ];
}

// Function to check if specific bus is available on a date
function isBusAvailable($conn, $bus_number, $date_covered) {
    $query = "SELECT COUNT(*) as booked 
              FROM bus_bookings bb 
              JOIN bus_schedules bs ON bb.schedule_id = bs.id 
              JOIN buses b ON bb.bus_id = b.id 
              WHERE b.bus_number = ? AND bs.date_covered = ? AND bb.status = 'active'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $bus_number, $date_covered);
    $stmt->execute();
    $result = $stmt->get_result();
    $booked = $result->fetch_assoc()['booked'];
    
    return $booked == 0;
}

// Function to check if bus is available for a date range
function checkBusAvailabilityForRange($conn, $bus_number, $start_date, $end_date) {
    // Get bus ID
    $bus_id = getBusIdByNumber($conn, $bus_number);
    if (!$bus_id) {
        return ['available' => false, 'conflicting_dates' => []];
    }
    
    // Check for any bookings that overlap with the date range
    $query = "SELECT DISTINCT bs.date_covered 
              FROM bus_bookings bb 
              JOIN bus_schedules bs ON bb.schedule_id = bs.id 
              WHERE bb.bus_id = ? 
              AND bb.status = 'active' 
              AND bs.status IN ('pending', 'approved')
              AND bs.date_covered BETWEEN ? AND ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $bus_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $conflicting_dates = [];
    while ($row = $result->fetch_assoc()) {
        $conflicting_dates[] = $row['date_covered'];
    }
    
    return [
        'available' => count($conflicting_dates) == 0,
        'conflicting_dates' => $conflicting_dates
    ];
}

// Function to get bus ID by bus number
function getBusIdByNumber($conn, $bus_number) {
    $query = "SELECT id FROM buses WHERE bus_number = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $bus_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? $row['id'] : null;
}

// LOCAL DISTANCE CALCULATION - No APIs required
// Uses hardcoded coordinates for all Negros Occidental locations

// Function to get distance between two locations
function getDistanceBetweenLocations($from, $to) {
    require_once '../includes/negros_occidental_locations.php';
    
    // Check if both are CHMSU campuses (campus to campus)
    $fromIsCHMSU = (stripos($from, 'chmsu') !== false || stripos($from, 'carlos hilado') !== false);
    $toIsCHMSU = (stripos($to, 'chmsu') !== false || stripos($to, 'carlos hilado') !== false);
    
    if ($fromIsCHMSU && $toIsCHMSU) {
        // Both are CHMSU campuses - calculate distance between them
        $fromLocation = NegrosOccidentalLocations::findLocation($from);
        $toLocation = NegrosOccidentalLocations::findLocation($to);
        
        if ($fromLocation && $toLocation) {
            $distance = NegrosOccidentalLocations::calculateDistance(
                $fromLocation['lat'], 
                $fromLocation['lon'],
                $toLocation['lat'], 
                $toLocation['lon']
            );
            return max($distance, 5); // Minimum 5km
        }
    }
    
    // If FROM is CHMSU Talisay, calculate to destination
    if (stripos($from, 'talisay') !== false && $fromIsCHMSU) {
        $result = NegrosOccidentalLocations::getDistanceFromCHMSU($to);
        if ($result) {
            return max($result['distance_km'], 5);
        }
    }
    
    // If TO is CHMSU Talisay, calculate from origin
    if (stripos($to, 'talisay') !== false && $toIsCHMSU) {
        $result = NegrosOccidentalLocations::getDistanceFromCHMSU($from);
        if ($result) {
            return max($result['distance_km'], 5);
        }
    }
    
    // Default: use TO as destination from CHMSU Talisay
    $result = NegrosOccidentalLocations::getDistanceFromCHMSU($to);
    if ($result) {
        return max($result['distance_km'], 5);
    }
    
    // Ultimate fallback
    return 50;
}

// Function to calculate price based on fixed pricing structure
function calculatePriceFromDistance($distance_km) {
    // Fixed pricing structure based on round trip distance (km)
    if ($distance_km >= 20 && $distance_km <= 50) {
        return 1000;
    } elseif ($distance_km >= 51 && $distance_km <= 80) {
        return 2000;
    } elseif ($distance_km >= 81 && $distance_km <= 110) {
        return 3000;
    } elseif ($distance_km >= 111 && $distance_km <= 140) {
        return 4000;
    } elseif ($distance_km >= 141 && $distance_km <= 170) {
        return 5000;
    } elseif ($distance_km >= 171 && $distance_km <= 200) {
        return 6000;
    } elseif ($distance_km >= 201 && $distance_km <= 230) {
        return 7000;
    } elseif ($distance_km >= 231 && $distance_km <= 260) {
        return 8000;
    } elseif ($distance_km >= 261 && $distance_km <= 290) {
        return 9000;
    } elseif ($distance_km >= 291 && $distance_km <= 320) {
        return 10000;
    } elseif ($distance_km >= 321 && $distance_km <= 350) {
        return 11000;
    } elseif ($distance_km >= 351 && $distance_km <= 380) {
        return 12000;
    } elseif ($distance_km >= 381 && $distance_km <= 410) {
        return 13000;
    } else {
        // For distances outside the range, use the closest tier
        if ($distance_km < 20) {
            return 1000; // Minimum price
        } else {
            return 13000; // Maximum price for distances > 410 km
        }
    }
}

// Function to calculate billing statement
function calculateBillingStatement($from_location, $to_location, $destination, $no_of_vehicles, $no_of_days, $to_location_continuation = '') {
    global $conn;
    
    // Get distance from CHMSU to first location
    $distance_km = getDistanceBetweenLocations($from_location, $to_location);
    
    // If continuation exists, add distance from first location to continuation
    if (!empty($to_location_continuation)) {
        $continuation_distance = getDistanceBetweenLocations($to_location, $to_location_continuation);
        $distance_km += $continuation_distance;
    }
    
    $total_distance_km = $distance_km * 2; // Round trip
    
    // Get fuel rate from database settings
    $fuel_rate_query = $conn->query("SELECT setting_value FROM bus_settings WHERE setting_key = 'fuel_rate'");
    $fuel_rate = 45.00; // Default fallback
    if ($fuel_rate_query && $fuel_rate_query->num_rows > 0) {
        $fuel_rate = floatval($fuel_rate_query->fetch_assoc()['setting_value']);
    }
    
    // Get cost settings from database (with defaults)
    $cost_settings = [
        'runtime_liters' => 25.00,
        'maintenance_cost' => 5000.00,
        'standby_cost' => 1500.00,
        'additive_cost' => 1500.00
    ];
    
    // Load cost settings from database
    foreach ($cost_settings as $key => $default_value) {
        $setting_query = $conn->query("SELECT setting_value FROM bus_settings WHERE setting_key = '$key'");
        if ($setting_query && $setting_query->num_rows > 0) {
            $cost_settings[$key] = floatval($setting_query->fetch_assoc()['setting_value']);
        }
    }
    
    // Calculate costs
    $computed_distance = $distance_km; // One-way distance
    $runtime_liters = $cost_settings['runtime_liters'];
    
    // Calculate fuel cost: Computed distance × Fuel Rate
    $fuel_cost = $computed_distance * $fuel_rate;
    
    // Runtime cost is fixed at 1,750
    $runtime_cost = 1750.00;
    
    // Other costs from settings
    $maintenance_cost = $cost_settings['maintenance_cost'];
    $standby_cost = $cost_settings['standby_cost'];
    $additive_cost = $cost_settings['additive_cost'];
    
    // Calculate Rate per Bus: Sum of all costs
    $rate_per_bus = $fuel_cost + $runtime_cost + $maintenance_cost + $standby_cost + $additive_cost;
    
    // Calculate total amount: Rate per Bus × Number of Vehicles
    $total_amount = $rate_per_bus * $no_of_vehicles;
    
    // Subtotal per vehicle (same as rate_per_bus for compatibility)
    $subtotal_per_vehicle = $rate_per_bus;
    
    return [
        'from_location' => $from_location,
        'to_location' => $to_location,
        'distance_km' => $distance_km,
        'total_distance_km' => $total_distance_km,
        'fuel_rate' => $fuel_rate,
        'computed_distance' => $computed_distance,
        'runtime_liters' => $runtime_liters,
        'fuel_cost' => $fuel_cost,
        'runtime_cost' => $runtime_cost,
        'maintenance_cost' => $maintenance_cost,
        'standby_cost' => $standby_cost,
        'additive_cost' => $additive_cost,
        'rate_per_bus' => $rate_per_bus,
        'subtotal_per_vehicle' => $subtotal_per_vehicle,
        'total_amount' => $total_amount
    ];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Handle cancel request
        if ($_POST['action'] === 'cancel_request') {
            $schedule_id = intval($_POST['schedule_id']);
            
            // Verify the schedule belongs to the current user and is pending
            $verify_stmt = $conn->prepare("SELECT status FROM bus_schedules WHERE id = ? AND user_id = ?");
            $verify_stmt->bind_param("ii", $schedule_id, $_SESSION['user_id']);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            if ($verify_result->num_rows === 0) {
                $error = 'Request not found or you do not have permission to cancel it.';
            } else {
                $schedule_data = $verify_result->fetch_assoc();
                
                if ($schedule_data['status'] !== 'pending') {
                    $error = 'Only pending requests can be cancelled.';
                } else {
                    // Update status to cancelled
                    $cancel_stmt = $conn->prepare("UPDATE bus_schedules SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                    $cancel_stmt->bind_param("i", $schedule_id);
                    
                    if ($cancel_stmt->execute()) {
                        // Redirect to refresh the page and show updated status
                        header("Location: bus.php?cancel_success=1");
                        exit();
                    } else {
                        $error = 'Error cancelling request: ' . $conn->error;
                    }
                }
            }
        }
        elseif ($_POST['action'] === 'add_schedule') {
            $school_name = sanitize_input($_POST['school_name']);
            $client = sanitize_input($_POST['client']);
            $from_location = sanitize_input($_POST['from_location']);
            $to_location = sanitize_input($_POST['to_location']);
            $to_location_continuation = isset($_POST['to_location_continuation']) ? trim(sanitize_input($_POST['to_location_continuation'])) : '';
            
            // Build destination string with optional continuation
            $destination = $from_location . ' - ' . $to_location;
            if (!empty($to_location_continuation)) {
                $destination .= ' - ' . $to_location_continuation;
            }
            $purpose = sanitize_input($_POST['purpose']);
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $vehicle = sanitize_input($_POST['vehicle']);
            
            // Handle bus selection (can be array from checkboxes)
            $bus_no_array = isset($_POST['bus_no']) ? $_POST['bus_no'] : [];
            if (!is_array($bus_no_array)) {
                $bus_no_array = [$bus_no_array];
            }
            
            // Validate at least one bus is selected
            if (empty($bus_no_array) || (count($bus_no_array) === 1 && empty($bus_no_array[0]))) {
                $error = 'Please select at least one bus.';
            } else {
                // Use first bus for primary bus_no field (for backward compatibility)
                $bus_no = sanitize_input($bus_no_array[0]);
                // Set number of vehicles to match selected buses
                $no_of_vehicles = count($bus_no_array);
            }
            
            $no_of_days = intval($_POST['no_of_days']);
            
            // Calculate date_covered (use start_date for compatibility)
            $date_covered = $start_date;
            
            // Handle approval document upload
            $approval_document_path = null;
            if (!isset($_FILES['approval_document']) || $_FILES['approval_document']['error'] !== 0) {
                $error = 'President approval document is required. Please upload the approval document.';
            } else {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg', 'application/pdf'];
                $file_type = $_FILES['approval_document']['type'];
                
                if (!in_array($file_type, $allowed_types)) {
                    $error = 'Invalid file type. Only JPG, PNG, GIF, and PDF files are allowed for approval documents.';
                } elseif ($_FILES['approval_document']['size'] > 5242880) { // 5MB limit
                    $error = 'File size too large. Maximum file size is 5MB.';
                } else {
                    $file_extension = pathinfo($_FILES['approval_document']['name'], PATHINFO_EXTENSION);
                    $file_name = 'approval_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['approval_document']['tmp_name'], $target_path)) {
                        $approval_document_path = 'uploads/bus_approvals/' . $file_name;
                    } else {
                        $error = 'Error uploading approval document. Please try again.';
                    }
                }
            }
            
            // Validate dates
            if (!empty($error)) {
                // Error already set from file upload validation
            } elseif (empty($start_date) || empty($end_date)) {
                $error = 'Start date and end date are required.';
            } else {
                // Validate that start date is at least 3 days in advance
                $start_date_obj = new DateTime($start_date);
                $today = new DateTime();
                $today->setTime(0, 0, 0); // Set time to beginning of day
                $min_start_date = clone $today;
                $min_start_date->modify('+3 days'); // Add 3 days to today
                
                if ($start_date_obj < $min_start_date) {
                    $error = "Bus reservations must be made at least 3 days in advance. The earliest available start date is " . $min_start_date->format('F j, Y') . ".";
                } elseif (strtotime($end_date) < strtotime($start_date)) {
                    $error = 'End date cannot be before start date.';
                } elseif ($no_of_days <= 0) {
                    $error = 'Invalid date range. Number of days must be at least 1.';
                } elseif (empty($approval_document_path)) {
                    $error = 'President approval document is required.';
                }
            }
            
            // Continue with other validations if no error yet
            if (empty($error)) {
                // First, check if all selected buses have status = 'available' (not maintenance or out_of_service)
                $invalid_status_buses = [];
                foreach ($bus_no_array as $selected_bus) {
                    $selected_bus = sanitize_input($selected_bus);
                    if (!empty($selected_bus)) {
                        $status_check = $conn->prepare("SELECT status FROM buses WHERE bus_number = ?");
                        $status_check->bind_param("s", $selected_bus);
                        $status_check->execute();
                        $status_result = $status_check->get_result();
                        if ($status_row = $status_result->fetch_assoc()) {
                            if ($status_row['status'] !== 'available') {
                                $invalid_status_buses[] = $selected_bus;
                            }
                        } else {
                            $invalid_status_buses[] = $selected_bus; // Bus doesn't exist
                        }
                    }
                }
                
                if (!empty($invalid_status_buses)) {
                    $error = "Bus(es) " . implode(', ', $invalid_status_buses) . " are not available for booking (may be in maintenance or out of service). Please select different buses.";
                } else {
                    // Check if all selected buses are available for the date range
                    $unavailable_buses = [];
                    foreach ($bus_no_array as $selected_bus) {
                        $selected_bus = sanitize_input($selected_bus);
                        if (!empty($selected_bus)) {
                            $date_check = checkBusAvailabilityForRange($conn, $selected_bus, $start_date, $end_date);
                    if (!$date_check['available']) {
                                $unavailable_buses[] = $selected_bus;
                            }
                        }
                    }
                }
                
                if (!empty($unavailable_buses)) {
                    $error = "Bus(es) " . implode(', ', $unavailable_buses) . " are already booked for one or more dates in the selected range. Please select different buses or date range.";
                } elseif (empty($school_name) || empty($client) || empty($from_location) || empty($to_location) || empty($purpose) || empty($vehicle) || empty($bus_no)) {
                    $error = 'All required fields must be filled.';
                } elseif ($from_location === $to_location) {
                    $error = 'From and To locations must be different.';
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Combine school name and client organization
                        $full_client = $school_name . ' - ' . $client;
                        
                        // Store all selected bus numbers (comma-separated for backward compatibility)
                        $bus_no_string = implode(', ', array_map('sanitize_input', $bus_no_array));
                        
                        // Insert bus schedule with approval document
                        $stmt = $conn->prepare("INSERT INTO bus_schedules (client, destination, purpose, date_covered, vehicle, bus_no, no_of_days, no_of_vehicles, user_id, user_type, status, approval_document) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'pending', ?)");
                        $stmt->bind_param("sssssssiis", $full_client, $destination, $purpose, $date_covered, $vehicle, $bus_no_string, $no_of_days, $no_of_vehicles, $_SESSION['user_id'], $approval_document_path);
                        
                        if ($stmt->execute()) {
                            $schedule_id = $conn->insert_id;
                            
                            // Process all selected buses
                            foreach ($bus_no_array as $selected_bus_no) {
                                $selected_bus_no = sanitize_input($selected_bus_no);
                                if (empty($selected_bus_no)) continue;
                            
                            // Get bus ID and create bus booking record
                                $bus_id = getBusIdByNumber($conn, $selected_bus_no);
                            if (!$bus_id) {
                                    throw new Exception("Invalid bus number: {$selected_bus_no}");
                            }
                            
                            // Insert bus booking records for each day in the date range
                            $current_date = $start_date;
                            $end_timestamp = strtotime($end_date);
                            
                            while (strtotime($current_date) <= $end_timestamp) {
                                $bus_booking_stmt = $conn->prepare("INSERT INTO bus_bookings (schedule_id, bus_id, booking_date, status) VALUES (?, ?, ?, 'active')");
                                $bus_booking_stmt->bind_param("iis", $schedule_id, $bus_id, $current_date);
                                
                                if (!$bus_booking_stmt->execute()) {
                                    throw new Exception('Error creating bus booking: ' . $conn->error);
                                }
                                
                                // Move to next day
                                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                            }
                            
                            // Update bus status to booked when booking is created
                            $update_bus_status = $conn->prepare("UPDATE buses SET status = 'booked' WHERE id = ?");
                            $update_bus_status->bind_param("i", $bus_id);
                            $update_bus_status->execute();
                            }
                            
                            // Calculate billing statement (include continuation if provided)
                            $billing = calculateBillingStatement($from_location, $to_location, $destination, $no_of_vehicles, $no_of_days, $to_location_continuation);
                            
                            // Insert billing statement with default payment_status as 'pending'
                            $billing_stmt = $conn->prepare("INSERT INTO billing_statements 
                                (schedule_id, client, destination, purpose, date_covered, no_of_days, vehicle, bus_no, no_of_vehicles,
                                 from_location, to_location, distance_km, total_distance_km, fuel_rate, computed_distance, runtime_liters,
                                 fuel_cost, runtime_cost, maintenance_cost, standby_cost, additive_cost, rate_per_bus, subtotal_per_vehicle, total_amount,
                                 prepared_by, recommending_approval, payment_status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                            
                            $prepared_by = $_SESSION['user_name'] ?? 'Student';
                            $recommending = 'NEUYER JAN C. BALA-AN, Director, Business Affairs Office';
                            
                            $billing_stmt->bind_param("isssisissdddddddddddddddss", 
                                $schedule_id, 
                                $full_client, 
                                $destination, 
                                $purpose, 
                                $date_covered, 
                                $no_of_days, 
                                $vehicle, 
                                $bus_no_string, 
                                $no_of_vehicles,
                                $billing['from_location'], 
                                $billing['to_location'], 
                                $billing['distance_km'], 
                                $billing['total_distance_km'], 
                                $billing['fuel_rate'], 
                                $billing['computed_distance'], 
                                $billing['runtime_liters'],
                                $billing['fuel_cost'], 
                                $billing['runtime_cost'], 
                                $billing['maintenance_cost'], 
                                $billing['standby_cost'], 
                                $billing['additive_cost'], 
                                $billing['rate_per_bus'], 
                                $billing['subtotal_per_vehicle'], 
                                $billing['total_amount'],
                                $prepared_by, 
                                $recommending);
                            
                            if ($billing_stmt->execute()) {
                                $conn->commit();
                                
                                // Send notification to user
                                require_once '../includes/notification_functions.php';
                                $start_formatted = date('F j, Y', strtotime($start_date));
                                $end_formatted = date('F j, Y', strtotime($end_date));
                                $date_range = $no_of_days > 1 ? "{$start_formatted} to {$end_formatted}" : $start_formatted;
                                create_notification($_SESSION['user_id'], "Bus Schedule Submitted", "Your bus schedule request for {$date_range} (Destination: {$destination}) has been submitted and is pending approval.", "request", "student/bus.php");
                                
                                // Send notification to all admins
                                $user_name = $_SESSION['user_sessions']['student']['user_name'] ?? 'Student';
                                create_notification_for_admins("New Bus Schedule Request", "{$user_name} has submitted a new bus schedule request for {$date_range} (Destination: {$destination}). Please review and approve.", "request", "admin/bus.php");
                                
                                // Redirect to show success modal
                                header("Location: bus.php?submitted=1");
                                exit();
                            } else {
                                throw new Exception('Error creating billing statement: ' . $conn->error);
                            }
                        } else {
                            throw new Exception('Error saving schedule: ' . $conn->error);
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                } // Close the else block (all validations passed)
            } // Close the if (empty($error)) block
        } // Close the elseif (add_schedule action)
    } // Close the if (isset action)
} // Close the if (POST request)

// Get filter and pagination parameters
$status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query with status filter
$where_clause = "WHERE bs.user_id = ?";
$status_param = null;

if ($status_filter === 'pending') {
    $where_clause .= " AND bs.status = ?";
    $status_param = 'pending';
} elseif ($status_filter === 'approved') {
    $where_clause .= " AND bs.status = ?";
    $status_param = 'approved';
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total 
                FROM bus_schedules bs 
                $where_clause";
$count_stmt = $conn->prepare($count_query);
if ($status_param) {
    $count_stmt->bind_param("is", $_SESSION['user_id'], $status_param);
} else {
    $count_stmt->bind_param("i", $_SESSION['user_id']);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = $total_rows > 0 ? ceil($total_rows / $per_page) : 1;

// Get paginated bus schedules with billing information and bus plate number
$user_schedules_query = "SELECT bs.*, bst.total_amount, bst.payment_status, b.plate_number, u.name as user_name, u.email as user_email
                        FROM bus_schedules bs 
                        LEFT JOIN billing_statements bst ON bs.id = bst.schedule_id 
                        LEFT JOIN buses b ON bs.bus_no = b.bus_number
                        LEFT JOIN user_accounts u ON bs.user_id = u.id
                        $where_clause
                        ORDER BY bs.id DESC, bs.created_at DESC 
                        LIMIT $per_page OFFSET $offset";
$user_schedules_stmt = $conn->prepare($user_schedules_query);
if ($status_param) {
    $user_schedules_stmt->bind_param("is", $_SESSION['user_id'], $status_param);
} else {
    $user_schedules_stmt->bind_param("i", $_SESSION['user_id']);
}
$user_schedules_stmt->execute();
$user_schedules = $user_schedules_stmt->get_result();

// Get statistics for current user
$stats_query = "SELECT 
    COUNT(*) as total_requests,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_requests
    FROM bus_schedules 
    WHERE user_id = ?";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $_SESSION['user_id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get only available buses (exclude maintenance and out_of_service)
$buses_query = "SELECT id, bus_number, vehicle_type, capacity, status FROM buses WHERE status = 'available' ORDER BY bus_number ASC";
$buses_result = $conn->query($buses_query);
$available_buses = [];
while ($bus = $buses_result->fetch_assoc()) {
    $available_buses[] = $bus;
}
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/student_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Bus Schedule Management</h1>
                    <p class="text-sm text-gray-500">Request and manage your bus transportation needs</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="profile.php" class="flex items-center">
                        <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                            <img src="../<?php echo htmlspecialchars($profile_pic); ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover border-2 border-gray-300 hover:border-blue-500 transition-colors cursor-pointer">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gray-300 flex items-center justify-center border-2 border-gray-300 hover:border-blue-500 transition-colors cursor-pointer">
                                <i class="fas fa-user text-gray-600"></i>
                            </div>
                        <?php endif; ?>
                    </a>
                    <span class="text-gray-700 hidden sm:inline"><?php echo $_SESSION['user_name']; ?></span>
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
                
                <!-- Important Reminder Notice -->
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-6 mb-6 rounded-lg shadow-md">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400 text-3xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-bold text-yellow-800 mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Important Reminder
                            </h3>
                            <div class="text-sm text-yellow-700 space-y-2">
                                <p class="font-semibold">
                                    You must submit a letter to the president for approval before requesting a bus schedule.
                                </p>
                                <p>
                                    After it is approved, you can request a bus schedule. Please ensure you have the approval document ready before submitting your request.
                                </p>
                                <div class="mt-3 p-3 bg-yellow-100 rounded border border-yellow-300">
                                    <p class="font-semibold text-yellow-900 mb-1">
                                        <i class="fas fa-list-ol mr-2"></i>Process Steps:
                                    </p>
                                    <ol class="list-decimal list-inside space-y-1 text-yellow-800">
                                        <li>Submit a letter to the President's Office requesting bus schedule approval</li>
                                        <li>Wait for the president's approval and obtain the signed document</li>
                                        <li>Upload the approved document when requesting a bus schedule</li>
                                        <li>Submit your bus schedule request with the approval document</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white overflow-hidden shadow rounded-lg">
                        <div class="p-5">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-bus text-blue-600 text-2xl"></i>
                                </div>
                                <div class="ml-5 w-0 flex-1">
                                    <dl>
                                        <dt class="text-sm font-medium text-gray-500 truncate">Total Requests</dt>
                                        <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_requests']; ?></dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white overflow-hidden shadow rounded-lg">
                        <div class="p-5">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                                </div>
                                <div class="ml-5 w-0 flex-1">
                                    <dl>
                                        <dt class="text-sm font-medium text-gray-500 truncate">Approved</dt>
                                        <dd class="text-lg font-medium text-gray-900"><?php echo $stats['approved_requests']; ?></dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white overflow-hidden shadow rounded-lg">
                        <div class="p-5">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-clock text-yellow-600 text-2xl"></i>
                                </div>
                                <div class="ml-5 w-0 flex-1">
                                    <dl>
                                        <dt class="text-sm font-medium text-gray-500 truncate">Pending</dt>
                                        <dd class="text-lg font-medium text-gray-900"><?php echo $stats['pending_requests']; ?></dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white overflow-hidden shadow rounded-lg">
                        <div class="p-5">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-times-circle text-red-600 text-2xl"></i>
                                </div>
                                <div class="ml-5 w-0 flex-1">
                                    <dl>
                                        <dt class="text-sm font-medium text-gray-500 truncate">Rejected</dt>
                                        <dd class="text-lg font-medium text-gray-900"><?php echo $stats['rejected_requests']; ?></dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold tracking-tight text-gray-900">My Bus Schedules</h2>
                    <button type="button" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" onclick="openAddModal()">
                        <i class="fas fa-plus mr-2"></i> New Request
                    </button>
                </div>
                
                <!-- Status Filter Tabs -->
                <div class="mb-4 flex space-x-2">
                    <a href="?status=all" 
                       class="px-4 py-2 rounded-lg <?php echo $status_filter === 'all' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-list mr-2"></i>All
                    </a>
                    <a href="?status=pending" 
                       class="px-4 py-2 rounded-lg <?php echo $status_filter === 'pending' ? 'bg-yellow-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-clock mr-2"></i>Pending (<?php echo $stats['pending_requests']; ?>)
                    </a>
                    <a href="?status=approved" 
                       class="px-4 py-2 rounded-lg <?php echo $status_filter === 'approved' ? 'bg-green-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-check-circle mr-2"></i>Approved (<?php echo $stats['approved_requests']; ?>)
                    </a>
                </div>
                
                <!-- Schedules Table -->
                <div class="bg-white shadow overflow-hidden sm:rounded-md">
                    <?php if ($user_schedules->num_rows > 0): ?>
                        <ul class="divide-y divide-gray-200">
                            <?php while ($schedule = $user_schedules->fetch_assoc()): ?>
                                <li class="px-6 py-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-bus text-blue-600 text-xl"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="flex items-center">
                                                    <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($schedule['destination']); ?></p>
                                                    <?php
                                                    // Display status badge based on actual status
                                                    $status_value = isset($schedule['status']) ? trim(strtolower($schedule['status'])) : 'pending';
                                                    $status_class = '';
                                                    $status_text = '';
                                                    $status_icon = '';
                                                    
                                                    switch ($status_value) {
                                                        case 'pending':
                                                            $status_class = 'bg-yellow-100 text-yellow-800';
                                                            $status_text = 'Pending';
                                                            $status_icon = 'fa-clock';
                                                            break;
                                                        case 'approved':
                                                            $status_class = 'bg-green-100 text-green-800';
                                                            $status_text = 'Approved';
                                                            $status_icon = 'fa-check-circle';
                                                            break;
                                                        case 'rejected':
                                                            $status_class = 'bg-red-100 text-red-800';
                                                            $status_text = 'Rejected';
                                                            $status_icon = 'fa-times-circle';
                                                            break;
                                                        case 'cancelled':
                                                        case 'canceled':
                                                            $status_class = 'bg-gray-100 text-gray-800';
                                                            $status_text = 'Cancelled';
                                                            $status_icon = 'fa-ban';
                                                            break;
                                                        case 'completed':
                                                            $status_class = 'bg-blue-100 text-blue-800';
                                                            $status_text = 'Completed';
                                                            $status_icon = 'fa-flag-checkered';
                                                            break;
                                                        default:
                                                            // For any unknown status, default to Cancelled
                                                            $status_class = 'bg-gray-100 text-gray-800';
                                                            $status_text = 'Cancelled';
                                                            $status_icon = 'fa-ban';
                                                    }
                                                    ?>
                                                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                                        <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </div>
                                                <div class="mt-1">
                                                    <p class="text-sm text-gray-500">
                                                        <i class="fas fa-calendar mr-1"></i>
                                                        <?php echo date('M d, Y', strtotime($schedule['date_covered'])); ?>
                                                        <span class="mx-2">•</span>
                                                        <i class="fas fa-building mr-1"></i>
                                                        <?php echo htmlspecialchars($schedule['client']); ?>
                                                        <span class="mx-2">•</span>
                                                        <i class="fas fa-bus mr-1"></i>
                                                        Bus #<?php echo htmlspecialchars($schedule['bus_no']); ?>
                                                    </p>
                                                </div>
                                                <div class="mt-1">
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($schedule['purpose']); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span class="text-sm text-gray-500">
                                                <?php echo $schedule['no_of_days']; ?> day<?php echo $schedule['no_of_days'] > 1 ? 's' : ''; ?>
                                            </span>
                                            <button type="button" class="text-blue-600 hover:text-blue-900" title="View Details" onclick="viewSchedule(<?php echo htmlspecialchars(json_encode($schedule)); ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($schedule['status'] === 'pending'): ?>
                                                <button type="button" class="text-red-600 hover:text-red-900" title="Cancel Request" onclick="openCancelModal(<?php echo $schedule['id']; ?>, '<?php echo htmlspecialchars($schedule['destination'], ENT_QUOTES); ?>')">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-bus text-gray-400 text-6xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">
                                <?php if ($status_filter === 'pending'): ?>
                                    No pending requests
                                <?php elseif ($status_filter === 'approved'): ?>
                                    No approved requests
                                <?php else: ?>
                                    No bus schedules yet
                                <?php endif; ?>
                            </h3>
                            <p class="text-gray-500 mb-4">
                                <?php if ($status_filter === 'pending' || $status_filter === 'approved'): ?>
                                    <a href="?status=all" class="text-blue-600 hover:underline">View all requests</a>
                                <?php else: ?>
                                    Get started by creating your first bus schedule request.
                                <?php endif; ?>
                            </p>
                            <?php if ($status_filter === 'all'): ?>
                                <button type="button" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700" onclick="openAddModal()">
                                    <i class="fas fa-plus mr-2"></i> Create Request
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-4 bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                    <div class="flex-1 flex justify-between sm:hidden">
                        <?php if ($page > 1): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Previous
                            </a>
                        <?php else: ?>
                            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-white cursor-not-allowed">
                                Previous
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
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
                                    <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page - 1; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
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
                                    <a href="?status=<?php echo $status_filter; ?>&page=1" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <span class="relative inline-flex items-center px-4 py-2 border border-blue-500 bg-blue-50 text-sm font-medium text-blue-600 z-10"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $i; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                                    <?php endif; ?>
                                    <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $total_pages; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50"><?php echo $total_pages; ?></a>
                                <?php endif; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
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
        </main>
    </div>
</div>

<!-- Add Schedule Modal -->
<div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-2xl max-h-screen overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">New Bus Schedule Request</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeAddModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <!-- Reminder in Modal -->
        <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6 rounded">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-semibold text-blue-800">
                        Reminder: President approval is required
                    </p>
                    <p class="text-xs text-blue-700 mt-1">
                        Make sure you have obtained approval from the president before submitting this request. You will need to upload the approval document below.
                    </p>
                </div>
            </div>
        </div>
        
        <form id="busRequestForm" method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_schedule">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="school_name" class="block text-sm font-medium text-gray-700 mb-1">School Name *</label>
                    <input type="text" id="school_name" name="school_name" required 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                               placeholder="e.g., CHMSU - Carlos Hilado Memorial State University, Talisay City."
                           value="<?php echo isset($_POST['school_name']) ? htmlspecialchars($_POST['school_name']) : 'CHMSU - Carlos Hilado Memorial State University, Talisay City.'; ?>">
                </div>
                
                <div>
                    <label for="client" class="block text-sm font-medium text-gray-700 mb-1">Client/Organization *</label>
                    <input type="text" id="client" name="client" required 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                           placeholder="e.g., OSAS, Student Council, BSIT Department"
                           value="<?php echo isset($_POST['client']) ? htmlspecialchars($_POST['client']) : ''; ?>">
                </div>
                
                <div>
                    <label for="from_location" class="block text-sm font-medium text-gray-700 mb-1">From Location *</label>
                    <input type="text" id="from_location" name="from_location" 
                           required 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                           placeholder="CHMSU Talisay (Fixed Origin)"
                           value="CHMSU - Carlos Hilado Memorial State University, Talisay City"
                           readonly
                           style="background-color: #f3f4f6; cursor: not-allowed;">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-university text-blue-600 mr-1"></i>
                        Fixed origin: CHMSU Talisay Campus
                    </p>
                </div>
                
                <div>
                    <label for="to_location" class="block text-sm font-medium text-gray-700 mb-1">To Location *</label>
                    <div class="relative">
                        <input type="text" id="to_location" name="to_location" 
                               required 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                               placeholder="Type city, barangay, or purok (e.g., Bacolod, Barangay 5 Silay, Purok 2 Talisay)"
                               value=""
                               autocomplete="off"
                               oninput="handleLocationInput(event)"
                               onfocus="showLocationSuggestions()"
                               onblur="hideLocationSuggestions()">
                        <div id="location-suggestions" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg hidden max-h-60 overflow-y-auto">
                            <!-- Suggestions will be populated here -->
                        </div>
                    </div>
                </div>
                
                <div>
                    <label for="to_location_continuation" class="block text-sm font-medium text-gray-700 mb-1">To Another Location (Optional)</label>
                    <div class="relative">
                        <input type="text" id="to_location_continuation" name="to_location_continuation" 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                               placeholder="e.g., CHMSU Fortune Towne Campus, CHMSU Binalbagan Campus"
                               value=""
                               autocomplete="off"
                               oninput="handleLocationInputContinuation(event)"
                               onfocus="showLocationSuggestionsContinuation()"
                               onblur="hideLocationSuggestionsContinuation()">
                        <div id="location-suggestions-continuation" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg hidden max-h-60 overflow-y-auto">
                            <!-- Suggestions will be populated here -->
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle text-blue-600 mr-1"></i>
                        Optional: Add a continuation destination (e.g., another CHMSU campus)
                    </p>
                </div>
                
                <div class="md:col-span-2">
                    <!-- Distance display removed - calculation not shown to students -->
                    <div id="distance-display" class="hidden"></div>
                    
                    <!-- Pricing Guide -->
                    <div class="mt-3">
                        <button type="button" onclick="togglePricingGuide()" class="flex items-center justify-between w-full text-left p-3 bg-gray-50 border border-gray-200 rounded-md hover:bg-gray-100 transition-colors">
                            <span class="text-sm font-medium text-blue-600">
                                <i class="fas fa-info-circle text-blue-600 mr-2"></i>View Pricing Guide
                            </span>
                            <i class="fas fa-chevron-down text-gray-400" id="pricingGuideIcon"></i>
                        </button>
                        <div id="pricingGuide" class="hidden mt-2 p-4 bg-white border border-gray-200 rounded-md shadow-sm">
                            <h4 class="text-sm font-semibold text-gray-800 mb-3">
                                <i class="fas fa-tag text-blue-600 mr-2"></i>Distance-Based Pricing (Round Trip)
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">20-50 km:</span>
                                    <span class="font-semibold text-green-700">₱1,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">51-80 km:</span>
                                    <span class="font-semibold text-green-700">₱2,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">81-110 km:</span>
                                    <span class="font-semibold text-green-700">₱3,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">111-140 km:</span>
                                    <span class="font-semibold text-green-700">₱4,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">141-170 km:</span>
                                    <span class="font-semibold text-green-700">₱5,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">171-200 km:</span>
                                    <span class="font-semibold text-green-700">₱6,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">201-230 km:</span>
                                    <span class="font-semibold text-green-700">₱7,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">231-260 km:</span>
                                    <span class="font-semibold text-green-700">₱8,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">261-290 km:</span>
                                    <span class="font-semibold text-green-700">₱9,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">291-320 km:</span>
                                    <span class="font-semibold text-green-700">₱10,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">321-350 km:</span>
                                    <span class="font-semibold text-green-700">₱11,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">351-380 km:</span>
                                    <span class="font-semibold text-green-700">₱12,000</span>
                                </div>
                                <div class="flex justify-between items-center py-1 px-2 bg-gray-50 rounded">
                                    <span class="text-gray-600">381-410 km:</span>
                                    <span class="font-semibold text-green-700">₱13,000</span>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-3 pt-3 border-t border-gray-200">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>Note:</strong> Pricing is based on round trip distance. Price per vehicle is multiplied by the number of buses selected.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="md:col-span-2">
                    <label for="purpose" class="block text-sm font-medium text-gray-700 mb-1">Purpose *</label>
                    <input type="text" id="purpose" name="purpose" required 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                           value="<?php echo isset($_POST['purpose']) ? htmlspecialchars($_POST['purpose']) : 'Bayanihan'; ?>">
                </div>
                
                <div class="md:col-span-2">
                    <label for="approval_document" class="block text-sm font-medium text-gray-700 mb-1">President Approval Document * <span class="text-red-600">Required</span></label>
                    <div class="mt-1 flex items-center justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-blue-400 transition-colors">
                        <div class="space-y-1 text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <div class="flex text-sm text-gray-600">
                                <label for="approval_document" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                                    <span>Upload approval document</span>
                                    <input id="approval_document" name="approval_document" type="file" class="sr-only" required accept="image/jpeg,image/png,image/gif,image/jpg,application/pdf" onchange="previewApprovalDocument(event)">
                                </label>
                                <p class="pl-1">or drag and drop</p>
                            </div>
                            <p class="text-xs text-gray-500">PNG, JPG, GIF, PDF up to 5MB</p>
                        </div>
                    </div>
                    <div id="approval_preview" class="mt-3 hidden">
                        <div class="flex items-center justify-between p-3 bg-blue-50 border border-blue-200 rounded-md">
                            <div class="flex items-center">
                                <i class="fas fa-file-alt text-blue-600 text-xl mr-3"></i>
                                <div>
                                    <p class="text-sm font-medium text-gray-900" id="approval_filename">filename.pdf</p>
                                    <p class="text-xs text-gray-500" id="approval_filesize">0 KB</p>
                                </div>
                            </div>
                            <button type="button" onclick="removeApprovalDocument()" class="text-red-600 hover:text-red-800">
                                <i class="fas fa-times-circle text-xl"></i>
                            </button>
                        </div>
                        <div id="approval_image_preview" class="mt-2 hidden">
                            <img id="approval_image" class="max-h-48 rounded-md border border-gray-300" src="" alt="Approval document preview">
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        <i class="fas fa-info-circle text-blue-600 mr-1"></i>
                        Upload the official approval document from the president to schedule a bus.
                    </p>
                </div>
                
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
                    <input type="date" id="start_date" name="start_date" required 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                           value="<?php echo isset($_POST['start_date']) ? htmlspecialchars($_POST['start_date']) : ''; ?>"
                           min="<?php 
                                $min_date = new DateTime();
                                $min_date->modify('+3 days');
                                echo $min_date->format('Y-m-d'); 
                           ?>"
                           onchange="updateDateRange()">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        Reservations must be made at least 3 days in advance
                    </p>
                </div>
                
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date *</label>
                    <input type="date" id="end_date" name="end_date" required 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                           value="<?php echo isset($_POST['end_date']) ? htmlspecialchars($_POST['end_date']) : ''; ?>"
                           min="<?php 
                                $min_date = new DateTime();
                                $min_date->modify('+3 days');
                                echo $min_date->format('Y-m-d'); 
                           ?>"
                           onchange="updateDateRange()">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        Must be on or after start date (minimum 3 days in advance)
                    </p>
                </div>
                
                <!-- Vehicle Type is fixed to "Bus" - hidden field -->
                <input type="hidden" id="vehicle" name="vehicle" value="Bus">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Bus(es) *</label>
                    <div id="bus-checkboxes" class="border border-gray-300 rounded-md p-4 max-h-60 overflow-y-auto bg-gray-50">
                        <p class="text-xs text-gray-500 mb-3">
                            <i class="fas fa-info-circle mr-1"></i>
                            Select one or more buses. Price will be calculated based on the number of buses selected.
                        </p>
                        <?php foreach ($available_buses as $bus): ?>
                            <div class="flex items-center mb-2 p-2 hover:bg-gray-100 rounded">
                                <input type="checkbox" 
                                       id="bus_<?php echo htmlspecialchars($bus['bus_number']); ?>" 
                                       name="bus_no[]" 
                                       value="<?php echo htmlspecialchars($bus['bus_number']); ?>"
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                       onchange="updateSelectedBuses()">
                                <label for="bus_<?php echo htmlspecialchars($bus['bus_number']); ?>" 
                                       class="ml-2 text-sm text-gray-700 cursor-pointer flex-1">
                                    <span class="font-semibold">Bus <?php echo htmlspecialchars($bus['bus_number']); ?></span>
                                    <span class="text-gray-500"> - <?php echo htmlspecialchars($bus['vehicle_type']); ?> (<?php echo $bus['capacity']; ?> seats)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2" id="bus-availability-hint">
                        <i class="fas fa-bus mr-1"></i>
                        <span id="selected-buses-count">0</span> bus(es) selected. Availability updates when you pick a date.
                    </p>
                    <input type="hidden" id="bus_no_required" name="bus_no_required" value="">
                </div>
                
                <div>
                    <label for="no_of_days" class="block text-sm font-medium text-gray-700 mb-1">Number of Days *</label>
                    <input type="number" id="no_of_days" name="no_of_days" min="1" max="30" required readonly
                           class="w-full rounded-md border-gray-300 shadow-sm bg-gray-50 focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                           value="<?php echo isset($_POST['no_of_days']) ? htmlspecialchars($_POST['no_of_days']) : '1'; ?>">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-calculator mr-1"></i>
                        Automatically calculated from date range
                    </p>
                </div>
                
                <div class="md:col-span-2">
                    <div id="availability-status" class="hidden p-3 rounded-md text-sm">
                        <!-- Availability status will be shown here -->
                    </div>
                </div>
                
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeAddModal()">
                    Cancel
                </button>
                <button type="button" onclick="showConfirmModal()" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <i class="fas fa-paper-plane mr-2"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-lg mx-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900">
                <i class="fas fa-check-circle text-blue-600 mr-2"></i>Confirm Bus Request
            </h3>
            <button onclick="closeConfirmModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="mb-6">
            <p class="text-gray-600 mb-4">Please review your request details before submitting:</p>
            
            <div class="bg-gray-50 rounded-lg p-4 space-y-3 text-sm">
                <div class="flex justify-between border-b border-gray-200 pb-2">
                    <span class="text-gray-600 font-medium">School:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-school">-</span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2">
                    <span class="text-gray-600 font-medium">Client/Organization:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-client">-</span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2">
                    <span class="text-gray-600 font-medium">From:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-from">-</span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2">
                    <span class="text-gray-600 font-medium">To:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-to">-</span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2">
                    <span class="text-gray-600 font-medium">Purpose:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-purpose">-</span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2">
                    <span class="text-gray-600 font-medium">Bus Number:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-bus">-</span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2">
                    <span class="text-gray-600 font-medium">Date Range:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-date-range">-</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600 font-medium">Number of Days:</span>
                    <span class="text-gray-900 font-semibold text-right" id="confirm-days">-</span>
                </div>
            </div>
            
            <div class="mt-4 p-3 bg-yellow-50 border-l-4 border-yellow-400 rounded">
                <p class="text-sm text-yellow-700">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Important:</strong> Once submitted, your request will be sent to the admin for approval.
                </p>
            </div>
        </div>
        
        <div class="flex justify-end space-x-3">
            <button type="button" onclick="closeConfirmModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                <i class="fas fa-times mr-1"></i> Cancel
            </button>
            <button type="button" onclick="submitBusRequest()" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <i class="fas fa-check mr-1"></i> Yes, Submit Request
            </button>
        </div>
    </div>
</div>

<!-- View Schedule Modal -->
<div id="viewModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-2xl">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-lg font-medium text-gray-900">Schedule Details</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeViewModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="schedule-details" class="space-y-4">
            <!-- Schedule details will be populated here -->
        </div>
        
        <div class="flex justify-end mt-6">
            <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeViewModal()">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Submission Success Modal -->
<div id="submissionSuccessModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-lg mx-4">
        <div class="flex items-center justify-center mb-4">
            <div class="flex-shrink-0 w-16 h-16 rounded-full bg-green-100 flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600 text-3xl"></i>
            </div>
        </div>
        <div class="text-center mb-6">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Bus Reservation Submitted</h3>
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 text-left">
                <p class="text-gray-700 leading-relaxed">
                    Your bus reservation request has been submitted.<br><br>
                    Please wait for the BAO Admin to review your request.<br><br>
                    Once approved, you may proceed to the BAO Office for further instructions and for claiming your billing statement after the trip.
                </p>
            </div>
        </div>
        <div class="flex justify-center">
            <button type="button" onclick="closeSubmissionSuccessModal()" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <i class="fas fa-check mr-2"></i> Understood
            </button>
        </div>
    </div>
</div>

<!-- Cancel Request Confirmation Modal -->
<div id="cancelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-medium text-gray-900">Cancel Bus Request</h3>
            </div>
        </div>
        
        <div class="mb-6">
            <p class="text-sm text-gray-600 mb-4">Are you sure you want to cancel this bus request?</p>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm font-medium text-gray-700">Destination:</p>
                <p class="text-sm text-gray-900" id="cancel-destination"></p>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                <i class="fas fa-info-circle mr-1"></i>
                This action cannot be undone. The request status will be changed to "Cancelled".
            </p>
        </div>
        
        <form method="POST" id="cancelForm">
            <input type="hidden" name="action" value="cancel_request">
            <input type="hidden" name="schedule_id" id="cancel-schedule-id">
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeCancelModal()" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                    No, Keep It
                </button>
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    <i class="fas fa-times-circle mr-2"></i>
                    Yes, Cancel Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Success Modal -->
<div id="successModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md mx-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900">
                <i class="fas fa-check-circle text-green-600 mr-2"></i>Request Submitted Successfully
            </h3>
            <button onclick="closeSuccessModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="mb-6">
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
                <p class="text-sm text-gray-700 leading-relaxed">
                    Your bus reservation request has been submitted.<br><br>
                    Please wait for the BAO Admin to review your request.<br><br>
                    Once approved, you may proceed to the BAO Office for further instructions and for claiming your billing statement.
                </p>
            </div>
        </div>
        
        <div class="flex justify-end">
            <button onclick="closeSuccessModal()" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                OK
            </button>
        </div>
    </div>
</div>

<script>
// Show success modal if request was just submitted
<?php if (isset($_GET['submitted']) && $_GET['submitted'] == 1): ?>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        document.getElementById('successModal').classList.remove('hidden');
    }, 300);
});
<?php endif; ?>

// Mobile menu toggle
document.getElementById('menu-button').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('-translate-x-full');
});

// Modal functions
function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
    // Set initial min date to 3 days from today
    const today = new Date();
    const minDate = new Date(today);
    minDate.setDate(today.getDate() + 3);
    const minDateStr = minDate.toISOString().split('T')[0];
    
    document.getElementById('start_date').min = minDateStr;
    document.getElementById('end_date').min = minDateStr;
    // Distance calculation removed - not shown to students
}

function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
    // Reset approval document upload
    removeApprovalDocument();
}

function viewSchedule(schedule) {
    const details = document.getElementById('schedule-details');
    details.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-500">School & Organization</label>
                <p class="text-sm text-gray-900">${schedule.client}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Destination</label>
                <p class="text-sm text-gray-900">${schedule.destination}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Purpose</label>
                <p class="text-sm text-gray-900">${schedule.purpose}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Date of Travel</label>
                <p class="text-sm text-gray-900">${(() => {
                    if (!schedule.date_covered) return 'N/A';
                    try {
                        const startDate = new Date(schedule.date_covered);
                        if (isNaN(startDate.getTime())) return 'N/A';
                        const days = schedule.no_of_days || 1;
                        if (days > 1) {
                            const endDate = new Date(startDate);
                            endDate.setDate(endDate.getDate() + days - 1);
                            return startDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + 
                                   ' to ' + 
                                   endDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        } else {
                            return startDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        }
                    } catch (e) {
                        return schedule.date_covered || 'N/A';
                    }
                })()}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Vehicle Type</label>
                <p class="text-sm text-gray-900">${schedule.vehicle || 'Bus'}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Vehicle Number</label>
                <p class="text-sm text-gray-900">${schedule.bus_no}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Number of Days</label>
                <p class="text-sm text-gray-900">${schedule.no_of_days || 1}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Schedule ID</label>
                <p class="text-sm text-gray-900">${schedule.id || 'N/A'}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Requested By</label>
                <p class="text-sm text-gray-900">${schedule.user_name || 'N/A'}</p>
                <p class="text-sm text-gray-500">${schedule.user_email || ''}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500">Last Updated</label>
                <p class="text-sm text-gray-900">${(() => {
                    if (!schedule.updated_at) return 'N/A';
                    try {
                        const date = new Date(schedule.updated_at);
                        if (isNaN(date.getTime())) return 'N/A';
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
                        return formattedDate + ', ' + formattedTime;
                    } catch (e) {
                        return schedule.updated_at || 'N/A';
                    }
                })()}</p>
            </div>
            ${schedule.or_number ? `
            <div class="md:col-span-2 bg-green-50 border-l-4 border-green-400 p-4 rounded">
                <label class="block text-sm font-medium text-green-700 mb-1">
                    <i class="fas fa-receipt mr-1"></i>Official Receipt Number
                </label>
                <p class="text-lg font-bold text-green-900">${schedule.or_number}</p>
                <p class="text-xs text-green-600 mt-1">
                    <i class="fas fa-check-circle mr-1"></i>Payment verified and approved
                </p>
            </div>
            ` : ''}
        </div>
    `;
    document.getElementById('viewModal').classList.remove('hidden');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.add('hidden');
}

function openCancelModal(scheduleId, destination) {
    document.getElementById('cancel-schedule-id').value = scheduleId;
    document.getElementById('cancel-destination').textContent = destination;
    document.getElementById('cancelModal').classList.remove('hidden');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
}

function closeSuccessModal() {
    document.getElementById('successModal').classList.add('hidden');
}

function closeSubmissionSuccessModal() {
    document.getElementById('submissionSuccessModal').classList.add('hidden');
}

// CHMSU Talisay as the fixed origin point (LOCAL ONLY - NO APIs)
const CHMSU_ORIGIN_NAME = 'CHMSU - Carlos Hilado Memorial State University, Talisay City, Negros Occidental';

// Local suggestions for Negros Occidental - COMPREHENSIVE COVERAGE (Excluding Talisay - system is located there)
const localSuggestions = [
    // Major Cities
    'Bacolod City, Negros Occidental',
    'Silay City, Negros Occidental',
    'Binalbagan, Negros Occidental',
    'Kabankalan City, Negros Occidental',
    'Himamaylan City, Negros Occidental',
    'Bago City, Negros Occidental',
    'Cadiz City, Negros Occidental',
    'Sagay City, Negros Occidental',
    'Victorias City, Negros Occidental',
    'Escalante City, Negros Occidental',
    'San Carlos City, Negros Occidental',
    'La Carlota City, Negros Occidental',
    'Ilog, Negros Occidental',
    'Isabela, Negros Occidental',
    
    // CHMSU Campuses (excluding Talisay - main campus location)
    'CHMSU Binalbagan Campus',
    'CHMSU Fortune Towne Campus',
    'CHMSU Alijis Campus',
    
    // Talisay City Routes (from CHMSU Talisay)
    'Talisay – Bubog',
    'Talisay – Cabatangan',
    'Talisay – Zone 1 (Poblacion)',
    'Talisay – Zone 2 (Poblacion)',
    'Talisay – Zone 3 (Poblacion)',
    'Talisay – Zone 4 (Poblacion)',
    'Talisay – Zone 4-A (Poblacion)',
    'Talisay – Zone 5 (Poblacion)',
    'Talisay – Zone 6 (Poblacion)',
    'Talisay – Zone 7 (Poblacion)',
    'Talisay – Zone 8 (Poblacion)',
    'Talisay – Zone 9 (Poblacion)',
    'Talisay – Zone 10 (Poblacion)',
    'Talisay – Zone 11 (Poblacion)',
    'Talisay – Zone 12 (Poblacion)',
    'Talisay – Zone 12-A (Poblacion)',
    'Talisay – Zone 14 (Poblacion)',
    'Talisay – Zone 14-A (Poblacion)',
    'Talisay – Zone 14-B (Poblacion)',
    'Talisay – Zone 15 (Poblacion)',
    'Talisay – Zone 16 (Poblacion)',
    'Talisay – Concepcion',
    'Talisay – Dos Hermanas',
    'Talisay – Efigenio Lizares',
    'Talisay – Matab-ang',
    'Talisay – San Fernando',
    'Talisay – Katilingban',
    
    // Bacolod City Barangays (61 total - ALL included) - matches database format
    'Barangay 1, Bacolod',
    'Barangay 2, Bacolod',
    'Barangay 3, Bacolod',
    'Barangay 4, Bacolod',
    'Barangay 5, Bacolod',
    'Barangay 6, Bacolod',
    'Barangay 7, Bacolod',
    'Barangay 8, Bacolod',
    'Barangay 9, Bacolod',
    'Barangay 10, Bacolod',
    'Barangay 11, Bacolod',
    'Barangay 12, Bacolod',
    'Barangay 13, Bacolod',
    'Barangay 14, Bacolod',
    'Barangay 15, Bacolod',
    'Barangay 16, Bacolod',
    'Barangay 17, Bacolod',
    'Barangay 18, Bacolod',
    'Barangay 19, Bacolod',
    'Barangay 20, Bacolod',
    'Barangay 21, Bacolod',
    'Barangay 22, Bacolod',
    'Barangay 23, Bacolod',
    'Barangay 24, Bacolod',
    'Barangay 25, Bacolod',
    'Barangay 26, Bacolod',
    'Barangay 27, Bacolod',
    'Barangay 28, Bacolod',
    'Barangay 29, Bacolod',
    'Barangay 30, Bacolod',
    'Barangay 31, Bacolod',
    'Barangay 32, Bacolod',
    'Barangay 33, Bacolod',
    'Barangay 34, Bacolod',
    'Barangay 35, Bacolod',
    'Barangay 36, Bacolod',
    'Barangay 37, Bacolod',
    'Barangay 38, Bacolod',
    'Barangay 39, Bacolod',
    'Barangay 40, Bacolod',
    'Barangay 41, Bacolod',
    'Barangay Mandalagan, Bacolod',
    'Barangay Villamonte, Bacolod',
    'Barangay Tangub, Bacolod',
    'Barangay Bata, Bacolod',
    'Barangay Singcang-Airport, Bacolod',
    'Barangay Banago, Bacolod',
    'Barangay Alijis, Bacolod',
    'Barangay Taculing, Bacolod',
    'Barangay Granada, Bacolod',
    'Barangay Estefania, Bacolod',
    'Barangay Sum-ag, Bacolod',
    'Barangay Felisa, Bacolod',
    'Barangay Punta Taytay, Bacolod',
    'Barangay Vista Alegre, Bacolod',
    'Barangay Pahanocoy, Bacolod',
    'Barangay Handumanan, Bacolod',
    'Barangay Montevista, Bacolod',
    'Barangay Cabug, Bacolod',
    'Barangay Alangilan, Bacolod',
    
    // Silay City Barangays - matches database format
    'Barangay 1, Silay',
    'Barangay 2, Silay',
    'Barangay 3, Silay',
    'Barangay 4, Silay',
    'Barangay 5, Silay',
    'Barangay 6, Silay',
    'Barangay Balaring, Silay',
    'Barangay Guinhalaran, Silay',
    'Barangay Hawaiian, Silay',
    'Barangay Kapitan Ramon, Silay',
    'Barangay Mambulac, Silay',
    'Barangay E. Lopez, Silay',
    'Barangay Lantad, Silay',
    'Barangay Rizal, Silay',
    
    // Bago City Barangays
    'Barangay Alijis, Bago City',
    'Barangay Atipuluan, Bago City',
    'Barangay Bacong, Bago City',
    'Barangay Balingasag, Bago City',
    'Barangay Binubuhan, Bago City',
    'Barangay Dulao, Bago City',
    'Barangay Lag-asan, Bago City',
    'Barangay Ma-ao, Bago City',
    'Barangay Poblacion, Bago City',
    
    // La Carlota City Barangays (Complete)
    'Barangay Ara-al, La Carlota',
    'Barangay Ayungon, La Carlota',
    'Barangay Balabag, La Carlota',
    'Barangay Batuan, La Carlota',
    'Barangay Consuelo, La Carlota',
    'Barangay Cubay, La Carlota',
    'Barangay Haguimit, La Carlota',
    'Barangay I (Poblacion), La Carlota',
    'Barangay II (Poblacion), La Carlota',
    'Barangay La Granja, La Carlota',
    'Barangay Nagasi, La Carlota',
    'Barangay RSB (Rafael Salas), La Carlota',
    'Barangay San Miguel, La Carlota',
    'Barangay Yubo, La Carlota',
    
    // More Bago City Barangays
    'Barangay Abuanan, Bago City',
    'Barangay Alianza, Bago City',
    'Barangay Busay, Bago City',
    'Barangay Calumangan, Bago City',
    'Barangay Caridad, Bago City',
    'Barangay Mailum, Bago City',
    'Barangay Malingin, Bago City',
    'Barangay Pacol, Bago City',
    'Barangay Sagasa, Bago City',
    'Barangay Taloc, Bago City',
    
    // Kabankalan City Barangays (Complete)
    'Barangay Bantayan, Kabankalan City',
    'Barangay Binicuil, Kabankalan City',
    'Barangay Camansi, Kabankalan City',
    'Barangay Camingawan, Kabankalan City',
    'Barangay Carol-an, Kabankalan City',
    'Barangay Daan Banua, Kabankalan City',
    'Barangay Hilamonan, Kabankalan City',
    'Barangay Inapoy, Kabankalan City',
    'Barangay Locotan, Kabankalan City',
    'Barangay Magatas, Kabankalan City',
    'Barangay Magballo, Kabankalan City',
    'Barangay Oringao, Kabankalan City',
    'Barangay Orong, Kabankalan City',
    'Barangay Pinaguinpinan, Kabankalan City',
    'Barangay Salong, Kabankalan City',
    'Barangay Tabugon, Kabankalan City',
    'Barangay Tagoc, Kabankalan City',
    'Barangay Tagukon, Kabankalan City',
    'Barangay Talubangi, Kabankalan City',
    'Barangay Tampalon, Kabankalan City',
    'Barangay Tan-awan, Kabankalan City',
    'Barangay Tapi, Kabankalan City',
    'Barangay Tiling, Kabankalan City',
    
    // Himamaylan City Barangays (Complete)
    'Barangay 1 (Poblacion I), Himamaylan City',
    'Barangay 2 (Poblacion II), Himamaylan City',
    'Barangay 3 (Poblacion III), Himamaylan City',
    'Barangay 4 (Poblacion IV), Himamaylan City',
    'Barangay 5 (Poblacion V), Himamaylan City',
    'Barangay 6 (Poblacion VI), Himamaylan City',
    'Barangay Aguisan, Himamaylan City',
    'Barangay Buenavista, Himamaylan City',
    'Barangay Cabadiangan, Himamaylan City',
    'Barangay Cabanbanan, Himamaylan City',
    'Barangay Carabalan, Himamaylan City',
    'Barangay Caradio-an, Himamaylan City',
    'Barangay Mambagaton, Himamaylan City',
    'Barangay Nabali-an, Himamaylan City',
    'Barangay San Antonio, Himamaylan City',
    'Barangay San Jose, Himamaylan City',
    'Barangay San Pablo, Himamaylan City',
    'Barangay Sara-et, Himamaylan City',
    'Barangay Suay, Himamaylan City',
    'Barangay Talaban, Himamaylan City',
    'Barangay To-oy, Himamaylan City',
    
    // Sagay City Barangays (Complete)
    'Barangay Andres Bonifacio, Sagay',
    'Barangay Bato, Sagay',
    'Barangay Baviera, Sagay',
    'Barangay Bulanon, Sagay',
    'Barangay Campo Himoga-an, Sagay',
    'Barangay Colonia Divina, Sagay',
    'Barangay Fabrica, Sagay',
    'Barangay General Luna, Sagay',
    'Barangay Himoga-an Baybay, Sagay',
    'Barangay Lopez Jaena, Sagay',
    'Barangay Malubon, Sagay',
    'Barangay Old Sagay, Sagay',
    'Barangay Paraiso, Sagay',
    'Barangay Poblacion I, Sagay',
    'Barangay Poblacion II, Sagay',
    'Barangay Poblacion III, Sagay',
    'Barangay Poblacion IV, Sagay',
    'Barangay Poblacion V, Sagay',
    'Barangay Puey, Sagay',
    'Barangay Rafaela Barrera, Sagay',
    'Barangay Rizal, Sagay',
    'Barangay Sewahon I, Sagay',
    'Barangay Sewahon II, Sagay',
    'Barangay Taba-ao, Sagay',
    'Barangay Tadlong, Sagay',
    'Barangay Vito, Sagay',
    
    // San Carlos City Barangays (Complete)
    'Barangay 3, San Carlos',
    'Barangay 4, San Carlos',
    'Barangay 5, San Carlos',
    'Barangay Bagonbon, San Carlos',
    'Barangay Buluangan, San Carlos',
    'Barangay Codcod, San Carlos',
    'Barangay Ermita, San Carlos',
    'Barangay Guadalupe, San Carlos',
    'Barangay I (Poblacion I), San Carlos',
    'Barangay II (Poblacion II), San Carlos',
    'Barangay Nataban, San Carlos',
    'Barangay Palampas, San Carlos',
    'Barangay Prosperidad, San Carlos',
    'Barangay Punao, San Carlos',
    'Barangay Quezon, San Carlos',
    'Barangay Rizal, San Carlos',
    'Barangay San Antonio, San Carlos',
    'Barangay San Juan, San Carlos',
    'Barangay San Pedro, San Carlos',
    
    // Cadiz City Barangays (Complete)
    'Barangay Andres Bonifacio, Cadiz',
    'Barangay Bandila, Cadiz',
    'Barangay Banquerohan, Cadiz',
    'Barangay 1 (Poblacion), Cadiz',
    'Barangay 2 (Poblacion), Cadiz',
    'Barangay 3 (Poblacion), Cadiz',
    'Barangay 4 (Poblacion), Cadiz',
    'Barangay 5 (Poblacion), Cadiz',
    'Barangay 6 (Poblacion), Cadiz',
    'Barangay 7 (Poblacion), Cadiz',
    'Barangay 8 (Poblacion), Cadiz',
    'Barangay 9 (Poblacion), Cadiz',
    'Barangay 10 (Poblacion), Cadiz',
    'Barangay Cabahug, Cadiz',
    'Barangay Caduhaan, Cadiz',
    'Barangay Celestino Villacin, Cadiz',
    'Barangay Daga, Cadiz',
    'Barangay Luna, Cadiz',
    'Barangay Mabini, Cadiz',
    'Barangay Magsaysay, Cadiz',
    'Barangay Sicaba, Cadiz',
    'Barangay Tiglawigan, Cadiz',
    'Barangay Tinampa-an, Cadiz',
    
    // Victorias City Barangays (Complete)
    'Barangay Bago, Victorias',
    'Barangay Canlandog, Victorias',
    'Barangay Daan Banua, Victorias',
    'Barangay I (Poblacion I), Victorias',
    'Barangay II (Poblacion II), Victorias',
    'Barangay III (Poblacion III), Victorias',
    'Barangay IV (Poblacion IV), Victorias',
    'Barangay V (Poblacion V), Victorias',
    'Barangay VI (Poblacion VI), Victorias',
    'Barangay VII (Poblacion VII), Victorias',
    'Barangay VIII (Poblacion VIII), Victorias',
    'Barangay IX (Poblacion IX), Victorias',
    'Barangay X (Poblacion X), Victorias',
    'Barangay XI (Poblacion XI), Victorias',
    'Barangay XII (Poblacion XII), Victorias',
    'Barangay XIII (Poblacion XIII), Victorias',
    'Barangay XIV (Poblacion XIV), Victorias',
    'Barangay XV (Poblacion XV), Victorias',
    'Barangay XVI (Poblacion XVI), Victorias',
    'Barangay XVII (Poblacion XVII), Victorias',
    'Barangay XVIII (Poblacion XVIII), Victorias',
    'Barangay XIX (Poblacion XIX), Victorias',
    'Barangay XX (Poblacion XX), Victorias',
    'Barangay XXI (Poblacion XXI), Victorias',
    'Barangay XXII (Poblacion XXII), Victorias',
    'Barangay XXIII (Poblacion XXIII), Victorias',
    'Barangay XXIV (Poblacion XXIV), Victorias',
    'Barangay XXV (Poblacion XXV), Victorias',
    'Barangay XXVI (Poblacion XXVI), Victorias',
    'Barangay Malaya, Victorias',
    'Barangay Malingin, Victorias',
    'Barangay San Miguel, Victorias',
    
    // Escalante City Barangays (Complete)
    'Barangay Alimango, Escalante',
    'Barangay Balintawak, Escalante',
    'Barangay Binaguiohan, Escalante',
    'Barangay Dian-ay, Escalante',
    'Barangay Hacienda Fe, Escalante',
    'Barangay Japitan, Escalante',
    'Barangay Jonob-jonob, Escalante',
    'Barangay Libertad, Escalante',
    'Barangay Mabini, Escalante',
    'Barangay Magsaysay, Escalante',
    'Barangay Malasibog, Escalante',
    'Barangay Old Poblacion, Escalante',
    'Barangay Paitan, Escalante',
    'Barangay Pinapugasan, Escalante',
    'Barangay Rizal, Escalante',
    'Barangay Sampinit, Escalante',
    'Barangay Udtongan, Escalante',
    'Barangay Washington, Escalante',
    
    // Sipalay City Barangays
    'Barangay Cabadiangan, Sipalay City',
    'Barangay Camindangan, Sipalay City',
    'Barangay Gil Montilla, Sipalay City',
    'Barangay Maricalum, Sipalay City',
    
    // Schools & Universities
    'University of Negros Occidental - Recoletos',
    'La Salle Bacolod',
    'University of St. La Salle',
    'University of Bacolod',
    'STI College Bacolod',
    'Carlos Hilado Memorial State College',
    
    // Landmarks & Shopping Centers
    'SM City Bacolod',
    'Ayala Capitol Central',
    'Robinsons Place Bacolod',
    'Bacolod City Plaza',
    'Bacolod Public Plaza',
    'The Negros Museum',
    'Capitol Park and Lagoon',
    'CityMall Bacolod',
    
    // Airports
    'Silay Airport',
    'Bacolod Airport',
    'Bacolod-Silay International Airport',
    
    // Other Municipalities
    'Calatrava, Negros Occidental',
    'Cauayan, Negros Occidental',
    'Don Salvador Benedicto, Negros Occidental',
    'Enrique B. Magalona, Negros Occidental',
    'Hinigaran, Negros Occidental',
    'Hinoba-an, Negros Occidental',
    'La Castellana, Negros Occidental',
    'Manapla, Negros Occidental',
    'Moises Padilla, Negros Occidental',
    'Murcia, Negros Occidental',
    'Pontevedra, Negros Occidental',
    'Pulupandan, Negros Occidental',
    'Salvador Benedicto, Negros Occidental',
    'San Enrique, Negros Occidental',
    'Sipalay, Negros Occidental',
    'Toboso, Negros Occidental',
    'Valladolid, Negros Occidental'
];

// NO API FUNCTIONS NEEDED - All calculations done locally on server

// Searchable dropdown functions
let suggestionTimeout = null;

function handleLocationInput(event) {
    const input = event.target.value;
    const suggestionsDiv = document.getElementById('location-suggestions');
    
    // Clear previous timeout
    if (suggestionTimeout) {
        clearTimeout(suggestionTimeout);
    }
    
    if (input.length < 2) {
        suggestionsDiv.classList.add('hidden');
        return;
    }
    
    // Debounce the search
    suggestionTimeout = setTimeout(() => {
        showSuggestions(input);
    }, 300);
}

function showLocationSuggestions() {
    const input = document.getElementById('to_location').value;
    if (input.length >= 2) {
        showSuggestions(input);
    }
}

function hideLocationSuggestions() {
    // Delay hiding to allow clicking on suggestions
    setTimeout(() => {
        const suggestionsDiv = document.getElementById('location-suggestions');
        suggestionsDiv.classList.add('hidden');
    }, 300); // Increased delay to 300ms
}

// Continuation location input handlers
function handleLocationInputContinuation(event) {
    const input = event.target.value;
    const suggestionsDiv = document.getElementById('location-suggestions-continuation');
    
    // Clear previous timeout
    if (suggestionTimeout) {
        clearTimeout(suggestionTimeout);
    }
    
    if (input.length < 2) {
        suggestionsDiv.classList.add('hidden');
        return;
    }
    
    // Debounce the search
    suggestionTimeout = setTimeout(() => {
        showSuggestionsContinuation(input);
    }, 300);
}

function showLocationSuggestionsContinuation() {
    const input = document.getElementById('to_location_continuation').value;
    if (input.length >= 2) {
        showSuggestionsContinuation(input);
    }
}

function hideLocationSuggestionsContinuation() {
    // Delay hiding to allow clicking on suggestions
    setTimeout(() => {
        const suggestionsDiv = document.getElementById('location-suggestions-continuation');
        suggestionsDiv.classList.add('hidden');
    }, 300); // Increased delay to 300ms
}

function showSuggestionsContinuation(query) {
    const suggestionsDiv = document.getElementById('location-suggestions-continuation');
    const normalizedQuery = query.toLowerCase().trim();
    
    // Filter local suggestions only - no API calls
    const matches = localSuggestions.filter(suggestion => 
        suggestion.toLowerCase().includes(normalizedQuery)
    ).slice(0, 10);
    
    if (matches.length === 0) {
        suggestionsDiv.classList.add('hidden');
        return;
    }
    
    // Display suggestions - use onmousedown instead of onclick (fires before blur)
    suggestionsDiv.innerHTML = matches.map(suggestion => `
        <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0" 
             onmousedown="selectSuggestionContinuation('${suggestion.replace(/'/g, "\\'")}'); event.preventDefault();">
            <div class="flex items-center">
                <i class="fas fa-map-marker-alt text-blue-500 mr-2"></i>
                <span class="text-sm">${suggestion}</span>
            </div>
        </div>
    `).join('');
    
    suggestionsDiv.classList.remove('hidden');
}

function selectSuggestionContinuation(suggestion) {
    document.getElementById('to_location_continuation').value = suggestion;
    document.getElementById('location-suggestions-continuation').classList.add('hidden');
    // Distance calculation removed - not shown to students
}

function showSuggestions(query) {
    const suggestionsDiv = document.getElementById('location-suggestions');
    const normalizedQuery = query.toLowerCase().trim();
    
    // Filter local suggestions only - no API calls
    const matches = localSuggestions.filter(suggestion => 
        suggestion.toLowerCase().includes(normalizedQuery)
    ).slice(0, 10);
    
    if (matches.length === 0) {
        suggestionsDiv.classList.add('hidden');
        return;
    }
    
    // Display suggestions - use onmousedown instead of onclick (fires before blur)
    suggestionsDiv.innerHTML = matches.map(suggestion => `
        <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0" 
             onmousedown="selectSuggestion('${suggestion.replace(/'/g, "\\'")}'); event.preventDefault();">
            <div class="flex items-center">
                <i class="fas fa-map-marker-alt text-blue-500 mr-2"></i>
                <span class="text-sm">${suggestion}</span>
            </div>
        </div>
    `).join('');
    
    suggestionsDiv.classList.remove('hidden');
}

function selectSuggestion(suggestion) {
    document.getElementById('to_location').value = suggestion;
    document.getElementById('location-suggestions').classList.add('hidden');
    // Distance calculation removed - not shown to students
}

// Keyboard navigation for dropdown
document.addEventListener('keydown', function(event) {
    const suggestionsDiv = document.getElementById('location-suggestions');
    const input = document.getElementById('to_location');
    
    if (document.activeElement !== input || suggestionsDiv.classList.contains('hidden')) {
        return;
    }
    
    const suggestions = suggestionsDiv.querySelectorAll('div[onclick]');
    const currentActive = suggestionsDiv.querySelector('.bg-blue-100');
    let activeIndex = -1;
    
    if (currentActive) {
        activeIndex = Array.from(suggestions).indexOf(currentActive);
    }
    
    switch(event.key) {
        case 'ArrowDown':
            event.preventDefault();
            activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            break;
        case 'Enter':
            event.preventDefault();
            if (currentActive) {
                const suggestion = currentActive.textContent.trim();
                selectSuggestion(suggestion);
            }
            return;
        case 'Escape':
            suggestionsDiv.classList.add('hidden');
            return;
        default:
            return;
    }
    
    // Update active suggestion
    suggestions.forEach((suggestion, index) => {
        if (index === activeIndex) {
            suggestion.classList.add('bg-blue-100');
        } else {
            suggestion.classList.remove('bg-blue-100');
        }
    });
});

// NO GEOCODING OR RESOLVING FUNCTIONS NEEDED - All done locally in PHP

// Function to calculate price based on fixed pricing structure
function calculatePriceFromDistance(distanceKm) {
    // Fixed pricing structure based on round trip distance (km)
    if (distanceKm >= 20 && distanceKm <= 50) {
        return 1000;
    } else if (distanceKm >= 51 && distanceKm <= 80) {
        return 2000;
    } else if (distanceKm >= 81 && distanceKm <= 110) {
        return 3000;
    } else if (distanceKm >= 111 && distanceKm <= 140) {
        return 4000;
    } else if (distanceKm >= 141 && distanceKm <= 170) {
        return 5000;
    } else if (distanceKm >= 171 && distanceKm <= 200) {
        return 6000;
    } else if (distanceKm >= 201 && distanceKm <= 230) {
        return 7000;
    } else if (distanceKm >= 231 && distanceKm <= 260) {
        return 8000;
    } else if (distanceKm >= 261 && distanceKm <= 290) {
        return 9000;
    } else if (distanceKm >= 291 && distanceKm <= 320) {
        return 10000;
    } else if (distanceKm >= 321 && distanceKm <= 350) {
        return 11000;
    } else if (distanceKm >= 351 && distanceKm <= 380) {
        return 12000;
    } else if (distanceKm >= 381 && distanceKm <= 410) {
        return 13000;
    } else {
        // For distances outside the range, use the closest tier
        if (distanceKm < 20) {
            return 1000; // Minimum price
        } else {
            return 13000; // Maximum price for distances > 410 km
        }
    }
}

// Calculate and display distance using LOCAL DATABASE (NO APIs)
async function updateDistance() {
    // DISABLED: Calculation display removed - students only see reservation form
    // Backend calculation still happens when form is submitted for billing purposes
    const distanceDisplay = document.getElementById('distance-display');
    if (distanceDisplay) {
        distanceDisplay.classList.add('hidden');
    }
    return;
    
    /* DISABLED CODE - Calculation removed from student view
    const fromLocation = document.getElementById('from_location').value.trim();
    const toLocation = document.getElementById('to_location').value.trim();
    const toLocationContinuation = document.getElementById('to_location_continuation').value.trim();
    const distanceDisplay = document.getElementById('distance-display');
    
    if (!fromLocation || !toLocation) {
        distanceDisplay.classList.add('hidden');
        return;
    }
    
    if (fromLocation.toLowerCase() === toLocation.toLowerCase()) {
        distanceDisplay.classList.remove('hidden');
        distanceDisplay.className = 'p-3 rounded-md text-sm bg-red-100 text-red-800';
        distanceDisplay.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i>Please enter different locations for departure and destination.';
        return;
    }
    
    // Show loading state
    distanceDisplay.classList.remove('hidden');
    distanceDisplay.className = 'p-3 rounded-md text-sm bg-gray-100 text-gray-700';
    distanceDisplay.innerHTML = `
        <div class="flex items-center">
            <div class="inline-block animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600 mr-2"></div>
            <span>Calculating distance from CHMSU using local database...</span>
        </div>
    `;
    
    try {
        let totalDistanceOneWay = 0;
        let routeDetails = [];
        
        // Calculate distance from CHMSU to first location
        const formData1 = new FormData();
        formData1.append('from_location', fromLocation);
        formData1.append('to_location', toLocation);
        
        const response1 = await fetch('calculate_distance.php', {
            method: 'POST',
            body: formData1
        });
        
        const result1 = await response1.json();
        
        if (result1.error) {
            distanceDisplay.className = 'p-3 rounded-md text-sm bg-yellow-100 text-yellow-800';
            distanceDisplay.innerHTML = `
                <div>
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <span class="font-semibold">Location not found</span>
                </div>
                <div class="mt-2 text-xs">
                    "${toLocation}" could not be found in the database. Try using: Bacolod City, Talisay City, or specific barangays.
                </div>
                <div class="mt-2 text-xs text-gray-600">
                    Using estimated distance: <span class="font-bold">50 km</span> (one-way), <span class="font-bold">100 km</span> (round trip)
                </div>
            `;
            return;
        }
        
        totalDistanceOneWay += result1.distance_km;
        routeDetails.push({
            from: result1.from_location || CHMSU_ORIGIN_NAME,
            to: result1.destination,
            distance: result1.distance_km
        });
        
        // If continuation exists, calculate distance from first location to continuation
        if (toLocationContinuation) {
            const formData2 = new FormData();
            formData2.append('from_location', toLocation);
            formData2.append('to_location', toLocationContinuation);
            
            const response2 = await fetch('calculate_distance.php', {
                method: 'POST',
                body: formData2
            });
            
            const result2 = await response2.json();
            
            if (!result2.error) {
                totalDistanceOneWay += result2.distance_km;
                routeDetails.push({
                    from: result2.from_location || toLocation,
                    to: result2.destination,
                    distance: result2.distance_km
                });
            }
        }
        
        const totalDistanceRoundTrip = totalDistanceOneWay * 2;
        
        // Calculate price based on round trip distance
        const pricePerVehicle = calculatePriceFromDistance(totalDistanceRoundTrip);
        
        // Get number of selected buses from checkboxes
        const selectedBuses = document.querySelectorAll('input[name="bus_no[]"]:checked');
        const noOfVehicles = selectedBuses.length;
        const totalPrice = noOfVehicles > 0 ? pricePerVehicle * noOfVehicles : 0;
        
        // Display result
        distanceDisplay.className = 'p-3 rounded-md text-sm bg-green-100 text-green-800 border border-green-300';
        
        let routeHtml = '';
        routeDetails.forEach((route, index) => {
            routeHtml += `
                <div class="${index > 0 ? 'mt-2' : ''}">
                    <i class="fas fa-map-marker-alt mr-1 text-green-600"></i>
                    <span class="font-semibold">${index === 0 ? 'To' : 'Then to'}:</span> ${route.to} <span class="text-gray-600">(${route.distance} km)</span>
                </div>
            `;
        });
        
        distanceDisplay.innerHTML = `
            <div class="flex items-center justify-between">
                <span><i class="fas fa-route mr-2"></i>Distance (one-way):</span>
                <span class="font-bold text-lg">${totalDistanceOneWay.toFixed(1)} km</span>
            </div>
            <div class="flex items-center justify-between mt-1">
                <span><i class="fas fa-exchange-alt mr-2"></i>Total Distance (round trip):</span>
                <span class="font-bold text-lg">${totalDistanceRoundTrip.toFixed(1)} km</span>
            </div>
            <div class="flex items-center justify-between mt-2 pt-2 border-t border-green-200">
                <span><i class="fas fa-money-bill-wave mr-2"></i>Price per vehicle:</span>
                <span class="font-bold text-lg text-green-700">₱${pricePerVehicle.toLocaleString()}</span>
            </div>
            ${noOfVehicles > 0 ? `
            <div class="flex items-center justify-between mt-1">
                <span><i class="fas fa-calculator mr-2"></i>Total Price (${noOfVehicles} vehicle${noOfVehicles !== 1 ? 's' : ''}):</span>
                <span class="font-bold text-xl text-green-800">₱${totalPrice.toLocaleString()}</span>
            </div>
            ` : `
            <div class="flex items-center justify-between mt-1 pt-1 border-t border-yellow-200">
                <span><i class="fas fa-exclamation-circle mr-2 text-yellow-600"></i>Please select at least one bus</span>
                <span class="font-bold text-lg text-yellow-600">₱0</span>
            </div>
            `}
            <div class="mt-3 pt-2 border-t border-green-200 text-xs text-gray-700">
                <div class="mb-2">
                    <i class="fas fa-university mr-1 text-blue-600"></i>
                    <span class="font-semibold">From:</span> ${routeDetails[0].from}
                </div>
                ${routeHtml}
            </div>
            <div class="mt-2 text-xs text-gray-600 flex items-center">
                <i class="fas fa-database mr-1"></i>
                Calculated using local Negros Occidental database
            </div>
        `;
        
    } catch (error) {
        console.error('Distance calculation error:', error);
        distanceDisplay.className = 'p-3 rounded-md text-sm bg-red-100 text-red-800';
        distanceDisplay.innerHTML = `
            <div>
                <i class="fas fa-exclamation-circle mr-2"></i>
                <span class="font-semibold">Error calculating distance</span>
            </div>
            <div class="mt-2 text-xs">
                Using estimated distance: <span class="font-bold">50 km</span> (one-way), <span class="font-bold">100 km</span> (round trip)
            </div>
        `;
    }
    */
}

// Update date range and calculate number of days
function updateDateRange() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const noOfDaysInput = document.getElementById('no_of_days');
    const endDateInput = document.getElementById('end_date');
    const startDateInput = document.getElementById('start_date');
    
    // Set minimum date to 3 days from today
    const today = new Date();
    const minDate = new Date(today);
    minDate.setDate(today.getDate() + 3);
    const minDateStr = minDate.toISOString().split('T')[0];
    
    // Ensure start date is at least 3 days from today
    if (startDate && startDate < minDateStr) {
        startDateInput.setCustomValidity('Reservations must be made at least 3 days in advance');
        startDateInput.value = minDateStr;
        return;
    } else {
        startDateInput.setCustomValidity('');
    }
    
    // Set minimum end date to start date (or 3 days from today, whichever is later)
    if (startDate) {
        endDateInput.min = startDate;
    } else {
        endDateInput.min = minDateStr;
    }
    
    // Validate end date is not before start date
    if (startDate && endDate) {
        if (endDate < startDate) {
            endDateInput.setCustomValidity('End date must be on or after start date');
            noOfDaysInput.value = '1';
            return;
        } else {
            endDateInput.setCustomValidity('');
        }
        
        // Calculate number of days
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // +1 to include both start and end dates
        
        noOfDaysInput.value = diffDays;
        
        // Check availability for the date range
        checkAvailability();
    } else {
        noOfDaysInput.value = '1';
    }
}

// Check bus availability
function checkAvailability() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const statusDiv = document.getElementById('availability-status');
    const hint = document.getElementById('bus-availability-hint');
    
    if (!startDate || !endDate) {
        statusDiv.classList.add('hidden');
        return;
    }
    
    // Make AJAX request to check availability for date range
    fetch('check_bus_availability.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `start_date=${startDate}&end_date=${endDate}`
    })
    .then(response => response.json())
    .then(data => {
        statusDiv.classList.remove('hidden');
        if (data.can_book) {
            statusDiv.className = 'p-3 rounded-md text-sm bg-green-100 text-green-800';
            statusDiv.innerHTML = `<i class="fas fa-check-circle mr-2"></i>Buses available for the selected date range.`;
        } else {
            statusDiv.className = 'p-3 rounded-md text-sm bg-red-100 text-red-800';
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle mr-2"></i>Limited availability for the selected date range.`;
        }

        // Update bus checkbox availability
        const availabilityByNumber = {};
        const busCheckboxes = document.querySelectorAll('input[name="bus_no[]"]');
        
        // Initialize all buses as available
        busCheckboxes.forEach(cb => {
            if (cb.value) {
                availabilityByNumber[cb.value] = true;
            }
        });
        
        // Mark unavailable buses from API response
        if (data.buses) {
            data.buses.forEach(bus => {
                if (availabilityByNumber.hasOwnProperty(bus.bus_number)) {
                    availabilityByNumber[bus.bus_number] = bus.available;
                }
            });
        }

        // Enable/disable checkboxes and update labels
        busCheckboxes.forEach(cb => {
            const isAvail = availabilityByNumber[cb.value] !== false;
            cb.disabled = !isAvail;
            
            // Update label text
            const label = document.querySelector(`label[for="${cb.id}"]`);
            if (label) {
                const originalText = label.getAttribute('data-original-text') || label.textContent.trim();
                if (!label.hasAttribute('data-original-text')) {
                    label.setAttribute('data-original-text', originalText);
            }
            
                if (!isAvail) {
                    label.innerHTML = originalText + ' <span class="text-red-600">(Not available)</span>';
                } else {
                    label.innerHTML = originalText;
                }
            }
        });

        // Uncheck unavailable buses
        busCheckboxes.forEach(cb => {
            if (cb.disabled && cb.checked) {
                cb.checked = false;
                updateSelectedBuses();
        }
        });

        // Update hint
        const availableList = Object.keys(availabilityByNumber).filter(k => availabilityByNumber[k]);
        if (hint) {
        hint.innerHTML = availableList.length
                ? `<i class="fas fa-bus mr-1"></i><span id="selected-buses-count">0</span> bus(es) selected. Available: Bus ${availableList.join(', Bus ')}`
                : `<i class="fas fa-bus mr-1"></i>No buses available for selected date range`;
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Check bus availability when bus number changes
function checkBusAvailability() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const hint = document.getElementById('bus-availability-hint');
    
    if (!startDate || !endDate) {
        if (hint) {
        hint.innerHTML = '<i class="fas fa-bus mr-1"></i>Please select date range first';
        }
        return;
    }
    
    // Trigger full availability check
    checkAvailability();
}

// Print receipt
function printReceipt(scheduleId) {
    window.open(`print_bus_receipt.php?id=${scheduleId}`, '_blank');
}

// Toggle pricing guide
function togglePricingGuide() {
    const guide = document.getElementById('pricingGuide');
    const icon = document.getElementById('pricingGuideIcon');
    
    if (guide && icon) {
        guide.classList.toggle('hidden');
        icon.classList.toggle('fa-chevron-down');
        icon.classList.toggle('fa-chevron-up');
    }
}

// Update selected buses count
function updateSelectedBuses() {
    const checkboxes = document.querySelectorAll('input[name="bus_no[]"]:checked');
    const count = checkboxes.length;
    const countElement = document.getElementById('selected-buses-count');
    if (countElement) {
        countElement.textContent = count;
    }
    
    // Update hidden field for validation
    const hiddenField = document.getElementById('bus_no_required');
    if (hiddenField) {
        hiddenField.value = count > 0 ? '1' : '';
    }
    
    return count;
}

// Confirmation Modal Functions
function showConfirmModal() {
    // Get form values
    const form = document.getElementById('busRequestForm');
    const formData = new FormData(form);
    
    // Validate form first
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Check if at least one bus is selected
    const selectedBuses = document.querySelectorAll('input[name="bus_no[]"]:checked');
    if (selectedBuses.length === 0) {
        alert('Please select at least one bus.');
        return;
    }
    
    // Get selected bus numbers
    const busNumbers = Array.from(selectedBuses).map(cb => {
        const label = document.querySelector(`label[for="${cb.id}"]`);
        return label ? label.textContent.trim().split(' - ')[0] : `Bus ${cb.value}`;
    });
    const busText = busNumbers.join(', ');
    
    // Format date range
    const startDate = formData.get('start_date');
    const endDate = formData.get('end_date');
    let dateRangeText = '-';
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        if (startDate === endDate) {
            dateRangeText = start.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        } else {
            dateRangeText = start.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + 
                          ' to ' + 
                          end.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        }
    }
    
    // Populate confirmation modal with form values
    document.getElementById('confirm-school').textContent = formData.get('school_name') || '-';
    document.getElementById('confirm-client').textContent = formData.get('client') || '-';
    document.getElementById('confirm-from').textContent = formData.get('from_location') || '-';
    document.getElementById('confirm-to').textContent = formData.get('to_location') || '-';
    document.getElementById('confirm-purpose').textContent = formData.get('purpose') || '-';
    document.getElementById('confirm-date-range').textContent = dateRangeText;
    document.getElementById('confirm-bus').textContent = busText || '-';
    document.getElementById('confirm-days').textContent = formData.get('no_of_days') || '-';
    
    // Show confirmation modal
    document.getElementById('confirmModal').classList.remove('hidden');
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.add('hidden');
}

function submitBusRequest() {
    // Close confirmation modal
    closeConfirmModal();
    
    // Submit the form
    document.getElementById('busRequestForm').submit();
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('fixed')) {
        closeAddModal();
        closeViewModal();
        closeCancelModal();
        closeConfirmModal();
        closeSuccessModal();
    }
    
    // Close location suggestions when clicking outside
    const suggestionsDiv = document.getElementById('location-suggestions');
    const toLocationInput = document.getElementById('to_location');
    
    if (!suggestionsDiv.contains(event.target) && event.target !== toLocationInput) {
        suggestionsDiv.classList.add('hidden');
    }
});

// Approval document preview functions
function previewApprovalDocument(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const preview = document.getElementById('approval_preview');
    const filename = document.getElementById('approval_filename');
    const filesize = document.getElementById('approval_filesize');
    const imagePreview = document.getElementById('approval_image_preview');
    const imageElement = document.getElementById('approval_image');
    
    // Display file info
    filename.textContent = file.name;
    filesize.textContent = formatFileSize(file.size);
    preview.classList.remove('hidden');
    
    // Preview image if it's an image file
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            imageElement.src = e.target.result;
            imagePreview.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    } else {
        imagePreview.classList.add('hidden');
    }
}

function removeApprovalDocument() {
    const fileInput = document.getElementById('approval_document');
    const preview = document.getElementById('approval_preview');
    const imagePreview = document.getElementById('approval_image_preview');
    
    fileInput.value = '';
    preview.classList.add('hidden');
    imagePreview.classList.add('hidden');
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

// Initialize selected buses count on page load
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedBuses();
    
    // Add custom validation for bus selection
    const form = document.getElementById('busRequestForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const selectedBuses = document.querySelectorAll('input[name="bus_no[]"]:checked');
            if (selectedBuses.length === 0) {
                e.preventDefault();
                alert('Please select at least one bus before submitting.');
                return false;
            }
        });
    }
    
    // Client/Organization field validation - letters only, capitalize first letter
    const clientField = document.getElementById('client');
    if (clientField) {
        clientField.addEventListener('input', function(e) {
            // Remove any non-letter characters (except spaces)
            let value = e.target.value.replace(/[^a-zA-Z\s]/g, '');
            
            // Capitalize first letter of each word
            value = value.toLowerCase().replace(/\b\w/g, function(char) {
                return char.toUpperCase();
            });
            
            e.target.value = value;
        });
        
        // Handle paste for client field
        clientField.addEventListener('paste', function(e) {
            e.preventDefault();
            let pastedText = (e.clipboardData || window.clipboardData).getData('text');
            // Remove non-letter characters and capitalize
            pastedText = pastedText.replace(/[^a-zA-Z\s]/g, '');
            pastedText = pastedText.toLowerCase().replace(/\b\w/g, function(char) {
                return char.toUpperCase();
            });
            let start = e.target.selectionStart;
            let end = e.target.selectionEnd;
            let text = e.target.value;
            e.target.value = text.substring(0, start) + pastedText + text.substring(end);
            e.target.selectionStart = e.target.selectionEnd = start + pastedText.length;
        });
    }
});
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>