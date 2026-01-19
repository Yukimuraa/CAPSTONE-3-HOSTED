<?php
// Don't start a session here since it's already started in the main pages

// Determine the path to logout.php
$logout_path = dirname($_SERVER['PHP_SELF']);
$logout_path = preg_replace('/(\/admin|\/student|\/staff|\/external)$/', '', $logout_path);
if ($logout_path === '') {
    $logout_path = '/';
}
if (substr($logout_path, -1) !== '/') {
    $logout_path .= '/';
}
$logout_path .= 'logout.php?user_type=external';
?>

<div class="bg-blue-800 text-white w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition duration-200 ease-in-out" id="sidebar">
    <a href="dashboard.php" class="flex items-center space-x-2 px-4 hover:opacity-80 transition-opacity">
        <img src="../image/CHMSUWebLOGO.png" alt="CHMSU Logo" class="h-10 w-auto">
        <div>
            <span class="text-xl font-bold">CHMSU BAO</span>
            <!-- <p class="text-xs text-gray-400">External Portal</p> -->
        </div>
    </a>
    <nav>
        <a href="dashboard.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-amber-700">
            <i class="fas fa-home mr-2"></i>Dashboard
        </a>
        <a href="requests.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-amber-700">
            <i class="fas fa-clipboard-list mr-2"></i>My Requests
        </a>
        <a href="gym.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-amber-700" onclick="handleGymReservationClick(event);">
            <i class="fas fa-calendar-alt mr-2"></i>Gym Reservation
        </a>
        <a href="oval.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-amber-700">
            <i class="fas fa-running mr-2"></i>Oval Field Reservation
        </a>
        <a href="profile.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-amber-700">
            <i class="fas fa-user mr-2"></i>My Profile
        </a>
        <a href="<?php echo $logout_path; ?>" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-amber-700 mt-6">
            <i class="fas fa-sign-out-alt mr-2"></i>Logout
        </a>
    </nav>
</div>

<script>
function handleGymReservationClick(event) {
    // Check if we're already on the gym page
    const currentPage = window.location.pathname;
    if (currentPage.includes('gym.php')) {
        // Already on gym page, show reminder modal if function exists
        event.preventDefault();
        if (typeof showGymReminderModal === 'function') {
            showGymReminderModal();
        } else {
            // If function doesn't exist yet, navigate to trigger page load
            window.location.href = 'gym.php?show_reminder=1';
        }
    } else {
        // Not on gym page, navigate normally (reminder will show on gym.php if needed)
        // Or we can show reminder first, then navigate
        event.preventDefault();
        showGymReminderBeforeNavigate();
    }
}

function showGymReminderBeforeNavigate() {
    // Create modal if it doesn't exist
    let modal = document.getElementById('gymReminderModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'gymReminderModal';
        modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50';
        modal.innerHTML = `
            <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-exclamation-circle text-amber-500 mr-2"></i>Important Reminder
                    </h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeGymReminderBeforeNavigate()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mb-6">
                    <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded">
                        <p class="text-gray-700 font-medium">
                            <i class="fas fa-info-circle text-amber-500 mr-2"></i>
                            Please ensure that the letter from the president is completed before making a booking.
                        </p>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="button" class="bg-blue-600 text-white py-2 px-6 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" onclick="proceedToGymPage()">
                        I Understand, Continue
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
}

function closeGymReminderBeforeNavigate() {
    const modal = document.getElementById('gymReminderModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

function proceedToGymPage() {
    closeGymReminderBeforeNavigate();
    window.location.href = 'gym.php';
}
</script>
