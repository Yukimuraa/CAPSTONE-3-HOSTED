<?php
// Get user_id from session - handle both old and new session structure
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} elseif (isset($_SESSION['active_user_type']) && isset($_SESSION['user_sessions'][$_SESSION['active_user_type']]['user_id'])) {
    $user_id = $_SESSION['user_sessions'][$_SESSION['active_user_type']]['user_id'];
} else {
    return; // User not logged in
}

require_once __DIR__ . '/notification_functions.php';
$unread_count = get_unread_notification_count($user_id);
$notifications = get_user_notifications($user_id, 10);
$base_url = $base_url ?? '..';
?>

<!-- Notification Bell -->
<div class="relative">
    <button id="notification-bell" class="relative p-2 text-gray-600 hover:text-gray-800 focus:outline-none transition-colors">
        <i class="fas fa-bell text-xl"></i>
        <?php if ($unread_count > 0): ?>
        <span class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full">
            <?php echo $unread_count > 99 ? '99+' : $unread_count; ?>
        </span>
        <?php endif; ?>
    </button>
    
    <!-- Notification Dropdown -->
    <div id="notification-dropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg z-50 border border-gray-200">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900">Notifications</h3>
            <div class="flex gap-2">
                <?php if ($unread_count > 0): ?>
                <button id="mark-all-read" class="text-xs text-blue-600 hover:text-blue-800">Mark all read</button>
                <?php endif; ?>
                <button id="close-notifications" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div id="notifications-list" class="max-h-96 overflow-y-auto">
            <?php if (empty($notifications)): ?>
                <div class="p-4 text-center text-gray-500">
                    <i class="fas fa-bell-slash text-2xl mb-2"></i>
                    <p>No notifications</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                <div class="notification-item p-4 border-b hover:bg-gray-50 cursor-pointer <?php echo $notification['is_read'] ? '' : 'bg-blue-50'; ?>" 
                     data-id="<?php echo $notification['id']; ?>"
                     <?php if ($notification['link']): ?>
                     onclick="handleNotificationClick('<?php echo htmlspecialchars($notification['link']); ?>')"
                     <?php endif; ?>>
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-xs font-semibold text-gray-900"><?php echo htmlspecialchars($notification['title']); ?></span>
                                <?php if (!$notification['is_read']): ?>
                                <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                            <p class="text-xs text-gray-400 mt-1">
                                <?php 
                                $time = strtotime($notification['created_at']);
                                $diff = time() - $time;
                                if ($diff < 60) echo 'Just now';
                                elseif ($diff < 3600) echo floor($diff/60) . ' minutes ago';
                                elseif ($diff < 86400) echo floor($diff/3600) . ' hours ago';
                                else echo date('M j, Y', $time);
                                ?>
                            </p>
                        </div>
                        <button class="delete-notification ml-2 text-gray-400 hover:text-red-600" data-id="<?php echo $notification['id']; ?>" onclick="event.stopPropagation();">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notification-bell');
    const dropdown = document.getElementById('notification-dropdown');
    const markAllRead = document.getElementById('mark-all-read');
    const closeBtn = document.getElementById('close-notifications');
    const baseUrl = '<?php echo $base_url; ?>';
    
    // Toggle dropdown
    if (bell) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('hidden');
            loadNotifications();
        });
    }
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (bell && dropdown && !bell.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
    
    // Close button
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            dropdown.classList.add('hidden');
        });
    }
    
    // Mark all as read
    if (markAllRead) {
        markAllRead.addEventListener('click', function(e) {
            e.stopPropagation();
            fetch(baseUrl + '/api/get_notifications.php?action=mark_all_read')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
        });
    }
    
    // Delete notification
    document.querySelectorAll('.delete-notification').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const id = this.getAttribute('data-id');
            if (confirm('Delete this notification?')) {
                fetch(baseUrl + '/api/get_notifications.php?action=delete&id=' + id)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.closest('.notification-item').remove();
                            updateNotificationCount();
                            // Reload if no notifications left
                            const list = document.getElementById('notifications-list');
                            if (list && list.querySelectorAll('.notification-item').length === 0) {
                                location.reload();
                            }
                        }
                    });
            }
        });
    });
    
    // Mark as read when clicking notification
    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            if (!this.classList.contains('bg-blue-50')) return; // Already read
            
            fetch(baseUrl + '/api/get_notifications.php?action=mark_read&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.classList.remove('bg-blue-50');
                        const dot = this.querySelector('.bg-blue-600');
                        if (dot) dot.remove();
                        updateNotificationCount();
                    }
                });
        });
    });
    
    // Load notifications
    function loadNotifications() {
        fetch(baseUrl + '/api/get_notifications.php?action=list&limit=10')
            .then(response => response.json())
            .then(data => {
                // Update notifications list if needed
            });
    }
    
    // Update notification count
    function updateNotificationCount() {
        fetch(baseUrl + '/api/get_notifications.php?action=count')
            .then(response => response.json())
            .then(data => {
                if (!bell) return;
                const badge = bell.querySelector('span');
                if (data.count > 0) {
                    if (!badge) {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full';
                        newBadge.textContent = data.count > 99 ? '99+' : data.count;
                        bell.appendChild(newBadge);
                    } else {
                        badge.textContent = data.count > 99 ? '99+' : data.count;
                    }
                } else if (badge) {
                    badge.remove();
                }
            });
    }
    
    // Auto-refresh notification count every 30 seconds
    setInterval(updateNotificationCount, 30000);
    
    // Handle notification click - properly construct the URL
    function handleNotificationClick(link) {
        if (!link) return;
        
        // Determine the correct path based on current location
        const currentPath = window.location.pathname;
        let targetUrl;
        
        // If link already starts with http:// or https://, use it as is
        if (link.startsWith('http://') || link.startsWith('https://')) {
            targetUrl = link;
        } else {
            // Remove any leading slashes
            link = link.replace(/^\/+/, '');
            
            // Check if we're in student, external, or admin folder
            if (currentPath.includes('/student/')) {
                // If link starts with 'student/', remove it since we're already in student folder
                if (link.startsWith('student/')) {
                    link = link.replace(/^student\//, '');
                    targetUrl = link; // Use relative path since we're already in student folder
                } else {
                    targetUrl = baseUrl + '/' + link;
                }
            } else if (currentPath.includes('/external/')) {
                // If link starts with 'external/', remove it since we're already in external folder
                if (link.startsWith('external/')) {
                    link = link.replace(/^external\//, '');
                    targetUrl = link; // Use relative path since we're already in external folder
                } else {
                    targetUrl = baseUrl + '/' + link;
                }
            } else if (currentPath.includes('/admin/')) {
                // If link starts with 'admin/', remove it since we're already in admin folder
                if (link.startsWith('admin/')) {
                    link = link.replace(/^admin\//, '');
                    targetUrl = link; // Use relative path since we're already in admin folder
                } else {
                    targetUrl = baseUrl + '/' + link;
                }
            } else {
                // Root level - use the link as is with base_url
                targetUrl = baseUrl + '/' + link;
            }
        }
        
        // Navigate to the target URL
        window.location.href = targetUrl;
    }
});
</script>

