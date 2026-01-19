<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is an external user
require_external();

// Get user data for the external user
$user_id = $_SESSION['user_sessions']['external']['user_id'];
$user_name = $_SESSION['user_sessions']['external']['user_name'];

$page_title = "My Gym Reservations - CHMSU BAO";
$base_url = "..";

// Get user profile picture
$user_stmt = $conn->prepare("SELECT profile_pic FROM user_accounts WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$profile_pic = $user_data['profile_pic'] ?? '';

// Handle booking cancellation
if (isset($_POST['action']) && $_POST['action'] === 'cancel' && isset($_POST['booking_id'])) {
    $booking_id = (int)$_POST['booking_id'];
    
    // Check if the booking belongs to the user and is in a cancellable state
    $check_stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND user_id = ? AND status IN ('pending', 'confirmed') AND date > CURDATE() AND facility_type = 'gym'");
    $check_stmt->bind_param("ii", $booking_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Update booking status to cancelled
        $update_stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $booking_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Your reservation has been cancelled successfully.";
        } else {
            $_SESSION['error'] = "Error cancelling reservation: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "Invalid reservation or you cannot cancel this reservation.";
    }
    
    // Redirect to prevent form resubmission
    header("Location: gym_bookings.php");
    exit();
}

// Handle booking reschedule
if (isset($_POST['action']) && $_POST['action'] === 'reschedule' && isset($_POST['booking_id'])) {
    $booking_id = (int)$_POST['booking_id'];
    $new_date = sanitize_input($_POST['new_date']);
    $new_start_time = sanitize_input($_POST['new_start_time']);
    $new_end_time = sanitize_input($_POST['new_end_time']);
    
    // Validate dates
    if (empty($new_date) || empty($new_start_time) || empty($new_end_time)) {
        $_SESSION['error'] = "Please fill in all required fields.";
        header("Location: gym_bookings.php");
        exit();
    }
    
    // Check if the booking belongs to the user and is in a reschedulable state
    $check_stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND user_id = ? AND status IN ('pending', 'confirmed') AND date >= CURDATE() AND facility_type = 'gym'");
    $check_stmt->bind_param("ii", $booking_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $booking = $check_result->fetch_assoc();
        $facility_id = $booking['facility_id'];
        
        // Check if new date/time slot is available
        $availability_check = $conn->prepare("SELECT * FROM bookings WHERE facility_id = ? AND date = ? AND id != ? AND status IN ('pending', 'confirmed') AND ((start_time <= ? AND end_time > ?) OR (start_time < ? AND end_time >= ?) OR (start_time >= ? AND end_time <= ?))");
        $availability_check->bind_param("isisssssss", $facility_id, $new_date, $booking_id, $new_start_time, $new_start_time, $new_end_time, $new_end_time, $new_start_time, $new_end_time);
        $availability_check->execute();
        $availability_result = $availability_check->get_result();
        
        if ($availability_result->num_rows > 0) {
            $_SESSION['error'] = "The selected date and time slot is already booked. Please choose another time.";
        } else {
            // Check if date is blocked by school event
            $blocked_check = $conn->prepare("SELECT * FROM gym_blocked_dates WHERE ? BETWEEN start_date AND end_date AND is_active = 1 AND (blocked_for_user_types = 'all' OR blocked_for_user_types = 'external')");
            $blocked_check->bind_param("s", $new_date);
            $blocked_check->execute();
            $blocked_result = $blocked_check->get_result();
            
            if ($blocked_result->num_rows > 0) {
                $_SESSION['error'] = "The selected date is blocked due to a school event. Please choose another date.";
            } else {
                // Update booking
                $update_stmt = $conn->prepare("UPDATE bookings SET date = ?, start_time = ?, end_time = ?, status = 'pending', updated_at = NOW() WHERE id = ?");
                $update_stmt->bind_param("sssi", $new_date, $new_start_time, $new_end_time, $booking_id);
                
                if ($update_stmt->execute()) {
                    $_SESSION['success'] = "Your reservation has been rescheduled successfully. It will need to be re-approved by the admin.";
                } else {
                    $_SESSION['error'] = "Error rescheduling reservation: " . $conn->error;
                }
            }
        }
    } else {
        $_SESSION['error'] = "Invalid reservation or you cannot reschedule this reservation.";
    }
    
    // Redirect to prevent form resubmission
    header("Location: gym_bookings.php");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$date_filter = isset($_GET['date']) ? sanitize_input($_GET['date']) : '';

// Build query based on filters
$query = "SELECT b.*, f.name as facility_name 
          FROM bookings b
          JOIN gym_facilities f ON b.facility_id = f.id
          WHERE b.user_id = ? AND b.facility_type = 'gym'";

$params = [$user_id];
$types = "i";

if (!empty($status_filter)) {
    $query .= " AND b.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_filter)) {
    $query .= " AND b.date = ?";
    $params[] = $date_filter;
    $types .= "s";
}

$query .= " ORDER BY b.date DESC, b.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bookings_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="flex h-screen bg-gray-100">
        <!-- Sidebar -->
        <?php include '../includes/external_sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Navigation -->
            <?php include '../includes/header.php'; ?>
            
            <!-- Top header with profile image -->
            <header class="bg-white shadow-sm z-10">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                    <h1 class="text-2xl font-semibold text-gray-900">My Gym Reservations</h1>
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
                        <span class="text-gray-700 hidden sm:inline"><?php echo htmlspecialchars($user_name); ?></span>
                        <button class="md:hidden rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-amber-500" id="menu-button">
                            <span class="sr-only">Open menu</span>
                            <i class="fas fa-bars"></i>
                        </button>
                    </div>
                </div>
            </header>
            
            <!-- Main Content -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-6">
                <h1 class="text-2xl font-semibold text-gray-800 mb-6">My Gym Reservations</h1>
                
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
                
                <!-- Quick Status Filter Buttons -->
                <div class="mb-4 flex flex-wrap gap-2">
                    <a href="gym_bookings.php" 
                       class="px-4 py-2 rounded-lg <?php echo empty($status_filter) ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-list mr-2"></i>All
                    </a>
                    <a href="?status=pending" 
                       class="px-4 py-2 rounded-lg <?php echo $status_filter === 'pending' ? 'bg-yellow-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-clock mr-2"></i>Pending
                    </a>
                    <a href="?status=confirmed" 
                       class="px-4 py-2 rounded-lg <?php echo $status_filter === 'confirmed' ? 'bg-green-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-check-circle mr-2"></i>Approved
                    </a>
                    <a href="?status=rejected" 
                       class="px-4 py-2 rounded-lg <?php echo $status_filter === 'rejected' ? 'bg-red-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-times-circle mr-2"></i>Rejected
                    </a>
                    <a href="?status=cancelled" 
                       class="px-4 py-2 rounded-lg <?php echo $status_filter === 'cancelled' ? 'bg-gray-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?> shadow transition-colors">
                        <i class="fas fa-ban mr-2"></i>Cancelled
                    </a>
                </div>
                
                <!-- Advanced Filters -->
                <div class="bg-white rounded-lg shadow p-4 mb-6">
                    <form action="gym_bookings.php" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <label for="date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input type="date" id="date" name="date" value="<?php echo $date_filter; ?>" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                <i class="fas fa-filter mr-1"></i> Filter
                            </button>
                            <a href="gym_bookings.php" class="ml-2 bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                                <i class="fas fa-sync-alt mr-1"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Bookings Table -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <h3 class="text-lg font-medium text-gray-900">My Reservations</h3>
                        <p class="mt-1 text-sm text-gray-500">View and manage your gym facility reservations</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purpose</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($bookings_result->num_rows > 0): ?>
                                    <?php while ($booking = $bookings_result->fetch_assoc()): ?>
                                        <?php 
                                        $status_class = '';
                                        $status_text = ucfirst($booking['status']);
                                        
                                        switch ($booking['status']) {
                                            case 'pending':
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                break;
                                            case 'confirmed':
                                                $status_class = 'bg-green-100 text-green-800';
                                                $status_text = 'Approved';
                                                break;
                                            case 'rejected':
                                                $status_class = 'bg-red-100 text-red-800';
                                                break;
                                            case 'cancelled':
                                                $status_class = 'bg-gray-100 text-gray-800';
                                                break;
                                        }
                                        
                                        // Check if booking can be cancelled or rescheduled
                                        $can_cancel = false;
                                        $can_reschedule = false;
                                        $booking_date = new DateTime($booking['date']);
                                        $today = new DateTime();
                                        $today->setTime(0, 0, 0);
                                        
                                        if (($booking['status'] === 'pending' || $booking['status'] === 'confirmed') && $booking_date >= $today) {
                                            $can_cancel = true;
                                            $can_reschedule = true;
                                        }
                                        ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $booking['facility_name']; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('F j, Y', strtotime($booking['date'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $booking['time_slot']; ?></td>
                                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo $booking['purpose']; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo !empty($booking['admin_remarks']) ? $booking['admin_remarks'] : '-'; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <div class="flex justify-end gap-2">
                                                    <?php if ($can_reschedule): ?>
                                                        <button onclick="openRescheduleModal(<?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                                class="text-blue-600 hover:text-blue-900" title="Reschedule">
                                                            <i class="fas fa-calendar-alt"></i> Reschedule
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($can_cancel): ?>
                                                        <form method="POST" action="gym_bookings.php" class="inline">
                                                            <input type="hidden" name="action" value="cancel">
                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                            <button type="submit" onclick="return confirm('Are you sure you want to cancel this reservation?')" class="text-red-600 hover:text-red-900" title="Cancel">
                                                                <i class="fas fa-times"></i> Cancel
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (!$can_reschedule && !$can_cancel): ?>
                                                        <span class="text-gray-400">-</span>
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
                </div>
            </main>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('menu-button')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        });
    </script>
</body>
</html>
