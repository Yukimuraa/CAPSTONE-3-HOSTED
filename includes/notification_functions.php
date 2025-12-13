<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Create a new notification for a user
 */
function create_notification($user_id, $title, $message, $type = 'info', $link = null) {
    global $conn;
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
    if (!$table_check || $table_check->num_rows == 0) {
        return false; // Table doesn't exist
    }
    
    // Verify user exists before creating notification
    $user_check = $conn->prepare("SELECT id FROM user_accounts WHERE id = ?");
    if ($user_check) {
        $user_check->bind_param("i", $user_id);
        $user_check->execute();
        $user_result = $user_check->get_result();
        if ($user_result->num_rows == 0) {
            return false; // User doesn't exist
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false; // Error preparing statement
    }
    
    $stmt->bind_param("issss", $user_id, $title, $message, $type, $link);
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    }
    return false;
}

/**
 * Create notifications for all users
 */
function create_notification_for_all($title, $message, $type = 'info', $link = null) {
    global $conn;
    
    $users = $conn->query("SELECT id FROM user_accounts WHERE status = 'active'");
    $count = 0;
    
    while ($user = $users->fetch_assoc()) {
        if (create_notification($user['id'], $title, $message, $type, $link)) {
            $count++;
        }
    }
    
    return $count;
}

/**
 * Create notifications for all admin users
 */
function create_notification_for_admins($title, $message, $type = 'info', $link = null) {
    global $conn;
    
    $admins = $conn->query("SELECT id FROM user_accounts WHERE user_type IN ('admin', 'secretary') AND status = 'active'");
    $count = 0;
    
    while ($admin = $admins->fetch_assoc()) {
        if (create_notification($admin['id'], $title, $message, $type, $link)) {
            $count++;
        }
    }
    
    return $count;
}

/**
 * Get unread notifications count for a user
 */
function get_unread_notification_count($user_id) {
    global $conn;
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
    if (!$table_check || $table_check->num_rows == 0) {
        return 0; // Table doesn't exist, return 0
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    if (!$stmt) {
        return 0; // Error preparing statement
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['count'] ?? 0;
}

/**
 * Get notifications for a user
 */
function get_user_notifications($user_id, $limit = 10, $unread_only = false) {
    global $conn;
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
    if (!$table_check || $table_check->num_rows == 0) {
        return []; // Table doesn't exist, return empty array
    }
    
    $sql = "SELECT * FROM notifications WHERE user_id = ?";
    if ($unread_only) {
        $sql .= " AND is_read = FALSE";
    }
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return []; // Error preparing statement
    }
    
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    return $notifications;
}

/**
 * Mark notification as read
 */
function mark_notification_read($notification_id, $user_id) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    return $stmt->execute();
}

/**
 * Mark all notifications as read for a user
 */
function mark_all_notifications_read($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    return $stmt->execute();
}

/**
 * Delete a notification
 */
function delete_notification($notification_id, $user_id) {
    global $conn;
    
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    return $stmt->execute();
}
?>

