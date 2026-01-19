<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

// Get user data for the admin
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

$page_title = "Oval Field Reservations Management - CHMSU BAO";
$base_url = "..";

// Handle reservation status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $booking_id = sanitize_input($_POST['booking_id'] ?? '');
    $action = sanitize_input($_POST['action']);
    $admin_remarks = sanitize_input($_POST['admin_remarks'] ?? '');
    
    if (!empty($booking_id)) {
        // Check if reservation exists
        $check_stmt = $conn->prepare("SELECT * FROM bookings WHERE booking_id = ? AND facility_type = 'oval'");
        $check_stmt->bind_param("s", $booking_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $booking = $check_result->fetch_assoc();
            $new_status = '';
            
            switch ($action) {
                case 'approve':
                    $new_status = 'confirmed';
                    break;
                case 'reject':
                    $new_status = 'rejected';
                    break;
                case 'cancel':
                    $new_status = 'cancelled';
                    break;
                default:
                    $_SESSION['error'] = "Invalid action";
                    header("Location: oval_bookings.php");
                    exit();
            }
            
            // Update reservation status
            $update_stmt = $conn->prepare("UPDATE bookings SET status = ?, additional_info = JSON_SET(COALESCE(additional_info, '{}'), '$.admin_remarks', ?) WHERE booking_id = ?");
            $update_stmt->bind_param("sss", $new_status, $admin_remarks, $booking_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success'] = "Reservation has been " . ucfirst($action) . "d successfully.";
                
                // Send notification to user about reservation status change
                require_once '../includes/notification_functions.php';
                $booking_user_id = $booking['user_id'];
                $booking_id_str = $booking['booking_id'];
                $date_formatted = date('F j, Y', strtotime($booking['date']));
                
                if ($new_status === 'confirmed') {
                    create_notification($booking_user_id, "Gym Reservation Approved", "Your gym reservation (ID: {$booking_id_str}) for {$date_formatted} has been approved!", "success", "external/oval.php");
                } elseif ($new_status === 'rejected') {
                    $reason = !empty($admin_remarks) ? " Reason: {$admin_remarks}" : "";
                    create_notification($booking_user_id, "Gym Reservation Rejected", "Your gym reservation (ID: {$booking_id_str}) for {$date_formatted} has been rejected.{$reason}", "error", "external/oval.php");
                }
                
            } else {
                $_SESSION['error'] = "Error updating reservation: " . $conn->error;
            }
        } else {
            $_SESSION['error'] = "Reservation not found";
        }
    } else {
        $_SESSION['error'] = "Invalid reservation ID";
    }
    
    // Redirect to prevent form resubmission
    header("Location: oval_bookings.php");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';
$date_filter = isset($_GET['date']) ? sanitize_input($_GET['date']) : '';
$user_filter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Build query based on filters
$query = "SELECT b.*, u.name as user_name, u.email as user_email, u.organization 
          FROM bookings b
          JOIN user_accounts u ON b.user_id = u.id
          WHERE b.facility_type = 'oval'";

$params = [];
$types = "";

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

if ($user_filter > 0) {
    $query .= " AND b.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

$query .= " ORDER BY b.date DESC, b.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$bookings_result = $stmt->get_result();

// Get external users for filter dropdown
$users_query = "SELECT id, name, email FROM user_accounts WHERE user_type = 'external' ORDER BY name";
$users_result = $conn->query($users_query);
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Oval Field Reservations Management</h1>
                <div class="flex items-center space-x-4">
                    <a href="oval_events.php" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        <i class="fas fa-calendar-times mr-2"></i>
                        Manage Events & Blocked Dates
                    </a>
                    <span class="text-gray-700 mr-2"><?php echo $user_name; ?></span>
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
                
                <!-- Manage Facilities Button -->
                <div class="bg-white rounded-lg shadow p-4 mb-6">
                    <div class="flex justify-end">
                        <a href="gym_management.php" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            <i class="fas fa-cog mr-1"></i> Manage Facilities
                        </a>
                    </div>
                </div>
                
                <!-- Big Calendar (no external dependency) -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <div class="flex items-center justify-between">
                        <div>
                                <h3 class="text-lg font-medium text-gray-900">Oval Field Reservations Calendar</h3>
                                <p class="mt-1 text-sm text-gray-500">Full month view of all gym bookings</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button id="cal-prev" class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 text-sm">← Prev</button>
                                <div id="cal-title" class="text-sm font-semibold w-40 text-center"></div>
                                <button id="cal-next" class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 text-sm">Next →</button>
                                <button id="cal-today" class="ml-2 px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 text-sm">Today</button>
                        </div>
                        </div>
                    </div>
                    <div class="p-4">
                        <div id="simple-calendar" class="w-full" style="min-height: 600px;">
                            <div id="calendar-container">
                                <!-- Calendar will be generated here -->
                                <div id="calendar-loading" class="text-center py-8">
                                    <div class="inline-block h-8 w-8 border-4 border-emerald-200 border-t-emerald-600 rounded-full animate-spin"></div>
                                    <p class="mt-2 text-gray-600">Loading calendar...</p>
                                </div>
                                
                                <!-- Immediate fallback calendar -->
                                <div id="immediate-calendar" style="display: none;">
                                    <?php
                                    $today = new DateTime();
                                    $currentMonth = $today->format('F Y');
                                    $daysInMonth = $today->format('t');
                                    $firstDay = $today->format('w'); // 0 = Sunday
                                    $currentDay = $today->format('j');
                                    ?>
                                    <div class="text-center mb-4">
                                        <h3 class="text-lg font-semibold"><?php echo $currentMonth; ?></h3>
                                    </div>
                                    <div class="grid grid-cols-7 gap-1 bg-gray-200 p-2 rounded">
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">SUN</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">MON</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">TUE</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">WED</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">THU</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">FRI</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">SAT</div>
                                        <?php
                                        // Empty cells for days before month starts
                                        for ($i = 0; $i < $firstDay; $i++) {
                                            echo '<div class="bg-white p-2 text-center text-gray-400"></div>';
                                        }
                                        
                                        // Days of the month
                                        for ($day = 1; $day <= $daysInMonth; $day++) {
                                            $isToday = ($day == $currentDay) ? 'bg-blue-100 border-2 border-blue-500' : 'bg-white';
                                            echo "<div class='{$isToday} p-2 text-center min-h-16 border border-gray-300'>
                                                <div class='font-bold text-lg'>{$day}</div>
                                                <div class='text-xs text-gray-600 mt-1'>No bookings</div>
                                            </div>";
                                        }
                                        ?>
                                    </div>
                                    <div class="text-center mt-4 text-sm text-gray-600">Basic calendar view - Interactive features loading...</div>
                                </div>
                                <noscript>
                                    <div class="text-center py-8 text-gray-600">
                                        <p>JavaScript is required to display the calendar.</p>
                                        <p>Please enable JavaScript in your browser.</p>
                                    </div>
                                </noscript>
                            </div>
                            
                            <!-- Simple fallback calendar -->
                            <div id="fallback-calendar" style="display: none;">
                                <div class="text-center py-4">
                                    <h3 class="text-lg font-semibold mb-4">Current Month Calendar</h3>
                                    <div class="grid grid-cols-7 gap-1 bg-gray-200 p-2 rounded">
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">SUN</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">MON</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">TUE</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">WED</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">THU</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">FRI</div>
                                        <div class="bg-gray-300 p-2 text-center font-bold text-sm">SAT</div>
                                        <!-- Calendar days will be generated here -->
                                    </div>
                                    <p class="text-sm text-gray-600 mt-4">Interactive calendar is loading...</p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-xs text-gray-600">
                            <span class="inline-block w-3 h-3 rounded-sm align-middle" style="background:#10b981"></span> Confirmed
                            <span class="inline-block w-3 h-3 rounded-sm align-middle ml-4" style="background:#fbbf24"></span> Pending
                            <span class="inline-block w-3 h-3 rounded-sm align-middle ml-4" style="background:#ef4444"></span> Rejected
                            <span class="inline-block w-3 h-3 rounded-sm align-middle ml-4" style="background:#6b7280"></span> Cancelled
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- View Reservation Modal -->
<div id="viewModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Reservation Details</h3>
            <button type="button" onclick="closeModal('viewModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mt-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500">Reservation ID</p>
                    <p id="view-booking-id" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Status</p>
                    <p id="view-status" class="text-base"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Date</p>
                    <p id="view-date" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Time</p>
                    <p id="view-time" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">User</p>
                    <p id="view-user" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Attendees</p>
                    <p id="view-attendees" class="text-base text-gray-900"></p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-sm font-medium text-gray-500">Purpose</p>
                    <p id="view-purpose" class="text-base text-gray-900"></p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-sm font-medium text-gray-500">Admin Remarks</p>
                    <p id="view-remarks" class="text-base text-gray-900"></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Created At</p>
                    <p id="view-created" class="text-base text-gray-900"></p>
                </div>
                <div id="additional-info-container" class="md:col-span-2">
                    <p class="text-sm font-medium text-gray-500">Additional Information</p>
                    <div id="additional-info" class="text-base text-gray-900 space-y-2"></div>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="printGymReceipt()" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <i class="fas fa-print mr-2"></i>Print Receipt
                </button>
                <button type="button" onclick="closeModal('viewModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Reservation Modal -->
<div id="approveModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Approve Reservation</h3>
            <button type="button" onclick="closeModal('approveModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form action="oval_bookings.php" method="POST" class="mt-4">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" id="approve-booking-id" name="booking_id" value="">
            
            <div class="mb-4">
                <label for="approve-remarks" class="block text-sm font-medium text-gray-700 mb-1">Remarks (Optional)</label>
                <textarea id="approve-remarks" name="admin_remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500" placeholder="Add any notes or instructions for the user"></textarea>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeModal('approveModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Approve Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Reservation Modal -->
<div id="rejectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Reject Reservation</h3>
            <button type="button" onclick="closeModal('rejectModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form action="oval_bookings.php" method="POST" class="mt-4">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" id="reject-booking-id" name="booking_id" value="">
            
            <div class="mb-4">
                <label for="reject-remarks" class="block text-sm font-medium text-gray-700 mb-1">Reason for Rejection</label>
                <textarea id="reject-remarks" name="admin_remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Explain why the reservation is being rejected" required></textarea>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeModal('rejectModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Reject Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Reservation Modal -->
<div id="cancelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-lg font-medium text-gray-900">Cancel Reservation</h3>
            <button type="button" onclick="closeModal('cancelModal')" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form action="oval_bookings.php" method="POST" class="mt-4">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" id="cancel-booking-id" name="booking_id" value="">
            
            <div class="mb-4">
                <label for="cancel-remarks" class="block text-sm font-medium text-gray-700 mb-1">Reason for Cancellation</label>
                <textarea id="cancel-remarks" name="admin_remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-500" placeholder="Explain why the reservation is being cancelled" required></textarea>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeModal('cancelModal')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Back
                </button>
                <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Cancel Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    /* Simple calendar styling */
    #simple-calendar table {
        border-collapse: collapse;
        width: 100%;
    }
    
    #simple-calendar td {
        border: 1px solid #d1d5db;
        vertical-align: top;
    }
    
    #simple-calendar td:hover {
        background-color: #f9fafb;
    }
    
    /* Custom spinner animation */
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    .animate-spin {
        animation: spin 1s linear infinite;
    }
    
    /* Ensure calendar grid is visible */
    #calendar-container {
        min-height: 500px;
        background: white;
    }
    
    /* Fallback for missing icons */
    .icon-fallback::before {
        content: "📅";
        margin-right: 4px;
    }
  </style>

<script>
    // Mobile menu toggle
    document.getElementById('menu-button').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
    });
    
    // Modal functions
    let currentBookingId = '';
    
    function openViewModal(booking) {
        currentBookingId = booking.booking_id;
        document.getElementById('view-booking-id').textContent = booking.booking_id;
        
        const statusElement = document.getElementById('view-status');
        statusElement.textContent = booking.status.charAt(0).toUpperCase() + booking.status.slice(1);
        
        // Set status color
        statusElement.className = 'text-base';
        if (booking.status === 'pending') {
            statusElement.classList.add('text-yellow-600');
        } else if (booking.status === 'confirmed') {
            statusElement.classList.add('text-green-600');
        } else if (booking.status === 'rejected') {
            statusElement.classList.add('text-red-600');
        } else {
            statusElement.classList.add('text-gray-600');
        }
        
        document.getElementById('view-date').textContent = new Date(booking.date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        document.getElementById('view-time').textContent = formatTime(booking.start_time) + ' - ' + formatTime(booking.end_time);
        document.getElementById('view-user').textContent = booking.user_name + (booking.organization ? ` (${booking.organization})` : '');
        document.getElementById('view-attendees').textContent = booking.attendees;
        document.getElementById('view-purpose').textContent = booking.purpose;
        document.getElementById('view-remarks').textContent = booking.admin_remarks || 'No remarks';
        document.getElementById('view-created').textContent = new Date(booking.created_at).toLocaleString();
        
        // Display additional information if available
        const additionalInfoContainer = document.getElementById('additional-info-container');
        const additionalInfoElement = document.getElementById('additional-info');
        additionalInfoElement.innerHTML = '';
        
        try {
            let additionalInfo = booking.additional_info;
            if (typeof additionalInfo === 'string') {
                additionalInfo = JSON.parse(additionalInfo);
            }
            
            if (additionalInfo && typeof additionalInfo === 'object') {
                let hasInfo = false;
                
                for (const [key, value] of Object.entries(additionalInfo)) {
                    if (key !== 'admin_remarks' && value) {
                        hasInfo = true;
                        const infoItem = document.createElement('p');
                        infoItem.innerHTML = `<span class="font-medium">${formatLabel(key)}:</span> ${value}`;
                        additionalInfoElement.appendChild(infoItem);
                    }
                }
                
                additionalInfoContainer.style.display = hasInfo ? 'block' : 'none';
            } else {
                additionalInfoContainer.style.display = 'none';
            }
        } catch (e) {
            additionalInfoContainer.style.display = 'none';
        }
        
        document.getElementById('viewModal').classList.remove('hidden');
    }
    
    function formatLabel(key) {
        return key.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
    }
    
    function formatTime(timeString) {
        if (!timeString) return '';
        const [hours, minutes] = timeString.split(':');
        const date = new Date();
        date.setHours(hours);
        date.setMinutes(minutes);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    function openApproveModal(bookingId) {
        document.getElementById('approve-booking-id').value = bookingId;
        document.getElementById('approveModal').classList.remove('hidden');
    }
    
    function openRejectModal(bookingId) {
        document.getElementById('reject-booking-id').value = bookingId;
        document.getElementById('rejectModal').classList.remove('hidden');
    }
    
    function openCancelModal(bookingId) {
        document.getElementById('cancel-booking-id').value = bookingId;
        document.getElementById('cancelModal').classList.remove('hidden');
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
    }
    
    function printGymReceipt() {
        if (currentBookingId) {
            window.open('print_gym_receipt.php?booking_id=' + currentBookingId, '_blank');
        } else {
            alert('Booking ID not available');
        }
    }
    // Small date availability calendar (kept)
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('admin-calendar')) {
            flatpickr('#admin-calendar', {
                inline: true,
                minDate: 'today',
                dateFormat: 'Y-m-d',
                onChange: function(selectedDates, dateStr) {
                    if (!dateStr) return;
                    const avail = document.getElementById('admin-availability');
                    const dateSpan = document.getElementById('admin-avail-date');
                    const sessionsDiv = document.getElementById('admin-session-buttons');
                    const bookedDiv = document.getElementById('admin-booked-list');
                    dateSpan.textContent = dateStr;
                    sessionsDiv.innerHTML = '';
                    bookedDiv.innerHTML = '';
                    fetch(`get_oval_availability.php?date=${encodeURIComponent(dateStr)}`)
                        .then(r => r.json())
                        .then(data => {
                            // Check if date is blocked
                            if (data.is_blocked) {
                                const blockedMsg = document.createElement('div');
                                blockedMsg.className = 'p-4 bg-red-50 border-2 border-red-300 rounded-lg text-center';
                                const eventIcon = data.blocked_info?.event_type === 'ceremony' ? '🎓' : 
                                                 data.blocked_info?.event_type === 'intramurals' ? '🏅' : '🚫';
                                blockedMsg.innerHTML = `
                                    <div class="text-4xl mb-2">${eventIcon}</div>
                                    <div class="font-bold text-red-600 mb-1">DATE BLOCKED</div>
                                    <div class="font-semibold text-gray-800">${data.blocked_info?.event_name || 'School Event'}</div>
                                    <div class="text-sm text-gray-600 mt-1">${data.message || ''}</div>
                                `;
                                sessionsDiv.appendChild(blockedMsg);
                                avail.classList.remove('hidden');
                                return;
                            }
                            
                            data.sessions.forEach(s => {
                                const tag = document.createElement('span');
                                tag.className = `px-2 py-1 rounded text-sm ${s.available ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600'}`;
                                tag.textContent = `${s.label}: ${s.available ? 'Available' : 'Booked'}`;
                                sessionsDiv.appendChild(tag);
                            });
                            if (data.booked && data.booked.length) {
                                const title = document.createElement('div');
                                title.textContent = 'Booked intervals:';
                                bookedDiv.appendChild(title);
                                data.booked.forEach(b => {
                                    const item = document.createElement('div');
                                    item.textContent = `• ${b.start.substring(0,5)} - ${b.end.substring(0,5)}`;
                                    bookedDiv.appendChild(item);
                                });
                            } else {
                                const item = document.createElement('div');
                                item.textContent = 'No bookings yet.';
                                bookedDiv.appendChild(item);
                            }
                            avail.classList.remove('hidden');
                        });
                }
            });
        }
    });

    // Lightweight month calendar rendering (no external libs)
    console.log('Script starting...');
    
    function initCalendar() {
        console.log('Initializing calendar...');
        const container = document.getElementById('simple-calendar');
        if (!container) {
            console.log('Calendar container not found');
            return;
        }
        console.log('Container found:', container);
        
        const titleEl = document.getElementById('cal-title');
        const btnPrev = document.getElementById('cal-prev');
        const btnNext = document.getElementById('cal-next');
        const btnToday = document.getElementById('cal-today');

        let current = new Date();
        current.setDate(1);

        function formatDateISO(d) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }

        function getMonthDays(year, month) {
            const firstDay = new Date(year, month, 1);
            const startWeekday = firstDay.getDay(); // 0-6 Sun-Sat
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const prevMonthDays = new Date(year, month, 0).getDate();

            const cells = [];
            // Leading days from previous month
            for (let i = startWeekday - 1; i >= 0; i--) {
                const day = prevMonthDays - i;
                cells.push({ date: new Date(year, month - 1, day), other: true });
            }
            // Current month
            for (let d = 1; d <= daysInMonth; d++) {
                cells.push({ date: new Date(year, month, d), other: false });
            }
            // Trailing days to fill 6 rows x 7 cols = 42
            while (cells.length % 7 !== 0 || cells.length < 42) {
                const last = cells[cells.length - 1].date;
                const next = new Date(last);
                next.setDate(last.getDate() + 1);
                cells.push({ date: next, other: true });
            }
            return cells;
        }

        function render() {
            console.log('Rendering calendar...');
            const year = current.getFullYear();
            const month = current.getMonth();
            const monthName = current.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
            if (titleEl) titleEl.textContent = monthName;

            // Hide loading indicator
            const loadingEl = document.getElementById('calendar-loading');
            if (loadingEl) loadingEl.style.display = 'none';

            // Build simple calendar with divs
            const cells = getMonthDays(year, month);
            const todayISO = formatDateISO(new Date());
            const container = document.getElementById('calendar-container');
            
            if (!container) {
                console.error('Calendar container not found');
                return;
            }
            
            let html = `
                <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e5e7eb; border: 1px solid #e5e7eb;">
                    <div style="padding: 10px; background: #f3f4f6; text-align: center; font-weight: bold; border: 1px solid #d1d5db;">SUN</div>
                    <div style="padding: 10px; background: #f3f4f6; text-align: center; font-weight: bold; border: 1px solid #d1d5db;">MON</div>
                    <div style="padding: 10px; background: #f3f4f6; text-align: center; font-weight: bold; border: 1px solid #d1d5db;">TUE</div>
                    <div style="padding: 10px; background: #f3f4f6; text-align: center; font-weight: bold; border: 1px solid #d1d5db;">WED</div>
                    <div style="padding: 10px; background: #f3f4f6; text-align: center; font-weight: bold; border: 1px solid #d1d5db;">THU</div>
                    <div style="padding: 10px; background: #f3f4f6; text-align: center; font-weight: bold; border: 1px solid #d1d5db;">FRI</div>
                    <div style="padding: 10px; background: #f3f4f6; text-align: center; font-weight: bold; border: 1px solid #d1d5db;">SAT</div>
                </div>
            `;
            
            html += '<div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e5e7eb;">';
            
            cells.forEach(cell => {
                const iso = formatDateISO(cell.date);
                const isToday = iso === todayISO;
                const dayNumber = cell.date.getDate();
                const opacity = cell.other ? '0.5' : '1';
                const todayStyle = isToday ? 'background: #fef3c7; border: 2px solid #f59e0b;' : '';
                
                html += `
                    <div style="background: white; padding: 8px; min-height: 100px; border: 1px solid #d1d5db; position: relative; opacity: ${opacity}; ${todayStyle}" data-date="${iso}">
                        <div style="position: absolute; top: 5px; right: 5px; font-size: 16px; font-weight: bold; color: #374151; background: #f9fafb; padding: 4px 8px; border-radius: 4px; border: 1px solid #d1d5db; min-width: 24px; text-align: center;">${dayNumber}</div>
                        <div style="margin-top: 40px; font-size: 11px;" id="events-${iso}"></div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;

            // Fetch and render events + availability for visible month range
            const startISO = formatDateISO(cells[0].date);
            const endISO = formatDateISO(cells[cells.length - 1].date);
            
            // Load both events and availability for each day
            const daysToCheck = [];
            cells.forEach(c => {
                if (!c.other) { // Only current month days
                    const iso = formatDateISO(c.date);
                    daysToCheck.push(iso);
                }
            });
            
            // Load events
            fetch(`get_oval_events.php?start=${encodeURIComponent(startISO)}&end=${encodeURIComponent(endISO)}`)
                .then(r => r.json())
                .then(events => {
                    events.forEach(ev => {
                        const dayId = `events-${ev.start.substring(0,10)}`;
                        const slot = document.getElementById(dayId);
                        if (!slot) return;
                        const chip = document.createElement('div');
                        chip.className = 'px-1 py-0.5 rounded border mb-1';
                        chip.style.borderColor = ev.color || '#6b7280';
                        chip.style.color = '#111827';
                        chip.style.backgroundColor = ev.color || '#f3f4f6';
                        const startTime = new Date(ev.start).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                        const endTime = ev.end ? new Date(ev.end).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '';
                        chip.textContent = `${startTime}${endTime ? ' - ' + endTime : ''} ${ev.title}`;
                        slot.appendChild(chip);
                    });
                })
                .catch(() => {});
            
            // Check for whole day bookings and mark as "Fully Booked" ONLY if truly fully booked
            daysToCheck.forEach(dayISO => {
                fetch(`get_oval_availability.php?date=${encodeURIComponent(dayISO)}`)
                    .then(r => r.json())
                    .then(data => {
                        const dayId = `events-${dayISO}`;
                        const slot = document.getElementById(dayId);
                        if (!slot) return;
                        
                        // Check if date is blocked by school event
                        if (data.is_blocked) {
                            slot.innerHTML = '';
                            const blockedChip = document.createElement('div');
                            blockedChip.className = 'px-2 py-1 rounded text-xs mb-1 text-center font-bold';
                            const eventIcon = data.blocked_info?.event_type === 'ceremony' ? '🎓' : 
                                             data.blocked_info?.event_type === 'intramurals' ? '🏅' : '🚫';
                            blockedChip.style.backgroundColor = '#7c2d12';
                            blockedChip.style.color = '#ffffff';
                            blockedChip.style.border = '2px solid #ea580c';
                            blockedChip.textContent = `${eventIcon} BLOCKED`;
                            slot.appendChild(blockedChip);
                            
                            // Add event name label below
                            const eventLabel = document.createElement('div');
                            eventLabel.className = 'text-xs text-center font-semibold px-1';
                            eventLabel.style.color = '#7c2d12';
                            eventLabel.style.lineHeight = '1.2';
                            eventLabel.textContent = data.blocked_info?.event_name || '';
                            slot.appendChild(eventLabel);
                            
                            const dayCell = slot.closest('[data-date]');
                            if (dayCell) {
                                dayCell.style.backgroundColor = '#fed7aa';
                            }
                            return;
                        }
                        
                        // Check if there's a whole day booking (8 AM - 6 PM) AND no other sessions are available
                        const hasWholeDayBooking = data.sessions && data.sessions.some(session => 
                            session.type === 'whole_day' && !session.available
                        );
                        
                        // Check if there are any available sessions (excluding whole day)
                        const hasAvailableSessions = data.sessions && data.sessions.some(session => 
                            session.type !== 'whole_day' && session.available
                        );
                        
                        // Only show "FULLY BOOKED" if there's a whole day booking AND no other sessions are available
                        if (hasWholeDayBooking && !hasAvailableSessions) {
                            // Clear existing content and show "Fully Booked"
                            slot.innerHTML = '';
                            const fullyBookedChip = document.createElement('div');
                            fullyBookedChip.className = 'px-2 py-1 rounded text-xs mb-1 text-center font-bold';
                            fullyBookedChip.style.backgroundColor = '#dc2626';
                            fullyBookedChip.style.color = '#ffffff';
                            fullyBookedChip.style.border = '1px solid #b91c1c';
                            fullyBookedChip.textContent = 'FULLY BOOKED';
                            slot.appendChild(fullyBookedChip);
                            
                            // Also change the day cell background to red
                            const dayCell = slot.closest('[data-date]');
                            if (dayCell) {
                                dayCell.style.backgroundColor = '#fef2f2';
                                dayCell.style.borderColor = '#fca5a5';
                            }
                        }
                    })
                    .catch(err => {
                        console.log('Error checking whole day booking for', dayISO, ':', err);
                    });
            });
            
            // Load availability for each day
            daysToCheck.forEach(dayISO => {
                fetch(`get_oval_availability.php?date=${encodeURIComponent(dayISO)}`)
                    .then(r => r.json())
                    .then(data => {
                        // Skip if date is blocked
                        if (data.is_blocked) {
                            return; // already marked above as BLOCKED
                        }
                        
                        // If this day is effectively fully booked (whole day booked and no partial sessions available),
                        // skip rendering any "Available" chips to avoid contradictions
                        const hasWholeDayBooking = data.sessions && data.sessions.some(session => 
                            session.type === 'whole_day' && !session.available
                        );
                        const hasAvailableSessions = data.sessions && data.sessions.some(session => 
                            session.type !== 'whole_day' && session.available
                        );
                        if (hasWholeDayBooking && !hasAvailableSessions) {
                            return; // already marked above as FULLY BOOKED
                        }
                        console.log('Availability data for', dayISO, ':', data);
                        const dayId = `events-${dayISO}`;
                        const slot = document.getElementById(dayId);
                        if (!slot) {
                            console.log('Slot not found for', dayId);
                            return;
                        }
                        
                        // Add available time slots
                        if (data.booked && data.booked.length > 0) {
                            // Calculate free time slots between bookings, respecting lunch break (11:30-13:00)
                            const dayStart = '08:00:00';
                            const lunchStart = '11:30:00';
                            const lunchEnd = '13:00:00';
                            const dayEnd = '18:00:00';
                            const bookedTimes = data.booked.map(b => ({
                                start: b.start,
                                end: b.end
                            })).sort((a, b) => a.start.localeCompare(b.start));
                            
                            let currentTime = dayStart;
                            const freeSlots = [];
                            
                            bookedTimes.forEach(booking => {
                                if (currentTime < booking.start) {
                                    // Split available window if it crosses lunch break
                                    if (currentTime < lunchStart && booking.start > lunchStart) {
                                        // Add morning slot (before lunch)
                                        if (currentTime < lunchStart) {
                                            freeSlots.push({ start: currentTime, end: lunchStart });
                                        }
                                        // Add afternoon slot (after lunch) if booking starts after lunch
                                        if (booking.start > lunchEnd) {
                                            freeSlots.push({ start: lunchEnd, end: booking.start });
                                        }
                                    } else if (currentTime >= lunchEnd || booking.start <= lunchStart) {
                                        // Normal window that doesn't cross lunch
                                        freeSlots.push({ start: currentTime, end: booking.start });
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
                                    freeSlots.push({ start: currentTime, end: lunchStart });
                                    freeSlots.push({ start: lunchEnd, end: dayEnd });
                                } else if (currentTime >= lunchEnd) {
                                    freeSlots.push({ start: currentTime, end: dayEnd });
                                } else if (currentTime >= lunchStart && currentTime < lunchEnd) {
                                    freeSlots.push({ start: lunchEnd, end: dayEnd });
                                }
                            }
                            
                            // Display free slots that are at least 1 hour
                            freeSlots.forEach(freeSlot => {
                                const [startHr, startMin] = freeSlot.start.split(':').map(Number);
                                const [endHr, endMin] = freeSlot.end.split(':').map(Number);
                                const diffMinutes = (endHr * 60 + endMin) - (startHr * 60 + startMin);
                                
                                if (diffMinutes >= 60) {
                                    const availChip = document.createElement('div');
                                    availChip.className = 'px-1 py-0.5 rounded text-xs mb-1';
                                    availChip.style.backgroundColor = '#dcfce7';
                                    availChip.style.color = '#166534';
                                    availChip.style.border = '1px solid #bbf7d0';
                                    availChip.textContent = `Available: ${freeSlot.start.substring(0,5)} - ${freeSlot.end.substring(0,5)}`;
                                    slot.appendChild(availChip);
                                }
                            });
                        } else {
                            // No bookings - show morning and afternoon slots separately
                            const morningChip = document.createElement('div');
                            morningChip.className = 'px-1 py-0.5 rounded text-xs mb-1';
                            morningChip.style.backgroundColor = '#dcfce7';
                            morningChip.style.color = '#166534';
                            morningChip.style.border = '1px solid #bbf7d0';
                            morningChip.textContent = 'Available: 08:00 - 11:30';
                            slot.appendChild(morningChip);
                            
                            const afternoonChip = document.createElement('div');
                            afternoonChip.className = 'px-1 py-0.5 rounded text-xs mb-1';
                            afternoonChip.style.backgroundColor = '#dcfce7';
                            afternoonChip.style.color = '#166534';
                            afternoonChip.style.border = '1px solid #bbf7d0';
                            afternoonChip.textContent = 'Available: 13:00 - 18:00';
                            slot.appendChild(afternoonChip);
                        }
                    })
                    .catch(err => {
                        console.log('Error fetching availability for', dayISO, ':', err);
                    });
            });
        }

        if (btnPrev) btnPrev.addEventListener('click', () => { current.setMonth(current.getMonth() - 1); render(); });
        if (btnNext) btnNext.addEventListener('click', () => { current.setMonth(current.getMonth() + 1); render(); });
        if (btnToday) btnToday.addEventListener('click', () => { const d = new Date(); current = new Date(d.getFullYear(), d.getMonth(), 1); render(); });

        console.log('About to render calendar...');
        render();
        console.log('Calendar rendered');
    }
    
    // Try multiple initialization methods
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCalendar);
    } else {
        initCalendar();
    }
    
    // Show immediate calendar right away
    setTimeout(() => {
        const loadingEl = document.getElementById('calendar-loading');
        const immediateCal = document.getElementById('immediate-calendar');
        const container = document.getElementById('calendar-container');
        
        if (loadingEl && loadingEl.style.display !== 'none') {
            console.log('Showing immediate calendar fallback');
            if (immediateCal) {
                loadingEl.style.display = 'none';
                immediateCal.style.display = 'block';
            }
        }
    }, 500);
    
    // Immediate simple calendar as backup
    setTimeout(() => {
        const loadingEl = document.getElementById('calendar-loading');
        const container = document.getElementById('calendar-container');
        if (loadingEl && loadingEl.style.display !== 'none') {
            console.log('Showing simple calendar fallback');
            const today = new Date();
            const currentMonth = today.getMonth();
            const currentYear = today.getFullYear();
            const monthName = today.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
            
            // Generate simple calendar
            let calendarHTML = `
                <div class="text-center mb-4">
                    <h3 class="text-lg font-semibold">${monthName}</h3>
                </div>
                <div class="grid grid-cols-7 gap-1 bg-gray-200 p-2 rounded">
                    <div class="bg-gray-300 p-2 text-center font-bold text-sm">SUN</div>
                    <div class="bg-gray-300 p-2 text-center font-bold text-sm">MON</div>
                    <div class="bg-gray-300 p-2 text-center font-bold text-sm">TUE</div>
                    <div class="bg-gray-300 p-2 text-center font-bold text-sm">WED</div>
                    <div class="bg-gray-300 p-2 text-center font-bold text-sm">THU</div>
                    <div class="bg-gray-300 p-2 text-center font-bold text-sm">FRI</div>
                    <div class="bg-gray-300 p-2 text-center font-bold text-sm">SAT</div>
            `;
            
            // Get first day of month and number of days
            const firstDay = new Date(currentYear, currentMonth, 1);
            const lastDay = new Date(currentYear, currentMonth + 1, 0);
            const daysInMonth = lastDay.getDate();
            const startDay = firstDay.getDay();
            
            // Add empty cells for days before month starts
            for (let i = 0; i < startDay; i++) {
                calendarHTML += '<div class="bg-white p-2 text-center text-gray-400"></div>';
            }
            
            // Add days of month
            for (let day = 1; day <= daysInMonth; day++) {
                const isToday = day === today.getDate() && currentMonth === today.getMonth() && currentYear === today.getFullYear();
                const todayClass = isToday ? 'bg-blue-100 border-2 border-blue-500' : 'bg-white';
                calendarHTML += `<div class="${todayClass} p-2 text-center min-h-16 border border-gray-300">
                    <div class="font-bold text-lg">${day}</div>
                    <div class="text-xs text-gray-600 mt-1">No bookings</div>
                </div>`;
            }
            
            calendarHTML += '</div>';
            calendarHTML += '<div class="text-center mt-4 text-sm text-gray-600">Simple calendar view - Interactive features loading...</div>';
            
            if (container) {
                container.innerHTML = calendarHTML;
            }
        }
    }, 1000);
    
    // Fallback: if calendar doesn't load within 3 seconds, show error
    setTimeout(() => {
        const loadingEl = document.getElementById('calendar-loading');
        const container = document.getElementById('calendar-container');
        if (loadingEl && loadingEl.style.display !== 'none' && container) {
            console.log('Calendar timeout - showing error');
            container.innerHTML = `
                <div class="text-center py-8 text-red-600">
                    <div class="text-4xl mb-4">⚠️</div>
                    <p class="text-lg font-semibold">Calendar failed to load</p>
                    <p class="text-sm">Please refresh the page or check your internet connection.</p>
                    <button onclick="location.reload()" class="mt-4 bg-emerald-600 text-white px-4 py-2 rounded hover:bg-emerald-700">
                        Refresh Page
                    </button>
                </div>
            `;
        }
    }, 3000);
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
