<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

$page_title = "Gym Events & Blocked Dates - CHMSU BAO";
$base_url = "..";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize_input($_POST['action'] ?? '');
    
    if ($action === 'add_event') {
        $event_name = sanitize_input($_POST['event_name']);
        $event_type = sanitize_input($_POST['event_type']);
        $start_date = sanitize_input($_POST['start_date']);
        $end_date = sanitize_input($_POST['end_date']);
        $description = sanitize_input($_POST['description']);
        $blocked_for = sanitize_input($_POST['blocked_for_user_types']);
        $affected_facilities = sanitize_input($_POST['affected_facilities'] ?? 'all');
        $created_by = $_SESSION['user_id'];
        
        $stmt = $conn->prepare("INSERT INTO gym_blocked_dates (event_name, event_type, start_date, end_date, description, affected_facilities, blocked_for_user_types, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssi", $event_name, $event_type, $start_date, $end_date, $description, $affected_facilities, $blocked_for, $created_by);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Event added successfully and dates blocked!";
        } else {
            $_SESSION['error'] = "Error adding event: " . $conn->error;
        }
        header("Location: gym_events.php");
        exit();
    }
    
    if ($action === 'delete_event') {
        $event_id = (int)$_POST['event_id'];
        $stmt = $conn->prepare("DELETE FROM gym_blocked_dates WHERE id = ?");
        $stmt->bind_param("i", $event_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Event deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting event: " . $conn->error;
        }
        header("Location: gym_events.php");
        exit();
    }
    
    if ($action === 'toggle_event') {
        $event_id = (int)$_POST['event_id'];
        $is_active = (int)$_POST['is_active'];
        $stmt = $conn->prepare("UPDATE gym_blocked_dates SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $is_active, $event_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = $is_active ? "Event activated!" : "Event deactivated!";
        } else {
            $_SESSION['error'] = "Error updating event: " . $conn->error;
        }
        header("Location: gym_events.php");
        exit();
    }
    
    if ($action === 'reschedule_event') {
        $event_id = (int)$_POST['event_id'];
        $event_name = sanitize_input($_POST['event_name']);
        $event_type = sanitize_input($_POST['event_type']);
        $start_date = sanitize_input($_POST['start_date']);
        $end_date = sanitize_input($_POST['end_date']);
        $description = sanitize_input($_POST['description']);
        $blocked_for = sanitize_input($_POST['blocked_for_user_types']);
        $affected_facilities = sanitize_input($_POST['affected_facilities'] ?? 'all');
        
        // Validate dates
        if ($start_date > $end_date) {
            $_SESSION['error'] = "End date must be after start date.";
            header("Location: gym_events.php");
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE gym_blocked_dates SET event_name = ?, event_type = ?, start_date = ?, end_date = ?, description = ?, affected_facilities = ?, blocked_for_user_types = ? WHERE id = ?");
        $stmt->bind_param("sssssssi", $event_name, $event_type, $start_date, $end_date, $description, $affected_facilities, $blocked_for, $event_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Event rescheduled successfully!";
        } else {
            $_SESSION['error'] = "Error rescheduling event: " . $conn->error;
        }
        header("Location: gym_events.php");
        exit();
    }
    
    if ($action === 'update_rules') {
        $allowed_months = sanitize_input($_POST['allowed_months']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE gym_booking_rules SET allowed_months = ?, is_active = ? WHERE user_type = 'external'");
        $stmt->bind_param("si", $allowed_months, $is_active);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Booking rules updated successfully!";
        } else {
            $_SESSION['error'] = "Error updating rules: " . $conn->error;
        }
        header("Location: gym_events.php");
        exit();
    }
}

// Get all blocked dates/events
$events_query = "SELECT gbd.*, ua.name as created_by_name 
                 FROM gym_blocked_dates gbd 
                 LEFT JOIN user_accounts ua ON gbd.created_by = ua.id 
                 ORDER BY gbd.start_date DESC";
$events_result = $conn->query($events_query);

// Get booking rules for external users
$rules_query = "SELECT * FROM gym_booking_rules WHERE user_type = 'external' LIMIT 1";
$rules_result = $conn->query($rules_query);
$rules = $rules_result->fetch_assoc();

// Get gym facilities for dropdown
$facilities_query = "SELECT * FROM gym_facilities WHERE status = 'active'";
$facilities_result = $conn->query($facilities_query);
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Gym Events & Blocked Dates</h1>
                <div class="flex items-center space-x-4">
                    <a href="gym_bookings.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Bookings
                    </a>
                    <span class="text-gray-700"><?php echo $_SESSION['user_name']; ?></span>
                </div>
            </div>
        </header>
        
        <!-- Main content -->
        <main class="flex-1 overflow-y-auto p-4">
            <div class="max-w-7xl mx-auto">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                        <p><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                        <p><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Booking Rules for External Users -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">
                        <i class="fas fa-calendar-check text-blue-600 mr-2"></i>
                        External User Booking Rules
                    </h2>
                    <form method="POST" action="gym_events.php">
                        <input type="hidden" name="action" value="update_rules">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Allowed Months for External Users</label>
                                <p class="text-sm text-gray-500 mb-2">Select which months external users can book the gym</p>
                                <div class="grid grid-cols-3 gap-2">
                                    <?php 
                                    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                    $allowed = isset($rules['allowed_months']) ? explode(',', $rules['allowed_months']) : [];
                                    foreach ($months as $index => $month) {
                                        $month_num = $index + 1;
                                        $checked = in_array($month_num, $allowed) ? 'checked' : '';
                                        echo "<label class='flex items-center'>
                                                <input type='checkbox' name='allowed_months[]' value='$month_num' $checked class='rounded border-gray-300 text-blue-600 mr-2'>
                                                <span class='text-sm'>$month</span>
                                              </label>";
                                    }
                                    ?>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Rule Status</label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_active" <?php echo ($rules['is_active'] ?? 1) ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 mr-2">
                                    <span class="text-sm">Active (enforce booking restrictions)</span>
                                </label>
                                <p class="text-sm text-gray-500 mt-2">
                                    <strong>Current Setting:</strong> 
                                    <?php if (!empty($allowed)): ?>
                                        External users can book from 
                                        <strong class="text-blue-600">
                                            <?php echo implode(', ', array_map(function($m) use ($months) { return $months[$m-1]; }, $allowed)); ?>
                                        </strong>
                                    <?php else: ?>
                                        No restrictions
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700">
                            <i class="fas fa-save mr-2"></i>Update Rules
                        </button>
                    </form>
                </div>
                
                <!-- Add New Event Form -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">
                        <i class="fas fa-plus-circle text-green-600 mr-2"></i>
                        Add School Event / Block Dates
                    </h2>
                    <form method="POST" action="gym_events.php">
                        <input type="hidden" name="action" value="add_event">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="event_name" class="block text-sm font-medium text-gray-700 mb-1">Event Name *</label>
                                <input type="text" id="event_name" name="event_name" required 
                                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                       placeholder="e.g., Pinning Ceremony, Intramurals">
                            </div>
                            <div>
                                <label for="event_type" class="block text-sm font-medium text-gray-700 mb-1">Event Type *</label>
                                <select id="event_type" name="event_type" required 
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                                    <option value="ceremony">Ceremony</option>
                                    <option value="intramurals">Intramurals</option>
                                    <option value="school_event">School Event</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
                                <input type="date" id="start_date" name="start_date" required 
                                       min="<?php echo date('Y-m-d'); ?>"
                                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date *</label>
                                <input type="date" id="end_date" name="end_date" required 
                                       min="<?php echo date('Y-m-d'); ?>"
                                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="blocked_for_user_types" class="block text-sm font-medium text-gray-700 mb-1">Block For *</label>
                                <select id="blocked_for_user_types" name="blocked_for_user_types" required 
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                                    <option value="external">External Users Only</option>
                                    <option value="all">All Users (School Priority)</option>
                                    <option value="student">Students Only</option>
                                    <option value="staff">Staff Only</option>
                                </select>
                            </div>
                            <div>
                                <label for="affected_facilities" class="block text-sm font-medium text-gray-700 mb-1">Affected Facilities</label>
                                <input type="text" id="affected_facilities" value="All Facilities" readonly
                                       class="w-full rounded-md border-gray-300 shadow-sm bg-gray-100 cursor-not-allowed"
                                       style="background-color: #f3f4f6;">
                                <input type="hidden" name="affected_facilities" value="all">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea id="description" name="description" rows="2" 
                                      class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"
                                      placeholder="Additional details about the event"></textarea>
                        </div>
                        <button type="submit" class="bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700">
                            <i class="fas fa-plus mr-2"></i>Add Event
                        </button>
                    </form>
                </div>
                
                <!-- Events List -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-calendar-times text-red-600 mr-2"></i>
                            Blocked Dates & School Events
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Blocked For</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($events_result->num_rows > 0): ?>
                                    <?php while ($event = $events_result->fetch_assoc()): ?>
                                        <tr class="<?php echo $event['is_active'] ? '' : 'bg-gray-50 opacity-50'; ?>">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($event['event_name']); ?></div>
                                                <?php if ($event['description']): ?>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars(substr($event['description'], 0, 50)); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                    <?php 
                                                    switch($event['event_type']) {
                                                        case 'ceremony': echo 'bg-purple-100 text-purple-800'; break;
                                                        case 'intramurals': echo 'bg-orange-100 text-orange-800'; break;
                                                        case 'maintenance': echo 'bg-yellow-100 text-yellow-800'; break;
                                                        default: echo 'bg-blue-100 text-blue-800';
                                                    }
                                                    ?>">
                                                    <?php echo ucfirst($event['event_type']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php 
                                                echo date('M d, Y', strtotime($event['start_date']));
                                                if ($event['start_date'] !== $event['end_date']) {
                                                    echo ' - ' . date('M d, Y', strtotime($event['end_date']));
                                                }
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <span class="px-2 py-1 rounded-full text-xs <?php echo $event['blocked_for_user_types'] === 'all' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                    <?php echo ucfirst($event['blocked_for_user_types']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="toggle_event">
                                                    <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $event['is_active'] ? 0 : 1; ?>">
                                                    <button type="submit" class="text-sm <?php echo $event['is_active'] ? 'text-green-600 hover:text-green-800' : 'text-gray-600 hover:text-gray-800'; ?>">
                                                        <?php echo $event['is_active'] ? '✓ Active' : '✗ Inactive'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($event), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                        class="text-blue-600 hover:text-blue-900 mr-3" title="Edit/Reschedule">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this event?');">
                                                    <input type="hidden" name="action" value="delete_event">
                                                    <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                                    <button type="submit" class="text-red-600 hover:text-red-900" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No events or blocked dates found. Add your first event above.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Edit Event Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">
                    <i class="fas fa-calendar-edit text-blue-600 mr-2"></i>
                    Reschedule Event
                </h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" action="gym_events.php" id="editEventForm">
                <input type="hidden" name="action" value="reschedule_event">
                <input type="hidden" name="event_id" id="edit_event_id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="edit_event_name" class="block text-sm font-medium text-gray-700 mb-1">Event Name *</label>
                        <input type="text" id="edit_event_name" name="event_name" required 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_event_type" class="block text-sm font-medium text-gray-700 mb-1">Event Type *</label>
                        <select id="edit_event_type" name="event_type" required 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                            <option value="ceremony">Ceremony</option>
                            <option value="intramurals">Intramurals</option>
                            <option value="school_event">School Event</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit_start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
                        <input type="date" id="edit_start_date" name="start_date" required 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date *</label>
                        <input type="date" id="edit_end_date" name="end_date" required 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="edit_blocked_for_user_types" class="block text-sm font-medium text-gray-700 mb-1">Block For *</label>
                        <select id="edit_blocked_for_user_types" name="blocked_for_user_types" required 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                            <option value="external">External Users Only</option>
                            <option value="all">All Users (School Priority)</option>
                            <option value="student">Students Only</option>
                            <option value="staff">Staff Only</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit_affected_facilities" class="block text-sm font-medium text-gray-700 mb-1">Affected Facilities</label>
                        <input type="text" value="All Facilities" readonly
                               class="w-full rounded-md border-gray-300 shadow-sm bg-gray-100 cursor-not-allowed">
                        <input type="hidden" name="affected_facilities" value="all">
                    </div>
                </div>
                <div class="mb-4">
                    <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="edit_description" name="description" rows="2" 
                              class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500"></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeEditModal()" 
                            class="bg-gray-300 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-400">
                        Cancel
                    </button>
                    <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.getElementById('menu-button')?.addEventListener('click', function() {
        document.getElementById('sidebar')?.classList.toggle('-translate-x-full');
    });
    
    // Handle checkbox conversion to comma-separated string
    document.querySelector('form[action="gym_events.php"]').addEventListener('submit', function(e) {
        if (this.querySelector('input[name="action"]').value === 'update_rules') {
            const checkboxes = this.querySelectorAll('input[name="allowed_months[]"]:checked');
            const values = Array.from(checkboxes).map(cb => cb.value);
            
            // Remove all checkboxes
            checkboxes.forEach(cb => cb.remove());
            
            // Add hidden input with comma-separated values
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'allowed_months';
            hiddenInput.value = values.join(',');
            this.appendChild(hiddenInput);
        }
    });
    
    // Ensure end date is not before start date and prevent past dates
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            const startDate = this.value;
            if (startDate && endDateInput) {
                endDateInput.min = startDate;
                if (endDateInput.value && endDateInput.value < startDate) {
                    endDateInput.value = startDate;
                }
            }
        });
    }
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>

