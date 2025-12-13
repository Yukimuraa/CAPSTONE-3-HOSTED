<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is external
require_external();

$page_title = "Gymnasium Booking - CHMSU BAO";
$base_url = "..";

// Get user ID
$user_id = $_SESSION['user_id'];

// Create uploads directory for letters if it doesn't exist
$letter_upload_dir = "../uploads/gym_letters/";
if (!file_exists($letter_upload_dir)) {
    mkdir($letter_upload_dir, 0777, true);
}

// Get user profile picture
$user_stmt = $conn->prepare("SELECT profile_pic FROM user_accounts WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$profile_pic = $user_data['profile_pic'] ?? '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Submit new booking
        if ($_POST['action'] === 'book') {
            $date = sanitize_input($_POST['date']);
            $start_time = sanitize_input($_POST['start_time']);
            $end_time = sanitize_input($_POST['end_time']);
            $purpose = sanitize_input($_POST['purpose']);
            $attendees = intval($_POST['attendees']);
            $organization = sanitize_input($_POST['organization']);
            $contact_person = sanitize_input($_POST['contact_person']);
            $contact_number = sanitize_input($_POST['contact_number']);
            $equipment = isset($_POST['equipment']) ? sanitize_input($_POST['equipment']) : '';
            $chair_pairs = isset($_POST['chair_pairs']) ? intval($_POST['chair_pairs']) : 0;
            // Limit chairs to maximum 999
            if ($chair_pairs > 999) {
                $chair_pairs = 999;
                $error_message = "Number of chairs has been limited to 999 (maximum allowed).";
            }
            $other_event_type = isset($_POST['other_event_type']) && !empty($_POST['other_event_type']) ? sanitize_input($_POST['other_event_type']) : null;
            
            // Handle letter upload
            $letter_path = null;
            if (isset($_FILES['letter']) && $_FILES['letter']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                $file_type = $_FILES['letter']['type'];
                $file_size = $_FILES['letter']['size'];
                
                if (!in_array($file_type, $allowed_types)) {
                    $error_message = "Only JPG, PNG, GIF images and PDF files are allowed for the letter.";
                } elseif ($file_size > $max_size) {
                    $error_message = "Letter file size should not exceed 5MB.";
                } else {
                    // Generate unique filename
                    $file_extension = pathinfo($_FILES['letter']['name'], PATHINFO_EXTENSION);
                    $filename = 'letter_' . $user_id . '_' . time() . '.' . $file_extension;
                    $target_file = $letter_upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['letter']['tmp_name'], $target_file)) {
                        $letter_path = 'uploads/gym_letters/' . $filename;
                    } else {
                        $error_message = "Failed to upload letter. Please try again.";
                    }
                }
            } elseif (isset($_FILES['letter']) && $_FILES['letter']['error'] !== UPLOAD_ERR_NO_FILE) {
                $error_message = "Error uploading letter. Please try again.";
            }
            
            // Validate that letter is uploaded
            if (empty($error_message) && empty($letter_path)) {
                $error_message = "Please upload a letter from the president before making a reservation.";
            }
            
            // Validate input formats (only if no previous errors)
            if (empty($error_message)) {
                if (!preg_match('/^[A-Za-z\s]+$/', $organization)) {
                    $error_message = "Organization name must contain only letters and spaces.";
                } elseif (!preg_match('/^[A-Za-z\s]+$/', $contact_person)) {
                    $error_message = "Contact person name must contain only letters and spaces.";
                } elseif (!preg_match('/^[0-9]{11}$/', $contact_number)) {
                    $error_message = "Contact number must be exactly 11 digits.";
                }
            }
            
            // Continue with other validations only if no errors
            if (empty($error_message)) {
                // Normalize times to HH:MM:SS format for proper comparison
                $start_time = trim($start_time);
                $end_time = trim($end_time);
                $start_normalized = (strlen($start_time) === 5) ? $start_time . ':00' : $start_time;
                $end_normalized = (strlen($end_time) === 5) ? $end_time . ':00' : $end_time;
                
                // Validate booking date (must be at least 3 days in advance)
                $booking_date = new DateTime($date);
                $today = new DateTime();
                $today->setTime(0, 0, 0); // Set time to beginning of day
                $min_booking_date = clone $today;
                $min_booking_date->modify('+3 days'); // Add 3 days to today
                
                if ($booking_date < $min_booking_date) {
                    $error_message = "Reservations must be made at least 3 days in advance. The earliest available date is " . $min_booking_date->format('F j, Y') . ".";
                } 
			// Validate time (end time must be after start time)
			elseif ($start_time >= $end_time) {
                $error_message = "End time must be after start time.";
			}
			// Check if booking overlaps with lunch break (12:00 PM - 1:00 PM)
			// Allow full-day bookings (08:00-18:00) to bypass this check
			// Block bookings that start before 13:00 AND end after 12:00
			elseif (!(($start_normalized === '08:00:00') && ($end_normalized === '18:00:00'))) {
				// Convert times to comparable integers (remove colons)
				$start_int = (int)str_replace(':', '', $start_normalized);
				$end_int = (int)str_replace(':', '', $end_normalized);
				$lunch_start_int = 120000; // 12:00:00
				$lunch_end_int = 130000;   // 13:00:00
				
				// Check if booking overlaps with lunch: starts before 13:00 AND ends after 12:00
				if ($start_int < $lunch_end_int && $end_int > $lunch_start_int) {
					$error_message = "The gymnasium is unavailable from 12:00 PM to 1:00 PM (Lunch Break).";
				}
			}
            } // Close the else block for input format validation
            
            // If no validation errors, proceed to check overlaps and insert booking
            if (empty($error_message)) {
				// Use half-open interval overlap: existing.start < new.end AND existing.end > new.start
				// This allows adjacent bookings that touch at boundaries (e.g., 09:30 following 08:30–09:30)
				$check_query = "SELECT 1 FROM bookings 
							WHERE facility_type = 'gym' 
							  AND date = ? 
							  AND status IN ('pending','confirmed')
							  AND (start_time < ? AND end_time > ?)";
				$check_stmt = $conn->prepare($check_query);
				$check_stmt->bind_param("sss", $date, $end_time, $start_time);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "The gymnasium is already booked for this time slot.";
                } else {
					// Calculate hours first
					$start_datetime = new DateTime($date . ' ' . $start_time);
					$end_datetime = new DateTime($date . ' ' . $end_time);
					$duration = $start_datetime->diff($end_datetime);
					$hours = $duration->h + ($duration->i / 60); // Convert minutes to decimal
					
					// Get pricing settings from database
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
					
					// Base gymnasium cost from database
					$gym_cost = $hours * $pricing_settings['gymnasium_per_hour'];
					
					// Equipment costs
					$sound_system_cost = 0;
					$electricity_cost = 0;
					$chairs_cost = 0;
					
					if (strpos($equipment, 'Sound System') !== false) {
						$sound_system_cost = $hours * $pricing_settings['sound_system_per_hour'];
					}
					if (strpos($equipment, 'Electricity') !== false) {
						$electricity_cost = $hours * $pricing_settings['electricity_per_hour'];
					}
					// Chairs are always available - calculate cost based on quantity using database pricing
					$chair_count = max(0, $chair_pairs);
					$chair_free_limit = $pricing_settings['chair_free_limit'];
					$chair_cost_per_unit = $pricing_settings['chair_cost_per_unit'];
					if ($chair_count > $chair_free_limit) {
						$chairs_cost = ($chair_count - $chair_free_limit) * $chair_cost_per_unit;
					} else {
						$chairs_cost = 0; // Within free limit
					}
					$number_of_pairs = 0; // Not used anymore, but keep for compatibility
					
					$total_cost = $gym_cost + $sound_system_cost + $electricity_cost + $chairs_cost;
					
					// Generate a collision-resistant booking ID and retry on duplicates
					$year = date('Y');
					$additional_info_array = [
						'organization' => $organization,
						'contact_person' => $contact_person,
						'contact_number' => $contact_number,
						'equipment' => $equipment,
						'cost_breakdown' => [
							'gymnasium' => $gym_cost,
							'sound_system' => $sound_system_cost,
							'electricity' => $electricity_cost,
							'chairs' => $chairs_cost,
							'total' => $total_cost,
							'hours' => round($hours, 2)
						]
					];
					// Add other_event_type if "Other" event type was selected
					if ($other_event_type !== null) {
						$additional_info_array['other_event_type'] = $other_event_type;
					}
					// Add letter path if uploaded
					if ($letter_path !== null) {
						$additional_info_array['letter_path'] = $letter_path;
					}
					$additional_info = json_encode($additional_info_array);
					
					$attempts = 0;
					$maxAttempts = 3;
					$success = false;
					
					while ($attempts < $maxAttempts && !$success) {
						$attempts++;
						// Find current max sequence for this year (positions: 'GYM-'(1-4) + YYYY(5-8) + '-'(9) + NNN(10-...))
						$seq_query = "SELECT MAX(CAST(SUBSTRING(booking_id, 10) AS UNSIGNED)) AS max_seq 
										FROM bookings 
										WHERE facility_type = 'gym' AND SUBSTRING(booking_id, 5, 4) = ?";
						$seq_stmt = $conn->prepare($seq_query);
						$seq_stmt->bind_param("s", $year);
						$seq_stmt->execute();
						$seq_res = $seq_stmt->get_result();
						$seq_row = $seq_res->fetch_assoc();
						$next_seq = intval($seq_row['max_seq'] ?? 0) + 1;
						$booking_id = "GYM-" . $year . "-" . str_pad((string)$next_seq, 3, '0', STR_PAD_LEFT);

						$stmt = $conn->prepare("INSERT INTO bookings (booking_id, user_id, facility_type, date, start_time, end_time, purpose, attendees, status, additional_info) VALUES (?, ?, 'gym', ?, ?, ?, ?, ?, 'pending', ?)");
						$stmt->bind_param("sissssis", $booking_id, $user_id, $date, $start_time, $end_time, $purpose, $attendees, $additional_info);
						
						try {
							if ($stmt->execute()) {
								$success = true;
								
								// Send notification to user
								require_once '../includes/notification_functions.php';
								$date_formatted = date('F j, Y', strtotime($date));
								create_notification($user_id, "Gym Reservation Submitted", "Your gym reservation (ID: {$booking_id}) for {$date_formatted} has been submitted and is pending approval.", "booking", "external/gym.php");
								
								// Send notification to all admins
								$user_name = $_SESSION['user_sessions']['external']['user_name'] ?? 'User';
								create_notification_for_admins("New Gym Reservation Request", "{$user_name} has submitted a new gym reservation (ID: {$booking_id}) for {$date_formatted}. Please review and approve.", "booking", "admin/gym_bookings.php");
								
								// Store booking details for receipt display
								$_SESSION['booking_receipt'] = [
									'booking_id' => $booking_id,
									'date' => $date,
									'start_time' => $start_time,
									'end_time' => $end_time,
									'purpose' => $purpose,
									'attendees' => $attendees,
									'organization' => $organization,
									'contact_person' => $contact_person,
									'contact_number' => $contact_number,
									'equipment' => $equipment,
									'hours' => round($hours, 2),
									'chairs_pairs' => isset($number_of_pairs) ? $number_of_pairs : 0,
									'chairs_count' => $chair_pairs,
									'cost_breakdown' => [
										'gymnasium' => $gym_cost,
										'sound_system' => $sound_system_cost,
										'electricity' => $electricity_cost,
										'led_wall' => $led_wall_cost,
										'chairs' => $chairs_cost,
										'total' => $total_cost
									]
								];
								$success_message = "Your gymnasium booking request has been submitted successfully. Booking ID: " . $booking_id;
								// Redirect to show receipt
								header("Location: gym.php?show_receipt=1");
								exit();
							}
						} catch (mysqli_sql_exception $e) {
							// Duplicate key, retry by recomputing next sequence
							if ($e->getCode() !== 1062) {
								$error_message = "Error submitting booking: " . $e->getMessage();
								break;
							}
							// else: loop to try again
						}
					}

					if (!$success && !isset($error_message)) {
						$error_message = "Error submitting booking: Could not generate a unique booking ID.";
					}
                }
            }
        }
        
        // Cancel booking
        elseif ($_POST['action'] === 'cancel' && isset($_POST['booking_id'])) {
            $booking_id = sanitize_input($_POST['booking_id']);
            
            // Check if booking exists and belongs to user
            $check_query = "SELECT * FROM bookings WHERE booking_id = ? AND user_id = ? AND status = 'pending'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $booking_id, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                $error_message = "Invalid booking or booking cannot be cancelled.";
            } else {
                // Update booking status
                $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ?");
                $stmt->bind_param("s", $booking_id);
                
                if ($stmt->execute()) {
                    $success_message = "Booking cancelled successfully.";
                } else {
                    $error_message = "Error cancelling booking: " . $conn->error;
                }
            }
        }
    }
}

// Get user's bookings with pagination - order by most recent first
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get total count
$count_query = "SELECT COUNT(*) as total FROM bookings WHERE user_id = ? AND facility_type = 'gym'";
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = $total_rows > 0 ? ceil($total_rows / $per_page) : 1;

// Get paginated bookings (explicitly select status to ensure it's included)
$bookings_query = "SELECT booking_id, user_id, facility_type, date, start_time, end_time, purpose, attendees, status, additional_info, created_at FROM bookings WHERE user_id = ? AND facility_type = 'gym' ORDER BY created_at DESC, date DESC LIMIT ?, ?";
$bookings_stmt = $conn->prepare($bookings_query);
$bookings_stmt->bind_param("iii", $user_id, $offset, $per_page);
$bookings_stmt->execute();
$bookings_result = $bookings_stmt->get_result();

// Get event types from database
$event_types_query = "SELECT name FROM gym_event_types WHERE is_active = 1 ORDER BY name ASC";
$event_types_result = $conn->query($event_types_query);
$event_types = [];
$other_index = -1;
if ($event_types_result) {
    $index = 0;
    while ($row = $event_types_result->fetch_assoc()) {
        if ($row['name'] === 'Other') {
            $other_index = $index;
        } else {
            $event_types[] = $row['name'];
        }
        $index++;
    }
    // Add "Other" at the bottom if it exists
    if ($other_index !== -1) {
        $event_types[] = 'Other';
    }
}
// If no event types found, use default list
if (empty($event_types)) {
    $event_types = ['Badminton', 'Basketball', 'Volleyball', 'Graduation Ceremony', 'Sports Tournament', 'Conference', 'Cultural Event', 'School Program', 'Other'];
}

// Facilities query removed - no longer using facility dropdown

// Note: We no longer fully disable dates on the calendar to allow partial-day bookings
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/external_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Gymnasium Reservation</h1>
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
                    <span class="text-gray-700 hidden sm:inline mr-2"><?php echo $_SESSION['user_name']; ?></span>
                    <button class="md:hidden rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-amber-500" id="menu-button">
                        <span class="sr-only">Open menu</span>
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Main content -->
        <main class="flex-1 overflow-y-auto p-4">
            <div class="max-w-7xl mx-auto">
                <?php if (isset($success_message)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                        <p><?php echo $success_message; ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php endif; ?>
                
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Booking Calendar -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                            <h3 class="text-lg font-medium text-gray-900">Reservation Calendar</h3>
                            <p class="mt-1 text-sm text-gray-500">View available dates for gymnasium Reservation</p>
                        </div>
                        <div class="p-4">
                            <div id="booking-calendar" class="bg-white p-2 rounded-lg"></div>
                            <div id="availability" class="mt-4 hidden">
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <h4 class="text-sm font-semibold text-blue-800 mb-3">Available Sessions on <span id="avail-date" class="font-normal text-blue-600"></span></h4>
                                    <div id="loading-indicator" class="text-center py-4 hidden">
                                        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                                        <p class="mt-2 text-sm text-gray-600">Loading availability...</p>
                                    </div>
                                    <div id="session-buttons" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-3"></div>
                                    <div id="booked-list" class="text-xs text-gray-600 bg-gray-50 p-2 rounded"></div>
                                    <div class="mt-2 text-xs text-gray-500">
                                        <i class="fas fa-info-circle mr-1"></i> Click on an available session to book that time slot
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-4">
                                <div class="flex items-center gap-1">
                                    <div class="h-4 w-4 rounded-full bg-green-500"></div>
                                    <span class="text-sm">Available</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <div class="h-4 w-4 rounded-full bg-gray-300"></div>
                                    <span class="text-sm">Booked</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Booking Guidelines -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                            <h3 class="text-lg font-medium text-gray-900">Reservation Guidelines</h3>
                            <p class="mt-1 text-sm text-gray-500">Important information for gymnasium Reservation</p>
                        </div>
                        <div class="p-4">
                            <ul class="list-disc pl-5 space-y-2 text-sm text-gray-700">
                                <li>Reservation must be made at least 3 days in advance.</li>
                                <li>Maximum capacity of the gymnasium is 2500 people.</li>
                                <li>Reservation are subject to approval by the administration.</li>
                                <li>Cancellations must be made at least 24 hours before the scheduled time.</li>
                                <li>The organization is responsible for cleaning up after the event.</li>
                                <li>Any damages to the facility will be charged to the Reservation organization.</li>
                                <li>For inquiries, please contact the BAO office at (034) 123-4567.</li>
                            </ul>
                            
                            <div class="mt-6 pt-4 border-t border-gray-200">
                                <h4 class="font-semibold text-gray-800 mb-3">Pricing Information</h4>
                                <?php
                                // Get pricing settings from database
                                $pricing_query = "SELECT setting_key, setting_value FROM gym_pricing_settings";
                                $pricing_result = $conn->query($pricing_query);
                                $pricing_display = [];
                                if ($pricing_result) {
                                    while ($row = $pricing_result->fetch_assoc()) {
                                        $pricing_display[$row['setting_key']] = (float)$row['setting_value'];
                                    }
                                }
                                // Set defaults if not found
                                if (empty($pricing_display)) {
                                    $pricing_display = [
                                        'gymnasium_per_hour' => 700.00,
                                        'sound_system_per_hour' => 150.00,
                                        'electricity_per_hour' => 150.00,
                                        'chair_free_limit' => 200.00,
                                        'chair_cost_per_unit' => 8.00
                                    ];
                                }
                                ?>
                                <ul class="list-disc pl-5 space-y-2 text-sm text-gray-700">
                                    <li><strong>Gymnasium:</strong> ₱<?php echo number_format($pricing_display['gymnasium_per_hour'], 2); ?> per hour</li>
                                    <li><strong>Sound System:</strong> ₱<?php echo number_format($pricing_display['sound_system_per_hour'], 2); ?> per hour</li>
                                    <li><strong>Electricity:</strong> ₱<?php echo number_format($pricing_display['electricity_per_hour'], 2); ?> per hour</li>
                                    <li><strong>Chairs:</strong> First <?php echo (int)$pricing_display['chair_free_limit']; ?> chairs are free, then ₱<?php echo number_format($pricing_display['chair_cost_per_unit'], 2); ?> per additional chair</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- My Bookings -->
                <div class="mt-6 bg-white rounded-lg shadow">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <h3 class="text-lg font-medium text-gray-900">My Reservation</h3>
                        <p class="mt-1 text-sm text-gray-500">View your gymnasium Reservation history</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reservation ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purpose</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($bookings_result->num_rows > 0): ?>
                                    <?php while ($booking = $bookings_result->fetch_assoc()): ?>
                                        <?php 
                                        $additional_info = json_decode($booking['additional_info'], true);
                                        $status_class = 'bg-gray-100 text-gray-800';
                                        $status_text = 'Unknown';
                                        
                                        // Get the status value, handle null/empty cases
                                        $booking_status = isset($booking['status']) ? trim(strtolower($booking['status'])) : '';
                                        
                                        // Ensure status is always set with proper display text
                                        switch ($booking_status) {
                                            case 'pending':
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                $status_text = 'Pending';
                                                break;
                                            case 'confirmed':
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
                                                // For any other status, use default styling
                                                $status_class = 'bg-gray-100 text-gray-800';
                                                if (!empty($booking['status'])) {
                                                    $status_text = ucfirst(trim($booking['status']));
                                                } else {
                                                    $status_text = 'Unknown';
                                                }
                                                break;
                                        }
                                        
                                        // Get other event type if purpose is "Other"
                                        $other_event_type = '';
                                        if ($booking['purpose'] === 'Other' && isset($additional_info['other_event_type']) && !empty($additional_info['other_event_type'])) {
                                            $other_event_type = $additional_info['other_event_type'];
                                        }
                                        ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $booking['booking_id']; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('F j, Y', strtotime($booking['date'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php 
                                                echo date('h:i A', strtotime($booking['start_time'])) . ' - ' . 
                                                     date('h:i A', strtotime($booking['end_time'])); 
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <div><?php echo $booking['purpose']; ?></div>
                                                <?php if ($booking['purpose'] === 'Other' && !empty($other_event_type)): ?>
                                                    <div class="text-xs text-gray-400 mt-1">
                                                        <i class="fas fa-info-circle mr-1"></i><?php echo htmlspecialchars($other_event_type); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php 
                                                // Debug: Ensure we always have a status to display
                                                $display_status = $status_text;
                                                if (empty($display_status) && isset($booking['status'])) {
                                                    $display_status = ucfirst(trim($booking['status']));
                                                }
                                                if (empty($display_status)) {
                                                    $display_status = 'Unknown';
                                                }
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo htmlspecialchars($status_class); ?>">
                                                    <?php echo htmlspecialchars($display_status); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex items-center gap-2">
                                                    <button type="button" class="inline-flex items-center px-3 py-1.5 border border-blue-300 shadow-sm text-sm font-medium rounded-md text-blue-700 bg-blue-50 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors" onclick="viewBookingDetails(<?php echo htmlspecialchars(json_encode($booking)); ?>, <?php echo htmlspecialchars(json_encode($additional_info)); ?>)">
                                                        <i class="fas fa-eye mr-1.5"></i> View
                                                </button>
                                                
                                                <?php if ($booking['status'] === 'pending'): ?>
                                                        <button type="button" onclick="openCancelModal('<?php echo $booking['booking_id']; ?>', '<?php echo htmlspecialchars($booking['booking_id']); ?>', '<?php echo date('F j, Y', strtotime($booking['date'])); ?>')" class="inline-flex items-center px-3 py-1.5 border border-red-300 shadow-sm text-sm font-medium rounded-md text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                                                            <i class="fas fa-times mr-1.5"></i> Cancel
                                                        </button>
                                                <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No bookings found</td>
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
        </main>
    </div>
</div>

<!-- Reminder Modal -->
<div id="reminderModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-md max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-6 pb-4 border-b border-gray-200 flex-shrink-0">
            <h3 class="text-lg font-medium text-gray-900">
                <i class="fas fa-exclamation-circle text-amber-500 mr-2"></i>Important Reminder
            </h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeReminderModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto flex-1">
            <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded">
                <p class="text-gray-700 font-medium">
                    <i class="fas fa-info-circle text-amber-500 mr-2"></i>
                    Please ensure that you have a letter from the president ready to upload before making a booking.
                </p>
                <p class="text-gray-600 text-sm mt-2">
                    You will be required to upload the letter in the booking form. Accepted formats: JPG, PNG, GIF, PDF (Max: 5MB)
                </p>
            </div>
        </div>
        <div class="flex justify-end p-6 pt-4 border-t border-gray-200 flex-shrink-0">
            <button type="button" class="bg-blue-600 text-white py-2 px-6 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" onclick="proceedToBooking()">
                I Understand, Continue
            </button>
        </div>
    </div>
</div>

<!-- Booking Modal -->
<div id="bookingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-md max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-6 pb-4 border-b border-gray-200 flex-shrink-0">
            <h3 class="text-lg font-medium text-gray-900">Reservation Gymnasium</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeBookingModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="gym.php" enctype="multipart/form-data" id="bookingForm" onsubmit="return validateBookingForm()" class="flex flex-col flex-1 min-h-0">
            <div class="p-6 overflow-y-auto flex-1">
            <input type="hidden" name="action" value="book">
            <div class="mb-4">
                <label for="letter" class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-file-upload mr-1"></i>Upload Letter from President <span class="text-red-500">*</span>
                </label>
                <input type="file" id="letter" name="letter" accept="image/*,.pdf" required 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50"
                       onchange="previewLetter(this)">
                <p class="mt-1 text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>
                    Please upload a letter from the president. Accepted formats: JPG, PNG, GIF, PDF (Max: 5MB)
                </p>
                <!-- Letter Preview -->
                <div id="letterPreview" class="mt-3 hidden">
                    <div class="border border-gray-300 rounded-lg p-3 bg-gray-50">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-file-pdf text-red-500 mr-2" id="letterIcon"></i>
                                <div>
                                    <p class="text-sm font-medium text-gray-900" id="letterFileName"></p>
                                    <p class="text-xs text-gray-500" id="letterFileSize"></p>
                                </div>
                            </div>
                            <button type="button" onclick="removeLetterPreview()" class="text-red-500 hover:text-red-700">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div id="letterImagePreview" class="mt-3 hidden">
                            <img id="letterPreviewImg" src="" alt="Letter Preview" class="max-w-full h-auto rounded border border-gray-300" style="max-height: 200px;">
                        </div>
                    </div>
                </div>
            </div>
            <!-- Date field is hidden - selected from calendar -->
            <input type="hidden" id="date" name="date" required>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="start_time" class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                    <input type="time" id="start_time" name="start_time" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50">
                </div>
                <div>
                    <label for="end_time" class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                    <input type="time" id="end_time" name="end_time" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50">
                </div>
            </div>
            <div class="mb-4">
                <label for="purpose" class="block text-sm font-medium text-gray-700 mb-1">Purpose/Event Type</label>
                <select id="purpose" name="purpose" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50" onchange="toggleEquipmentDropdown()">
                    <option value="">Select event type</option>
                    <?php foreach ($event_types as $event_type): ?>
                        <option value="<?php echo htmlspecialchars($event_type); ?>"><?php echo htmlspecialchars($event_type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4" id="otherEventInput" style="display: none;">
                <label for="other_event_type" class="block text-sm font-medium text-gray-700 mb-1">Specify Event Type</label>
                <input type="text" id="other_event_type" name="other_event_type" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50" placeholder="Enter event type">
                <p class="mt-1 text-xs text-gray-500">Please specify the event type</p>
            </div>
            <div class="mb-4" id="equipmentDropdown" style="display: none;">
                <label for="equipment" class="block text-sm font-medium text-gray-700 mb-1">Equipment/Services Needed</label>
                <select id="equipment" name="equipment" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50" onchange="toggleChairPairsField()">
                    <option value="">Select equipment/service</option>
                    <option value="Sound System">Sound System</option>
                    <option value="Electricity">Electricity</option>
                    <option value="Chairs">Chairs</option>
                    <option value="Sound System + Electricity">Sound System + Electricity</option>
                    <option value="Sound System + Chairs">Sound System + Chairs</option>
                    <option value="Electricity + Chairs">Electricity + Chairs</option>
                    <option value="Sound System + Electricity + Chairs">Sound System + Electricity + Chairs</option>
                </select>
                <p class="mt-1 text-xs text-gray-500">Select the equipment or services you need for your event</p>
            </div>
            <div class="mb-4" id="chairPairsField">
                <label for="chair_pairs" class="block text-sm font-medium text-gray-700 mb-1">Number of Chairs</label>
                <input type="number" id="chair_pairs" name="chair_pairs" min="0" max="999" step="1" value="0" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50" oninput="if(this.value > 999) this.value = 999;">
                <?php
                // Get pricing for chairs display
                $chair_pricing_query = "SELECT setting_key, setting_value FROM gym_pricing_settings WHERE setting_key IN ('chair_free_limit', 'chair_cost_per_unit')";
                $chair_pricing_result = $conn->query($chair_pricing_query);
                $chair_pricing = ['chair_free_limit' => 200, 'chair_cost_per_unit' => 8];
                if ($chair_pricing_result) {
                    while ($row = $chair_pricing_result->fetch_assoc()) {
                        $chair_pricing[$row['setting_key']] = (float)$row['setting_value'];
                    }
                }
                ?>
                <p class="mt-1 text-xs text-gray-500">First <?php echo (int)$chair_pricing['chair_free_limit']; ?> chairs are free. Additional chairs beyond <?php echo (int)$chair_pricing['chair_free_limit']; ?> are ₱<?php echo number_format($chair_pricing['chair_cost_per_unit'], 2); ?> each. Maximum: 999 chairs.</p>
            </div>
            <div class="mb-4">
                <label for="attendees" class="block text-sm font-medium text-gray-700 mb-1">Expected Number of Attendees</label>
                <input type="number" id="attendees" name="attendees" min="1" max="2500" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50">
                <p class="mt-1 text-xs text-gray-500">Maximum capacity: 2500 people</p>
            </div>
            <div class="mb-4">
                <label for="organization" class="block text-sm font-medium text-gray-700 mb-1">Organization Name</label>
                <input type="text" id="organization" name="organization" required 
                       pattern="[A-Za-z\s]+" 
                       title="Organization name must contain only letters and spaces"
                       onkeypress="return /[A-Za-z\s]/.test(event.key)" 
                       oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50">
                <p class="mt-1 text-xs text-gray-500">Letters and spaces only</p>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                    <input type="text" id="contact_person" name="contact_person" required 
                           pattern="[A-Za-z\s]+" 
                           title="Contact person name must contain only letters and spaces"
                           onkeypress="return /[A-Za-z\s]/.test(event.key)" 
                           oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50">
                    <p class="mt-1 text-xs text-gray-500">Letters and spaces only</p>
                </div>
                <div>
                    <label for="contact_number" class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                    <input type="text" id="contact_number" name="contact_number" required 
                           pattern="[0-9]{11}" 
                           title="Contact number must be exactly 11 digits"
                           inputmode="numeric"
                           maxlength="11"
                           onkeypress="return (event.charCode >= 48 && event.charCode <= 57)" 
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring focus:ring-amber-500 focus:ring-opacity-50">
                    <p class="mt-1 text-xs text-gray-500">11 digits only</p>
                </div>
            </div>
            </div>
            <div class="flex justify-end gap-2 p-6 pt-4 border-t border-gray-200 flex-shrink-0">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeBookingModal()">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                    Submit Booking
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Receipt Modal -->
<div id="receiptModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-6 pb-4 border-b border-gray-200 flex-shrink-0">
            <h3 class="text-xl font-bold text-gray-900">
                <i class="fas fa-receipt text-green-500 mr-2"></i>Booking Receipt
            </h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeReceiptModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <?php if (isset($_SESSION['booking_receipt'])): 
            $receipt = $_SESSION['booking_receipt'];
            $costs = $receipt['cost_breakdown'];
        ?>
        <div class="p-6 overflow-y-auto flex-1">
        <div class="space-y-4">
            <!-- Booking Information -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-semibold text-gray-800 mb-3">Booking Information</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-600">Booking ID:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo $receipt['booking_id']; ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Date:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo date('F j, Y', strtotime($receipt['date'])); ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Time:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo date('h:i A', strtotime($receipt['start_time'])); ?> - <?php echo date('h:i A', strtotime($receipt['end_time'])); ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Duration:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo $receipt['hours']; ?> hours</span>
                    </div>
                    <div>
                        <span class="text-gray-600">Purpose:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo $receipt['purpose']; ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Attendees:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo $receipt['attendees']; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Organization Information -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-semibold text-gray-800 mb-3">Organization Information</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-600">Organization:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo $receipt['organization']; ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Contact Person:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo $receipt['contact_person']; ?></span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-600">Contact Number:</span>
                        <span class="font-medium text-gray-900 ml-2"><?php echo $receipt['contact_number']; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Equipment -->
            <?php if (!empty($receipt['equipment'])): ?>
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-semibold text-gray-800 mb-3">Equipment/Services</h4>
                <p class="text-sm text-gray-900"><?php echo $receipt['equipment']; ?></p>
            </div>
            <?php endif; ?>
            
            <!-- Cost Breakdown -->
            <div class="bg-blue-50 p-4 rounded-lg border-2 border-blue-200">
                <h4 class="font-semibold text-gray-800 mb-4">Cost Breakdown</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-700">Gymnasium (₱700/hour × <?php echo $receipt['hours']; ?> hrs):</span>
                        <span class="font-medium text-gray-900">₱<?php echo number_format($costs['gymnasium'], 2); ?></span>
                    </div>
                    <?php if ($costs['sound_system'] > 0): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-700">Sound System (₱150/hour × <?php echo $receipt['hours']; ?> hrs):</span>
                        <span class="font-medium text-gray-900">₱<?php echo number_format($costs['sound_system'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($costs['electricity'] > 0): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-700">Electricity (₱150/hour × <?php echo $receipt['hours']; ?> hrs):</span>
                        <span class="font-medium text-gray-900">₱<?php echo number_format($costs['electricity'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php 
                    $chairs_count = isset($receipt['chairs_count']) ? $receipt['chairs_count'] : 0;
                    if ($chairs_count > 0): 
                        $free_chairs = min(200, $chairs_count);
                        $paid_chairs = max(0, $chairs_count - 200);
                    ?>
                    <div class="flex justify-between">
                        <span class="text-gray-700">Chairs (<?php echo $chairs_count; ?> total):</span>
                        <span class="font-medium text-gray-900">
                            <?php if ($chairs_count <= 200): ?>
                                Free (<?php echo $chairs_count; ?> chairs)
                            <?php else: ?>
                                ₱<?php echo number_format($costs['chairs'], 2); ?> (<?php echo $free_chairs; ?> free + <?php echo $paid_chairs; ?> × ₱8)
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="border-t-2 border-blue-300 pt-2 mt-2">
                        <div class="flex justify-between">
                            <span class="font-bold text-lg text-gray-900">Total Amount:</span>
                            <span class="font-bold text-lg text-green-600">₱<?php echo number_format($costs['total'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Status Note -->
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                <p class="text-sm text-yellow-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Note:</strong> This booking is pending approval. You will be notified once the booking is confirmed.
                </p>
            </div>
        </div>
        </div>
        
        <div class="mt-6 flex justify-end gap-2 p-6 pt-4 border-t border-gray-200 flex-shrink-0">
            <button type="button" class="bg-gray-200 text-gray-700 py-2 px-6 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="printReceipt()">
                <i class="fas fa-print mr-2"></i>Print Receipt
            </button>
            <button type="button" class="bg-blue-600 text-white py-2 px-6 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" onclick="closeReceiptModal()">
                Close
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Booking Details Modal -->
<div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-md max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-6 pb-4 border-b border-gray-200 flex-shrink-0">
            <h3 class="text-lg font-medium text-gray-900">Reservation Details</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeDetailsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto flex-1">
        <div class="space-y-4">
            <div>
                <h4 class="text-sm font-medium text-gray-500">Reservation ID</h4>
                <p id="detail-booking-id" class="mt-1 text-sm text-gray-900"></p>
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
                <h4 class="text-sm font-medium text-gray-500">Attendees</h4>
                <p id="detail-attendees" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Organization</h4>
                <p id="detail-organization" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Contact Person</h4>
                <p id="detail-contact-person" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Contact Number</h4>
                <p id="detail-contact-number" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div id="detail-equipment-container">
                <h4 class="text-sm font-medium text-gray-500">Equipment/Services</h4>
                <p id="detail-equipment" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div id="detail-letter-container" class="hidden">
                <h4 class="text-sm font-medium text-gray-500 mb-2">Letter from President</h4>
                <div class="border border-gray-300 rounded-lg p-3 bg-gray-50">
                    <a id="detail-letter-link" href="#" target="_blank" class="flex items-center text-blue-600 hover:text-blue-800">
                        <i class="fas fa-file-pdf mr-2"></i>
                        <span id="detail-letter-text">View Letter</span>
                    </a>
                    <div id="detail-letter-image" class="mt-3 hidden">
                        <img id="detail-letter-img" src="" alt="Letter" class="max-w-full h-auto rounded border border-gray-300" style="max-height: 250px; object-fit: contain;">
                    </div>
                </div>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">Status</h4>
                <p id="detail-status" class="mt-1 text-sm"></p>
            </div>
            <div id="detail-rejection-reason-container" class="hidden">
                <h4 class="text-sm font-medium text-gray-500">Rejection Reason</h4>
                <p id="detail-rejection-reason" class="mt-1 text-sm text-gray-900"></p>
            </div>
            <div id="detail-total-amount-container">
                <h4 class="text-sm font-medium text-gray-500">Total Amount</h4>
                <p id="detail-total-amount" class="mt-1 text-sm font-bold text-green-600"></p>
            </div>
        </div>
        </div>
        <div class="mt-6 flex justify-end p-6 pt-4 border-t border-gray-200 flex-shrink-0">
            <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeDetailsModal()">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Cancel Booking Modal -->
<div id="cancelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">
                <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>Cancel Booking
            </h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeCancelModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mb-6">
            <p class="text-gray-700 mb-2">Are you sure you want to cancel this booking?</p>
            <div class="bg-gray-50 p-3 rounded-md">
                <p class="text-sm text-gray-600"><strong>Booking ID:</strong> <span id="cancel-booking-id"></span></p>
                <p class="text-sm text-gray-600"><strong>Date:</strong> <span id="cancel-booking-date"></span></p>
            </div>
            <p class="text-sm text-red-600 mt-3">
                <i class="fas fa-info-circle mr-1"></i>This action cannot be undone.
            </p>
        </div>
        <form method="POST" action="gym.php" id="cancelForm">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="booking_id" id="cancel-booking-id-input">
            <div class="flex justify-end gap-2">
                <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeCancelModal()">
                    No, Keep It
                </button>
                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    <i class="fas fa-times mr-1"></i>Yes, Cancel Booking
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<script>
    // Mobile menu toggle
    document.getElementById('menu-button').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
    });
    
    // Toggle equipment dropdown when event type is selected
    function toggleEquipmentDropdown() {
        const purposeSelect = document.getElementById('purpose');
        const equipmentDropdown = document.getElementById('equipmentDropdown');
        const otherEventInput = document.getElementById('otherEventInput');
        
        // Show input field only when "Other" is selected
        if (purposeSelect.value === 'Other') {
            otherEventInput.style.display = 'block';
            // Make input field required when "Other" is selected
            const otherEventInputField = document.getElementById('other_event_type');
            if (otherEventInputField) {
                otherEventInputField.required = true;
            }
        } else {
            otherEventInput.style.display = 'none';
            // Remove required attribute when not "Other"
            const otherEventInputField = document.getElementById('other_event_type');
            if (otherEventInputField) {
                otherEventInputField.required = false;
                otherEventInputField.value = ''; // Reset value
            }
        }
        
        if (purposeSelect.value !== '') {
            equipmentDropdown.style.display = 'block';
        } else {
            equipmentDropdown.style.display = 'none';
        }
        // Chairs are always available, so always show the field
        document.getElementById('chairPairsField').style.display = 'block';
    }
    
    // Toggle chair pairs field - chairs are always available
    function toggleChairPairsField() {
        const equipmentSelect = document.getElementById('equipment');
        const chairPairsField = document.getElementById('chairPairsField');
        
        // Chairs are always available, so always show the field
        chairPairsField.style.display = 'block';
    }
    
    // Validate booking form before submission
    function validateBookingForm() {
        const letterInput = document.getElementById('letter');
        
        // Check if letter file is uploaded
        if (!letterInput.files || letterInput.files.length === 0) {
            alert('Please upload a letter from the president before submitting.');
            letterInput.focus();
            return false;
        }
        
        // Check file size (5MB max)
        const file = letterInput.files[0];
        const maxSize = 5 * 1024 * 1024; // 5MB in bytes
        
        if (file.size > maxSize) {
            alert('Letter file size must not exceed 5MB. Please choose a smaller file.');
            letterInput.focus();
            return false;
        }
        
        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        if (!allowedTypes.includes(file.type)) {
            alert('Only JPG, PNG, GIF images and PDF files are allowed for the letter.');
            letterInput.focus();
            return false;
        }
        
        // Validate number of chairs (max 999)
        const chairPairsInput = document.getElementById('chair_pairs');
        if (chairPairsInput && parseInt(chairPairsInput.value) > 999) {
            alert('Number of chairs cannot exceed 999. Please enter a valid number.');
            chairPairsInput.focus();
            chairPairsInput.value = 999;
            return false;
        }
        
        return true;
    }
    
    // Letter preview functions
    function previewLetter(input) {
        const preview = document.getElementById('letterPreview');
        const fileName = document.getElementById('letterFileName');
        const fileSize = document.getElementById('letterFileSize');
        const fileIcon = document.getElementById('letterIcon');
        const imagePreview = document.getElementById('letterImagePreview');
        const previewImg = document.getElementById('letterPreviewImg');
        
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const fileSizeKB = (file.size / 1024).toFixed(2);
            const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
            
            fileName.textContent = file.name;
            fileSize.textContent = fileSizeMB > 1 ? fileSizeMB + ' MB' : fileSizeKB + ' KB';
            
            // Set icon based on file type
            if (file.type === 'application/pdf') {
                fileIcon.className = 'fas fa-file-pdf text-red-500 mr-2';
            } else if (file.type.startsWith('image/')) {
                fileIcon.className = 'fas fa-file-image text-blue-500 mr-2';
            } else {
                fileIcon.className = 'fas fa-file text-gray-500 mr-2';
            }
            
            // Show image preview if it's an image
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    imagePreview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            } else {
                imagePreview.classList.add('hidden');
            }
            
            preview.classList.remove('hidden');
        } else {
            preview.classList.add('hidden');
        }
    }
    
    function removeLetterPreview() {
        const preview = document.getElementById('letterPreview');
        const letterInput = document.getElementById('letter');
        const imagePreview = document.getElementById('letterImagePreview');
        
        preview.classList.add('hidden');
        imagePreview.classList.add('hidden');
        if (letterInput) {
            letterInput.value = '';
        }
    }
    
    // Reminder modal functions
    let pendingBookingAction = null;
    let isNavigationReminder = false; // Track if reminder is for navigation or booking
    
    // Global function to show reminder modal from sidebar
    function showGymReminderModal() {
        isNavigationReminder = true;
        document.getElementById('reminderModal').classList.remove('hidden');
    }
    
    function openReminderModal() {
        isNavigationReminder = false;
        document.getElementById('reminderModal').classList.remove('hidden');
    }
    
    function closeReminderModal() {
        document.getElementById('reminderModal').classList.add('hidden');
        isNavigationReminder = false;
    }
    
    function proceedToBooking() {
        closeReminderModal();
        
        // If this is a navigation reminder, just close the modal (we're already on gym.php)
        if (isNavigationReminder) {
            // Scroll to top of page to show the booking calendar
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        
        // If there was a pending action (like setting times), execute it
        if (pendingBookingAction) {
            if (pendingBookingAction.startTime && pendingBookingAction.endTime) {
                document.getElementById('start_time').value = pendingBookingAction.startTime;
                document.getElementById('end_time').value = pendingBookingAction.endTime;
            }
            pendingBookingAction = null;
        }
        document.getElementById('bookingModal').classList.remove('hidden');
    }
    
    // Booking modal functions
    function openBookingModal(startTime = null, endTime = null) {
        // Store the times if provided
        if (startTime && endTime) {
            pendingBookingAction = { startTime: startTime, endTime: endTime };
        }
        // Show reminder modal first
        openReminderModal();
    }
    
    function closeBookingModal() {
        document.getElementById('bookingModal').classList.add('hidden');
        pendingBookingAction = null;
        // Reset form fields
        const form = document.querySelector('#bookingModal form');
        if (form) {
            form.reset();
        }
        // Reset letter preview
        removeLetterPreview();
        document.getElementById('purpose').value = '';
        document.getElementById('equipment').value = '';
        document.getElementById('equipmentDropdown').style.display = 'none';
        document.getElementById('otherEventInput').style.display = 'none';
        const otherEventInputField = document.getElementById('other_event_type');
        if (otherEventInputField) {
            otherEventInputField.value = '';
            otherEventInputField.required = false;
        }
        // Chairs are always available, so keep the field visible
        document.getElementById('chairPairsField').style.display = 'block';
        document.getElementById('chair_pairs').value = 0;
        
        // Add validation for chair_pairs input
        const chairPairsInput = document.getElementById('chair_pairs');
        if (chairPairsInput) {
            chairPairsInput.addEventListener('input', function() {
                if (this.value > 999) {
                    this.value = 999;
                    alert('Maximum number of chairs is 999.');
                }
            });
            
            chairPairsInput.addEventListener('blur', function() {
                if (this.value > 999) {
                    this.value = 999;
                    alert('Maximum number of chairs is 999.');
                }
            });
        }
    }
    
    // Details modal functions
    function viewBookingDetails(booking, additionalInfo) {
        document.getElementById('detail-booking-id').textContent = booking.booking_id;
        document.getElementById('detail-datetime').textContent = formatDate(booking.date) + ', ' + 
                                                               formatTime(booking.start_time) + ' - ' + 
                                                               formatTime(booking.end_time);
        // Display purpose with other_event_type if "Other" is selected
        let purposeText = booking.purpose;
        if (booking.purpose === 'Other' && additionalInfo.other_event_type) {
            purposeText = booking.purpose + ' (' + additionalInfo.other_event_type + ')';
        }
        document.getElementById('detail-purpose').textContent = purposeText;
        document.getElementById('detail-attendees').textContent = booking.attendees;
        document.getElementById('detail-organization').textContent = additionalInfo.organization;
        document.getElementById('detail-contact-person').textContent = additionalInfo.contact_person;
        document.getElementById('detail-contact-number').textContent = additionalInfo.contact_number;
        
        // Display equipment information
        const equipmentContainer = document.getElementById('detail-equipment-container');
        const equipmentElement = document.getElementById('detail-equipment');
        if (additionalInfo.equipment && additionalInfo.equipment !== '') {
            equipmentElement.textContent = additionalInfo.equipment;
            equipmentContainer.style.display = 'block';
        } else {
            equipmentElement.textContent = 'None selected';
            equipmentContainer.style.display = 'block';
        }
        
        // Display letter if available
        const letterContainer = document.getElementById('detail-letter-container');
        const letterLink = document.getElementById('detail-letter-link');
        const letterText = document.getElementById('detail-letter-text');
        const letterImage = document.getElementById('detail-letter-image');
        const letterImg = document.getElementById('detail-letter-img');
        
        if (additionalInfo.letter_path) {
            const letterPath = '../' + additionalInfo.letter_path;
            letterLink.href = letterPath;
            letterText.textContent = 'View Letter';
            
            // Check if it's an image to show preview
            const isImage = /\.(jpg|jpeg|png|gif)$/i.test(additionalInfo.letter_path);
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
        
        // Set status with appropriate styling
        const statusElement = document.getElementById('detail-status');
        
        // Reset classes
        statusElement.className = 'mt-1 text-sm px-2 inline-flex text-xs leading-5 font-semibold rounded-full';
        
        // Add appropriate class and text based on status
        switch (booking.status) {
            case 'pending':
                statusElement.classList.add('bg-yellow-100', 'text-yellow-800');
                statusElement.textContent = 'Pending';
                break;
            case 'confirmed':
                statusElement.classList.add('bg-green-100', 'text-green-800');
                statusElement.textContent = 'Approved';
                break;
            case 'rejected':
                statusElement.classList.add('bg-red-100', 'text-red-800');
                statusElement.textContent = 'Rejected';
                break;
            case 'cancelled':
                statusElement.classList.add('bg-gray-100', 'text-gray-800');
                statusElement.textContent = 'Cancelled';
                break;
            default:
                // For any other status, use default styling
                statusElement.classList.add('bg-gray-100', 'text-gray-800');
                statusElement.textContent = booking.status.charAt(0).toUpperCase() + booking.status.slice(1);
                break;
        }
        
        // Show rejection reason if available
        const rejectionContainer = document.getElementById('detail-rejection-reason-container');
        const rejectionReason = document.getElementById('detail-rejection-reason');
        
        if (booking.status === 'rejected' && additionalInfo.rejection_reason) {
            rejectionReason.textContent = additionalInfo.rejection_reason;
            rejectionContainer.classList.remove('hidden');
        } else {
            rejectionContainer.classList.add('hidden');
        }
        
        // Display total amount if available
        const totalAmountContainer = document.getElementById('detail-total-amount-container');
        const totalAmountElement = document.getElementById('detail-total-amount');
        
        if (additionalInfo.cost_breakdown && additionalInfo.cost_breakdown.total) {
            const totalAmount = parseFloat(additionalInfo.cost_breakdown.total);
            totalAmountElement.textContent = '₱' + totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            totalAmountContainer.style.display = 'block';
        } else {
            totalAmountContainer.style.display = 'none';
        }
        
        document.getElementById('detailsModal').classList.remove('hidden');
    }
    
    function closeDetailsModal() {
        document.getElementById('detailsModal').classList.add('hidden');
    }
    
    // Cancel modal functions
    function openCancelModal(bookingId, bookingIdDisplay, bookingDate) {
        document.getElementById('cancel-booking-id').textContent = bookingIdDisplay;
        document.getElementById('cancel-booking-date').textContent = bookingDate;
        document.getElementById('cancel-booking-id-input').value = bookingId;
        document.getElementById('cancelModal').classList.remove('hidden');
    }
    
    function closeCancelModal() {
        document.getElementById('cancelModal').classList.add('hidden');
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const cancelModal = document.getElementById('cancelModal');
        if (event.target === cancelModal) {
            closeCancelModal();
        }
    });
    
    // Receipt modal functions
    function closeReceiptModal() {
        document.getElementById('receiptModal').classList.add('hidden');
        // Clear the receipt from session by reloading without the parameter
        if (window.location.search.includes('show_receipt=1')) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }
    
    function printReceipt() {
        // Get receipt data from the modal
        <?php if (isset($_SESSION['booking_receipt'])): ?>
        const bookingId = '<?php echo $_SESSION['booking_receipt']['booking_id']; ?>';
        if (bookingId) {
            // Open print receipt page in new window
            window.open('print_gym_receipt.php?booking_id=' + bookingId, '_blank');
        } else {
            alert('Receipt data not available');
        }
        <?php else: ?>
        alert('Receipt data not available');
        <?php endif; ?>
    }
    
    // Helper functions
    function formatDate(dateString) {
        const options = { year: 'numeric', month: 'long', day: 'numeric' };
        return new Date(dateString).toLocaleDateString(undefined, options);
    }
    
    function formatTime(timeString) {
        const [hours, minutes] = timeString.split(':');
        const date = new Date();
        date.setHours(hours);
        date.setMinutes(minutes);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    // Initialize calendar
    document.addEventListener('DOMContentLoaded', function() {
        // Show receipt modal if needed
        <?php if (isset($_GET['show_receipt']) && isset($_SESSION['booking_receipt'])): ?>
        setTimeout(function() {
            document.getElementById('receiptModal').classList.remove('hidden');
        }, 500);
        <?php endif; ?>
        
        // Setup chair pairs validation
        const chairPairsInput = document.getElementById('chair_pairs');
        if (chairPairsInput) {
            chairPairsInput.addEventListener('input', function() {
                if (this.value > 999) {
                    this.value = 999;
                    alert('Maximum number of chairs is 999.');
                }
            });
            
            chairPairsInput.addEventListener('blur', function() {
                if (this.value > 999) {
                    this.value = 999;
                    alert('Maximum number of chairs is 999.');
                }
            });
        }
        // Initialize flatpickr calendar (do not disable entire dates)
        // Calculate minimum date (3 days from today)
        const today = new Date();
        const minDate = new Date(today);
        minDate.setDate(today.getDate() + 3);
        
        flatpickr("#booking-calendar", {
            inline: true,
            minDate: minDate,
            dateFormat: "Y-m-d",
            onChange: function(selectedDates, dateStr) {
                if (selectedDates.length > 0) {
                    document.getElementById('date').value = dateStr;
                    
                    // Show availability section and loading indicator
                    const avail = document.getElementById('availability');
                    const loadingIndicator = document.getElementById('loading-indicator');
                    const sessionsDiv = document.getElementById('session-buttons');
                    const bookedDiv = document.getElementById('booked-list');
                    
                    avail.classList.remove('hidden');
                    loadingIndicator.classList.remove('hidden');
                    sessionsDiv.innerHTML = '';
                    bookedDiv.innerHTML = '';
                    
                    // Fetch availability for selected date (assuming single gym facility)
                    const facilityIdField = document.querySelector('select[name="facility_id"], #facility_id');
                    const facilityId = facilityIdField ? facilityIdField.value || 1 : 1;
                    fetch(`get_gym_availability.php?date=${encodeURIComponent(dateStr)}&facility_id=${encodeURIComponent(facilityId)}`)
                        .then(r => {
                            console.log('Response status:', r.status);
                            if (!r.ok) {
                                throw new Error(`HTTP error! status: ${r.status}`);
                            }
                            return r.json();
                        })
                        .then(data => {
                            console.log('Availability data:', data);
                            // Hide loading indicator
                            loadingIndicator.classList.add('hidden');
                            
                            // Get the date span element
                            const dateSpan = document.getElementById('avail-date');
                            
                            // Format date for display
                            const selectedDate = new Date(dateStr);
                            const formattedDate = selectedDate.toLocaleDateString('en-US', { 
                                weekday: 'long', 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric' 
                            });
                            dateSpan.textContent = formattedDate;
                            
                            sessionsDiv.innerHTML = '';
                            bookedDiv.innerHTML = '';
                            
                            // Check if date is blocked by school event
                            if (data.is_blocked) {
                                const blockedDiv = document.createElement('div');
                                blockedDiv.className = 'text-center py-6 px-4';
                                const eventIcon = data.blocked_info?.event_type === 'ceremony' ? '🎓' : 
                                                 data.blocked_info?.event_type === 'intramurals' ? '🏅' : '🚫';
                                blockedDiv.innerHTML = `
                                    <div class="text-6xl mb-3">${eventIcon}</div>
                                    <p class="text-lg font-bold text-red-600 mb-2">Date Blocked</p>
                                    <p class="text-md font-semibold text-gray-800 mb-1">${data.blocked_info?.event_name || 'School Event'}</p>
                                    <p class="text-sm text-gray-600">${data.message || 'This date is not available for booking.'}</p>
                                    <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded">
                                        <p class="text-xs text-red-700">${data.blocked_info?.description || ''}</p>
                                    </div>
                                `;
                                sessionsDiv.appendChild(blockedDiv);
                                return; // Stop processing
                            }
                            
                            // Check if month is allowed (for external users)
                            if (data.month_allowed === false) {
                                const blockedDiv = document.createElement('div');
                                blockedDiv.className = 'text-center py-6 px-4';
                                blockedDiv.innerHTML = `
                                    <div class="text-6xl mb-3">📅</div>
                                    <p class="text-lg font-bold text-orange-600 mb-2">Month Not Available</p>
                                    <p class="text-sm text-gray-600 mb-3">${data.message || 'External users can only book during specific months.'}</p>
                                    <div class="mt-4 p-3 bg-orange-50 border border-orange-200 rounded">
                                        <p class="text-xs text-orange-700 font-semibold">Allowed Months:</p>
                                        <p class="text-xs text-orange-700">${(data.allowed_months || []).join(', ')}</p>
                                    </div>
                                `;
                                sessionsDiv.appendChild(blockedDiv);
                                return; // Stop processing
                            }
                            
                            // Check if any sessions are available
                            const availableSessions = data.sessions.filter(s => s.available);
                            const bookedSessions = data.sessions.filter(s => !s.available);
                            
                            if (availableSessions.length === 0) {
                                // All sessions are booked
                                const noAvailability = document.createElement('div');
                                noAvailability.className = 'text-center py-4 text-gray-600';
                                noAvailability.innerHTML = `
                                    <i class="fas fa-calendar-times text-2xl mb-2 text-red-500"></i>
                                    <p class="font-semibold">Fully Booked</p>
                                    <p class="text-sm">This date is completely booked. Please select another date.</p>
                                `;
                                sessionsDiv.appendChild(noAvailability);
                            } else {
                                // Show available sessions
                                availableSessions.forEach(s => {
                                    const btn = document.createElement('button');
                                    btn.type = 'button';
                                    
                                    // Special styling for whole day booking
                                    const isWholeDay = s.type === 'whole_day';
                                    const btnClass = isWholeDay 
                                        ? 'py-4 px-4 rounded-lg text-sm bg-blue-100 text-blue-800 hover:bg-blue-200 border-2 border-blue-400 transition-colors font-bold'
                                        : 'py-3 px-4 rounded-lg text-sm bg-green-100 text-green-800 hover:bg-green-200 border border-green-300 transition-colors';
                                    
                                    btn.innerHTML = `
                                        <div class="text-left">
                                            <div class="font-semibold">${s.label.split(' (')[0]}</div>
                                            <div class="text-xs opacity-75">${s.start.substring(0,5)} - ${s.end.substring(0,5)}</div>
                                            ${isWholeDay ? '<div class="text-xs font-bold text-blue-600 mt-1">🌟 FULL DAY ACCESS</div>' : ''}
                                        </div>
                                    `;
                                    btn.className = btnClass;
                                    btn.addEventListener('click', () => {
                                        openBookingModal(s.start.slice(0,5), s.end.slice(0,5));
                                    });
                                    sessionsDiv.appendChild(btn);
                                });
                                
                                // Additionally, compute dynamic free windows (like 14:30 - 18:00) and offer as custom slots
                                // Respect lunch break: 12:00 - 13:00
                                const dayStart = '08:00:00';
                                const lunchStart = '12:00:00';
                                const lunchEnd = '13:00:00';
                                const dayEnd = '18:00:00';
                                const bookedTimes = (data.booked || []).map(b => ({ start: b.start, end: b.end }))
                                    .sort((a, b) => a.start.localeCompare(b.start));
                                let currentTime = dayStart;
                                const freeWindows = [];
                                
                                bookedTimes.forEach(booking => {
                                    if (currentTime < booking.start) {
                                        // Split available window if it crosses lunch break
                                        if (currentTime < lunchStart && booking.start > lunchStart) {
                                            // Add morning slot (before lunch)
                                            if (currentTime < lunchStart) {
                                                freeWindows.push({ start: currentTime, end: lunchStart });
                                            }
                                            // Add afternoon slot (after lunch) if booking starts after lunch
                                            if (booking.start > lunchEnd) {
                                                freeWindows.push({ start: lunchEnd, end: booking.start });
                                            }
                                        } else if (currentTime >= lunchEnd || booking.start <= lunchStart) {
                                            // Normal window that doesn't cross lunch
                                            freeWindows.push({ start: currentTime, end: booking.start });
                                        }
                                    }
                                    if (booking.end > currentTime) {
                                        // Skip lunch break when updating current time
                                        if (booking.end > lunchStart && booking.end < lunchEnd) {
                                            currentTime = lunchEnd;
                                        } else if (currentTime < lunchStart && booking.end >= lunchStart) {
                                            currentTime = (booking.end >= lunchEnd) ? booking.end : lunchEnd;
                                        } else {
                                            currentTime = booking.end;
                                        }
                                    }
                                });
                                
                                // Check remaining time after last booking
                                if (currentTime < dayEnd) {
                                    // Split if it crosses lunch break
                                    if (currentTime < lunchStart) {
                                        freeWindows.push({ start: currentTime, end: lunchStart });
                                        freeWindows.push({ start: lunchEnd, end: dayEnd });
                                    } else if (currentTime >= lunchEnd) {
                                        freeWindows.push({ start: currentTime, end: dayEnd });
                                    }
                                    // If currentTime is between lunchStart and lunchEnd, start from lunchEnd
                                    else if (currentTime >= lunchStart && currentTime < lunchEnd) {
                                        freeWindows.push({ start: lunchEnd, end: dayEnd });
                                    }
                                }
                                // Render free windows of at least 60 minutes as selectable custom slots
                                freeWindows.forEach(w => {
                                    const [sh, sm] = w.start.split(':').map(Number);
                                    const [eh, em] = w.end.split(':').map(Number);
                                    const diffMinutes = (eh * 60 + em) - (sh * 60 + sm);
                                    if (diffMinutes >= 60) {
                                        const btn = document.createElement('button');
                                        btn.type = 'button';
                                        btn.className = 'py-3 px-4 rounded-lg text-sm bg-emerald-50 text-emerald-800 hover:bg-emerald-100 border border-emerald-300 transition-colors';
                                        btn.innerHTML = `
                                            <div class="text-left">
                                                <div class="font-semibold">Available Window</div>
                                                <div class="text-xs opacity-75">${w.start.substring(0,5)} - ${w.end.substring(0,5)}</div>
                                            </div>
                                        `;
                                        btn.addEventListener('click', () => {
                                            openBookingModal(w.start.slice(0,5), w.end.slice(0,5));
                                        });
                                        sessionsDiv.appendChild(btn);
                                    }
                                });
                            }
                            
                            // Show booked sessions
                            if (bookedSessions.length > 0) {
                                const bookedTitle = document.createElement('div');
                                bookedTitle.className = 'font-semibold text-gray-700 mb-2';
                                bookedTitle.textContent = 'Booked Sessions:';
                                bookedDiv.appendChild(bookedTitle);
                                
                                bookedSessions.forEach(s => {
                                    const isWholeDay = s.type === 'whole_day';
                                    const itemClass = isWholeDay 
                                        ? 'flex items-center justify-between py-2 px-3 bg-red-100 rounded mb-2 border border-red-300'
                                        : 'flex items-center justify-between py-1 px-2 bg-red-50 rounded mb-1';
                                    
                                    const item = document.createElement('div');
                                    item.className = itemClass;
                                    item.innerHTML = `
                                        <span class="text-sm text-gray-700">${s.label.split(' (')[0]}</span>
                                        <span class="text-xs text-red-600 font-medium">${isWholeDay ? 'FULL DAY BOOKED' : 'Booked'}</span>
                                    `;
                                    bookedDiv.appendChild(item);
                                });
                                
                                // If there's a whole day booking, show a special notice
                                const hasWholeDayBooking = bookedSessions.some(s => s.type === 'whole_day');
                                if (hasWholeDayBooking) {
                                    const notice = document.createElement('div');
                                    notice.className = 'mt-3 p-3 bg-red-50 border border-red-200 rounded-lg';
                                    notice.innerHTML = `
                                        <div class="flex items-center">
                                            <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>
                                            <div>
                                                <p class="text-sm font-semibold text-red-800">Full Day Reserved</p>
                                                <p class="text-xs text-red-600">This date is completely booked for the entire day (8 AM - 6 PM)</p>
                                            </div>
                                        </div>
                                    `;
                                    bookedDiv.appendChild(notice);
                                }
                            }
                            
                            avail.classList.remove('hidden');
                        })
                        .catch(error => {
                            // Hide loading indicator
                            loadingIndicator.classList.add('hidden');
                            
                            // Show error message
                            sessionsDiv.innerHTML = `
                                <div class="text-center py-4 text-red-600">
                                    <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                                    <p class="font-semibold">Error Loading Availability</p>
                                    <p class="text-sm">Unable to check availability. Please try again or contact support.</p>
                                    <button onclick="openBookingModal()" class="mt-2 bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                                        Book Manually
                                    </button>
                                </div>
                            `;
                            console.error('Error fetching availability:', error);
                        });
                }
            }
        });
        
        // Date is set from the calendar picker, no need to initialize flatpickr for hidden field
    });
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
