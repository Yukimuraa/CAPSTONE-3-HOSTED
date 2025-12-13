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

$page_title = "Order Management - CHMSU BAO";
$base_url = "..";

// Handle status updates and mark complete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_complete_batch']) && isset($_POST['batch_id']) && isset($_POST['or_number'])) {
        // Mark all orders in batch as completed and deduct inventory
        $batch_id = $_POST['batch_id'];
        $or_number = !empty($_POST['or_number']) ? sanitize_input($_POST['or_number']) : null;
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get all orders in batch
            $get_orders = $conn->prepare("SELECT o.*, i.quantity as current_quantity, i.size_quantities FROM orders o JOIN inventory i ON o.inventory_id = i.id WHERE o.batch_id = ? AND o.status = 'pending'");
            $get_orders->bind_param("s", $batch_id);
            $get_orders->execute();
            $orders_result = $get_orders->get_result();
            
            // Deduct inventory for each order
            while ($order = $orders_result->fetch_assoc()) {
                $new_quantity = $order['current_quantity'] - $order['quantity'];
                $in_stock = $new_quantity > 0 ? 1 : 0;
                
                // Handle size_quantities if size is provided
                $size_quantities_json = $order['size_quantities'] ?? null;
                if (!empty($order['size']) && !empty($size_quantities_json)) {
                    $size_quantities = json_decode($size_quantities_json, true);
                    if (is_array($size_quantities) && isset($size_quantities[$order['size']])) {
                        // Decrease the specific size quantity
                        $size_quantities[$order['size']] = max(0, $size_quantities[$order['size']] - $order['quantity']);
                        // Re-encode the updated size quantities
                        $size_quantities_json = json_encode($size_quantities);
                    }
                }
                
                // Update inventory
                $update_inventory = $conn->prepare("UPDATE inventory SET quantity = ?, in_stock = ?, size_quantities = ? WHERE id = ?");
                $update_inventory->bind_param("iiss", $new_quantity, $in_stock, $size_quantities_json, $order['inventory_id']);
                $update_inventory->execute();
            }
            
            // Mark all orders as completed and update OR number
            $update_query = "UPDATE orders SET status = 'completed', or_number = ? WHERE batch_id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("ss", $or_number, $batch_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Send notifications to all users in the batch
            require_once '../includes/notification_functions.php';
            $notify_orders = $conn->prepare("SELECT DISTINCT user_id, order_id FROM orders WHERE batch_id = ?");
            $notify_orders->bind_param("s", $batch_id);
            $notify_orders->execute();
            $notify_result = $notify_orders->get_result();
            while ($notify_order = $notify_result->fetch_assoc()) {
                create_notification($notify_order['user_id'], "Order Approved", "Your order (Order ID: {$notify_order['order_id']}) has been approved and completed. Thank you for your purchase!", "success", "student/receipt.php?order_id=" . urlencode($notify_order['order_id']));
            }
        
            // Redirect back to orders page
            header("Location: orders.php?success=1");
            exit();
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error_message = "Error completing order: " . $e->getMessage();
        }
    } elseif (isset($_POST['mark_complete']) && isset($_POST['order_id']) && isset($_POST['or_number'])) {
        // Mark single order as completed and deduct inventory
        $order_id = (int)$_POST['order_id'];
        $or_number = !empty($_POST['or_number']) ? sanitize_input($_POST['or_number']) : null;
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get order details with current inventory
            $get_order = $conn->prepare("SELECT o.*, i.quantity as current_quantity, i.size_quantities FROM orders o JOIN inventory i ON o.inventory_id = i.id WHERE o.id = ? AND o.status = 'pending'");
            $get_order->bind_param("i", $order_id);
            $get_order->execute();
            $order_result = $get_order->get_result();
            
            if ($order_result->num_rows === 0) {
                throw new Exception("Order not found or already processed");
            }
            
            $order = $order_result->fetch_assoc();
            
            // Deduct inventory
            $new_quantity = $order['current_quantity'] - $order['quantity'];
            $in_stock = $new_quantity > 0 ? 1 : 0;
            
            // Handle size_quantities if size is provided
            $size_quantities_json = $order['size_quantities'] ?? null;
            if (!empty($order['size']) && !empty($size_quantities_json)) {
                $size_quantities = json_decode($size_quantities_json, true);
                if (is_array($size_quantities) && isset($size_quantities[$order['size']])) {
                    // Decrease the specific size quantity
                    $size_quantities[$order['size']] = max(0, $size_quantities[$order['size']] - $order['quantity']);
                    // Re-encode the updated size quantities
                    $size_quantities_json = json_encode($size_quantities);
                }
            }
            
            // Update inventory
            $update_inventory = $conn->prepare("UPDATE inventory SET quantity = ?, in_stock = ?, size_quantities = ? WHERE id = ?");
            $update_inventory->bind_param("iiss", $new_quantity, $in_stock, $size_quantities_json, $order['inventory_id']);
            $update_inventory->execute();
            
            // Mark order as completed and update OR number
            $update_query = "UPDATE orders SET status = 'completed', or_number = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("si", $or_number, $order_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Send notification to user
            require_once '../includes/notification_functions.php';
            $order_user_id = $order['user_id'];
            $order_id_str = $order['order_id'];
            create_notification($order_user_id, "Order Approved", "Your order (Order ID: {$order_id_str}) has been approved and completed. Thank you for your purchase!", "success", "student/receipt.php?order_id=" . urlencode($order_id_str));
            
            // Redirect back to orders page
            header("Location: orders.php?success=1");
            exit();
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error_message = "Error completing order: " . $e->getMessage();
        }
    } elseif (isset($_POST['order_id']) && isset($_POST['status'])) {
        $order_id = (int)$_POST['order_id'];
        $status = $_POST['status'];
        
        $update_query = "UPDATE orders SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("si", $status, $order_id);
        
        if ($stmt->execute()) {
            $success_message = "Order status updated successfully!";
        } else {
            $error_message = "Error updating order status.";
        }
    }
}

// Get search parameter
$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';

// Get status filter parameter
$status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';

// Pagination - rows per page (display rows, not order rows)
$rows_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Get all orders with user details and item information
$query = "SELECT o.*, u.name as user_name, u.email as user_email, u.user_type, u.role, i.name as item_name, i.price as item_price 
          FROM orders o 
          JOIN user_accounts u ON o.user_id = u.id 
          JOIN inventory i ON o.inventory_id = i.id";

// Build WHERE clause for filters
$where_clauses = [];
$params = [];
$param_types = '';

// Add search filter if search term provided
if (!empty($search)) {
    $search_term = "%$search%";
    $where_clauses[] = "(o.order_id LIKE ? OR o.batch_id LIKE ? OR u.name LIKE ? OR i.name LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= 'ssss';
}

// Add status filter if provided
if (!empty($status_filter) && in_array($status_filter, ['pending', 'completed', 'cancelled'])) {
    $where_clauses[] = "o.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

// Add WHERE clause if any filters are active
if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

$query .= " ORDER BY o.created_at DESC, o.batch_id, o.id";

// Helper function to format user name with role/type
function formatUserName($user_name, $user_type, $role = null) {
    $roleLabels = [
        'student' => 'Student',
        'faculty' => 'Faculty',
        'staff' => 'Staff'
    ];
    
    $userTypeLabels = [
        'admin' => 'BAO Admin',
        'secretary' => 'BAO Secretary',
        'staff' => 'Staff',
        'external' => 'External User'
    ];
    
    // For students, use role if available
    if ($user_type === 'student' && $role && isset($roleLabels[$role])) {
        return $user_name . ' (' . $roleLabels[$role] . ')';
    }
    
    // For other user types, use user_type label
    if (isset($userTypeLabels[$user_type])) {
        return $user_name . ' (' . $userTypeLabels[$user_type] . ')';
    }
    
    // Fallback
    return $user_name;
}

// Get all orders first to count display rows (batches + single orders)
if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $all_orders_result = $stmt->get_result();
} else {
    $all_orders_result = $conn->query($query);
}

// Group orders to count display rows
$grouped_orders = [];
$single_orders = [];

while ($order = $all_orders_result->fetch_assoc()) {
    if (!empty($order['batch_id'])) {
        if (!isset($grouped_orders[$order['batch_id']])) {
            $grouped_orders[$order['batch_id']] = true;
        }
    } else {
        $single_orders[] = $order;
    }
}

// Count total display rows (batches + single orders)
$total_display_rows = count($grouped_orders) + count($single_orders);
$total_pages = ceil($total_display_rows / $rows_per_page);
$offset = ($current_page - 1) * $rows_per_page;

// Fetch enough orders to get the required display rows
// Fetch more orders than needed to account for grouping (batches may have multiple orders)
$fetch_limit = ($offset + $rows_per_page) * 10; // Fetch 10x to ensure we get enough groups
$limited_query = $query . " LIMIT " . intval($fetch_limit);

if (!empty($params)) {
    $stmt = $conn->prepare($limited_query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $orders = $stmt->get_result();
} else {
    $orders = $conn->query($limited_query);
}
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Order Management</h1>
                <div class="flex items-center">
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
                <?php if (isset($success_message)): ?>
                    <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
                    <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                        Order marked as complete successfully!
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Filter and Search Bar -->
                <div class="mb-6 bg-white rounded-lg shadow p-4">
                    <!-- Status Filter Buttons -->
                    <div class="mb-4 flex gap-2 flex-wrap">
                        <a href="orders.php<?php echo !empty($search) ? '?search=' . urlencode($search) : ''; ?>" 
                           class="px-4 py-2 rounded-md text-sm font-medium <?php echo empty($status_filter) ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            <i class="fas fa-list mr-1"></i>All Orders
                        </a>
                        <a href="orders.php?status=pending<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                           class="px-4 py-2 rounded-md text-sm font-medium <?php echo $status_filter === 'pending' ? 'bg-yellow-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            <i class="fas fa-clock mr-1"></i>Pending
                        </a>
                        <a href="orders.php?status=completed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                           class="px-4 py-2 rounded-md text-sm font-medium <?php echo $status_filter === 'completed' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            <i class="fas fa-check-circle mr-1"></i>Completed
                        </a>
                        <a href="orders.php?status=cancelled<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                           class="px-4 py-2 rounded-md text-sm font-medium <?php echo $status_filter === 'cancelled' ? 'bg-gray-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            <i class="fas fa-ban mr-1"></i>Cancelled
                        </a>
                    </div>
                    
                    <!-- Search Form -->
                    <form method="GET" action="orders.php" class="flex gap-4 items-end">
                        <?php if (!empty($status_filter)): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <?php endif; ?>
                        <div class="flex-1">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-search mr-1"></i>Search Orders
                            </label>
                            <input 
                                type="text" 
                                name="search" 
                                id="search" 
                                value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by Order ID, Batch ID, Customer Name, or Item Name..."
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                onkeypress="return !/[!@#$%^&*()_+={}\[\]:;&quot;'<>?\/\\|`~]/.test(event.key)"
                                oninput="this.value = this.value.replace(/[!@#$%^&*()_+={}\[\]:;&quot;'<>?\/\\|`~]/g, '')"
                            >
                        </div>
                        <div class="flex gap-2">
                            <button 
                                type="submit" 
                                class="px-6 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                                <i class="fas fa-search mr-1"></i>Search
                            </button>
                            <?php if (!empty($search) || !empty($status_filter)): ?>
                                <a 
                                    href="orders.php" 
                                    class="px-6 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                                    <i class="fas fa-times mr-1"></i>Clear All
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if (!empty($search) || !empty($status_filter)): ?>
                        <div class="mt-3 text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            <?php if (!empty($search)): ?>
                                Showing results for: <strong>"<?php echo htmlspecialchars($search); ?>"</strong>
                            <?php endif; ?>
                            <?php if (!empty($status_filter)): ?>
                                <?php if (!empty($search)): ?> | <?php endif; ?>
                                Status: <strong><?php echo ucfirst($status_filter); ?></strong>
                            <?php endif; ?>
                            (<?php echo $total_display_rows; ?> result<?php echo $total_display_rows != 1 ? 's' : ''; ?> found)
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Orders Table -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch/Order #</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($orders->num_rows > 0): ?>
                                    <?php 
                                    $current_batch = null;
                                    $batch_items = [];
                                    $batch_total = 0;
                                    $displayed_rows = 0;
                                    
                                    while ($order = $orders->fetch_assoc()):
                                        // Group by batch_id
                                        if ($order['batch_id'] && $order['batch_id'] != $current_batch) {
                                            // Display previous batch if exists and we're in the display range
                                            if ($current_batch && count($batch_items) > 0) {
                                                if ($displayed_rows >= $offset && $displayed_rows < ($offset + $rows_per_page)) {
                                                $first_item = $batch_items[0];
                                                ?>
                                                <tr class="bg-blue-50">
                                                    <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                                        <?php echo $current_batch; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-900">
                                                        <?php echo formatUserName($first_item['user_name'], $first_item['user_type'] ?? 'student', $first_item['role'] ?? null); ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500">
                                                        <?php echo count($batch_items); ?> items
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500">
                                                        -
                                                    </td>
                                                    <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                                        ₱<?php echo number_format($batch_total, 2); ?>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <?php
                                                        $status_value = isset($first_item['status']) ? trim(strtolower($first_item['status'])) : 'pending';
                                                        $status_classes = [
                                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                                            'completed' => 'bg-blue-100 text-blue-800',
                                                            'cancelled' => 'bg-gray-100 text-gray-800',
                                                            'canceled' => 'bg-gray-100 text-gray-800'
                                                        ];
                                                        $status_class = $status_classes[$status_value] ?? 'bg-gray-100 text-gray-800';
                                                        
                                                        // Set status text
                                                        $status_text = 'Pending';
                                                        switch ($status_value) {
                                                            case 'pending':
                                                                $status_text = 'Pending';
                                                                break;
                                                            case 'completed':
                                                                $status_text = 'Completed';
                                                                break;
                                                            case 'cancelled':
                                                            case 'canceled':
                                                                $status_text = 'Cancelled';
                                                                break;
                                                            default:
                                                                $status_text = 'Cancelled';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm">
                                                        <div class="flex gap-2">
                                                            <button onclick="viewBatchOrder('<?php echo $current_batch; ?>')" 
                                                                    class="inline-flex items-center px-3 py-1 border border-blue-600 rounded-md text-sm font-medium text-blue-600 bg-white hover:bg-blue-50">
                                                                <i class="fas fa-eye mr-1"></i> View
                                                            </button>
                                                            <?php if ($first_item['status'] === 'pending'): ?>
                                                                <button onclick="openMarkCompleteModal('<?php echo $current_batch; ?>', 'batch')" 
                                                                        class="inline-flex items-center px-3 py-1 border border-transparent rounded-md text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                                                    <i class="fas fa-check mr-1"></i> Mark Complete
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php
                                                }
                                                $displayed_rows++;
                                            }
                                            
                                            // Start new batch
                                            $current_batch = $order['batch_id'];
                                            $batch_items = [$order];
                                            $batch_total = $order['total_price'];
                                            
                                            // Count this batch (even if not displayed yet)
                                            if ($displayed_rows < $offset) {
                                                $displayed_rows++;
                                            }
                                        } elseif ($order['batch_id'] == $current_batch) {
                                            // Add to current batch
                                            $batch_items[] = $order;
                                            $batch_total += $order['total_price'];
                                        } else {
                                            // Single order (no batch) - only display if in range
                                            if ($displayed_rows >= $offset && $displayed_rows < ($offset + $rows_per_page)) {
                                                ?>
                                                <tr>
                                                    <td class="px-6 py-4 text-sm text-gray-900">
                                                        <?php echo $order['order_id']; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-900">
                                                        <?php echo formatUserName($order['user_name'], $order['user_type'] ?? 'student', $order['role'] ?? null); ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500">
                                                        <?php echo $order['item_name']; ?>
                                                        <?php if (!empty($order['size'])): ?>
                                                            <span class="text-gray-400">(<?php echo $order['size']; ?>)</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500"><?php echo $order['quantity']; ?></td>
                                                    <td class="px-6 py-4 text-sm text-gray-500">
                                                        ₱<?php echo number_format($order['total_price'], 2); ?>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <?php
                                                        $status_value = isset($order['status']) ? trim(strtolower($order['status'])) : 'pending';
                                                        $status_classes = [
                                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                                            'completed' => 'bg-blue-100 text-blue-800',
                                                            'cancelled' => 'bg-gray-100 text-gray-800',
                                                            'canceled' => 'bg-gray-100 text-gray-800'
                                                        ];
                                                        $status_class = $status_classes[$status_value] ?? 'bg-gray-100 text-gray-800';
                                                        
                                                        // Set status text
                                                        $status_text = 'Pending';
                                                        switch ($status_value) {
                                                            case 'pending':
                                                                $status_text = 'Pending';
                                                                break;
                                                            case 'completed':
                                                                $status_text = 'Completed';
                                                                break;
                                                            case 'cancelled':
                                                            case 'canceled':
                                                                $status_text = 'Cancelled';
                                                                break;
                                                            default:
                                                                $status_text = 'Cancelled';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm">
                                                        <div class="flex gap-2">
                                                            <button onclick="viewSingleOrder(<?php echo $order['id']; ?>)" 
                                                                    class="inline-flex items-center px-3 py-1 border border-blue-600 rounded-md text-sm font-medium text-blue-600 bg-white hover:bg-blue-50">
                                                                <i class="fas fa-eye mr-1"></i> View
                                                            </button>
                                                            <?php if ($order['status'] === 'pending'): ?>
                                                                <button onclick="openMarkCompleteModal('<?php echo $order['id']; ?>', 'single')" 
                                                                        class="inline-flex items-center px-3 py-1 border border-transparent rounded-md text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                                                    <i class="fas fa-check mr-1"></i> Mark Complete
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                            $displayed_rows++;
                                        }
                                    endwhile;
                                    
                                    // Display last batch if exists and in range
                                    if ($current_batch && count($batch_items) > 0 && $displayed_rows >= $offset && $displayed_rows < ($offset + $rows_per_page)) {
                                        $first_item = $batch_items[0];
                                        ?>
                                        <tr class="bg-blue-50">
                                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                                <?php echo $current_batch; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-900">
                                                <?php echo formatUserName($first_item['user_name'], $first_item['user_type'] ?? 'student', $first_item['role'] ?? null); ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo count($batch_items); ?> items
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                -
                                            </td>
                                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                                ₱<?php echo number_format($batch_total, 2); ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php
                                                $status_value = isset($first_item['status']) ? trim(strtolower($first_item['status'])) : 'pending';
                                                $status_classes = [
                                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                                    'completed' => 'bg-blue-100 text-blue-800',
                                                    'cancelled' => 'bg-gray-100 text-gray-800',
                                                    'canceled' => 'bg-gray-100 text-gray-800'
                                                ];
                                                $status_class = $status_classes[$status_value] ?? 'bg-gray-100 text-gray-800';
                                                
                                                // Set status text
                                                $status_text = 'Pending';
                                                switch ($status_value) {
                                                    case 'pending':
                                                        $status_text = 'Pending';
                                                        break;
                                                    case 'completed':
                                                        $status_text = 'Completed';
                                                        break;
                                                    case 'cancelled':
                                                    case 'canceled':
                                                        $status_text = 'Cancelled';
                                                        break;
                                                    default:
                                                        $status_text = 'Cancelled';
                                                        break;
                                                }
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm">
                                                <div class="flex gap-2">
                                                    <button onclick="viewBatchOrder('<?php echo $current_batch; ?>')" 
                                                            class="inline-flex items-center px-3 py-1 border border-blue-600 rounded-md text-sm font-medium text-blue-600 bg-white hover:bg-blue-50">
                                                        <i class="fas fa-eye mr-1"></i> View
                                                    </button>
                                                    <?php if ($first_item['status'] === 'pending'): ?>
                                                        <button onclick="openMarkCompleteModal('<?php echo $current_batch; ?>', 'batch')" 
                                                                class="inline-flex items-center px-3 py-1 border border-transparent rounded-md text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                                            <i class="fas fa-check mr-1"></i> Mark Complete
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No orders found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <?php
                    // Build query string for pagination
                    $pagination_params = [];
                    if (!empty($search)) {
                        $pagination_params[] = 'search=' . urlencode($search);
                    }
                    if (!empty($status_filter)) {
                        $pagination_params[] = 'status=' . urlencode($status_filter);
                    }
                    $pagination_query = !empty($pagination_params) ? '&' . implode('&', $pagination_params) : '';
                    ?>
                    <div class="mt-6 flex items-center justify-between bg-white px-4 py-3 rounded-lg shadow">
                        <div class="text-sm text-gray-700">
                            Showing <span class="font-medium"><?php echo $total_display_rows > 0 ? $offset + 1 : 0; ?></span> to 
                            <span class="font-medium"><?php echo min($offset + $rows_per_page, $total_display_rows); ?></span> of 
                            <span class="font-medium"><?php echo $total_display_rows; ?></span> result<?php echo $total_display_rows != 1 ? 's' : ''; ?>
                        </div>
                        <div class="flex gap-2">
                            <?php if ($current_page > 1): ?>
                                <a href="?page=<?php echo $current_page - 1; ?><?php echo $pagination_query; ?>" 
                                   class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    <i class="fas fa-chevron-left mr-1"></i>Previous
                                </a>
                            <?php else: ?>
                                <span class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">
                                    <i class="fas fa-chevron-left mr-1"></i>Previous
                                </span>
                            <?php endif; ?>
                            
                            <?php
                            // Show page numbers
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            if ($start_page > 1): ?>
                                <a href="?page=1<?php echo $pagination_query; ?>" 
                                   class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="px-4 py-2 text-gray-500">...</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <?php if ($i == $current_page): ?>
                                    <span class="px-4 py-2 border border-red-600 rounded-md text-sm font-medium text-white bg-red-600">
                                        <?php echo $i; ?>
                                    </span>
                                <?php else: ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $pagination_query; ?>" 
                                       class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="px-4 py-2 text-gray-500">...</span>
                                <?php endif; ?>
                                <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" 
                                   class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    <?php echo $total_pages; ?>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?page=<?php echo $current_page + 1; ?><?php echo $pagination_query; ?>" 
                                   class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    Next<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                            <?php else: ?>
                                <span class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">
                                    Next<i class="fas fa-chevron-right ml-1"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Order Details Modal -->
<div id="orderModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex items-center justify-between border-b pb-3 mb-4">
            <h3 class="text-lg font-medium text-gray-900">Order Details</h3>
            <button onclick="closeOrderModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="orderDetailsContent">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Mark Complete Modal -->
<div id="markCompleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">Mark Order as Complete</h3>
                <button type="button" onclick="closeMarkCompleteModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Warning Question -->
            <div id="orQuestionSection" class="mb-4">
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                <strong>Did the student show the OR No from cashier?</strong>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeMarkCompleteModal()"
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                        Cancel
                    </button>
                    <button type="button" onclick="handleOrNoResponse(true)" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Yes
                    </button>
                </div>
            </div>
            
            <!-- OR Number Input Form -->
            <form id="markCompleteForm" action="orders.php" method="POST" class="hidden">
                <input type="hidden" name="mark_complete_batch" id="mark_complete_batch" value="">
                <input type="hidden" name="mark_complete" id="mark_complete" value="">
                <input type="hidden" name="batch_id" id="mark_complete_batch_id" value="">
                <input type="hidden" name="order_id" id="mark_complete_order_id" value="">
                
                <div class="mb-4">
                    <label for="or_number" class="block text-sm font-medium text-gray-700 mb-1">Official Receipt (OR) No:</label>
                    <input type="text" id="or_number" name="or_number" required
                           pattern="[0-9]{7}"
                           inputmode="numeric"
                           maxlength="7"
                           onkeypress="return (event.charCode >= 48 && event.charCode <= 57)"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 7)"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50"
                           placeholder="Enter OR Number (7 digits)">
                    <p class="mt-1 text-xs text-gray-500">Enter the OR number provided by the cashier (7 digits only)</p>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeMarkCompleteModal()"
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                        <i class="fas fa-check mr-1"></i> Mark Complete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900">Update Order Status</h3>
            <form action="orders.php" method="POST" class="mt-4">
                <input type="hidden" name="order_id" id="modal_order_id">
                <div class="mb-4">
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" id="status" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeStatusModal()"
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                        Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.getElementById('menu-button').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
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
    
    // Status modal functions
    function openStatusModal(orderId, currentStatus) {
        document.getElementById('modal_order_id').value = orderId;
        document.getElementById('status').value = currentStatus;
        document.getElementById('statusModal').classList.remove('hidden');
    }
    
    function closeStatusModal() {
        document.getElementById('statusModal').classList.add('hidden');
    }
    
    // Store order data - get all orders for modal display
    let ordersData = <?php 
        // Get all orders (not just paginated) for modal functionality
        $modal_query = "SELECT o.*, u.name as user_name, u.email as user_email, u.user_type, u.role, i.name as item_name, i.price as item_price 
                       FROM orders o 
                       JOIN user_accounts u ON o.user_id = u.id 
                       JOIN inventory i ON o.inventory_id = i.id";
        if (!empty($search)) {
            $modal_search_term = "%$search%";
            $modal_query .= " WHERE (o.order_id LIKE ? OR o.batch_id LIKE ? OR u.name LIKE ? OR i.name LIKE ?)";
            $modal_query .= " ORDER BY o.created_at DESC, o.batch_id, o.id";
            $stmt = $conn->prepare($modal_query);
            $stmt->bind_param("ssss", $modal_search_term, $modal_search_term, $modal_search_term, $modal_search_term);
            $stmt->execute();
            $all_orders_result = $stmt->get_result();
        } else {
            $modal_query .= " ORDER BY o.created_at DESC, o.batch_id, o.id";
            $all_orders_result = $conn->query($modal_query);
        }
        $all_orders = [];
        while ($row = $all_orders_result->fetch_assoc()) {
            $all_orders[] = $row;
        }
        echo json_encode($all_orders); 
    ?>;

    function viewBatchOrder(batchId) {
        const batchOrders = ordersData.filter(o => o.batch_id === batchId);
        if (batchOrders.length === 0) return;
        
        const firstOrder = batchOrders[0];
        let totalAmount = 0;
        let itemsHtml = '';
        
        batchOrders.forEach(order => {
            totalAmount += parseFloat(order.total_price);
            itemsHtml += `
                <tr class="border-b">
                    <td class="py-2">${order.item_name}${order.size ? ' (' + order.size + ')' : ''}</td>
                    <td class="py-2 text-center">${order.quantity}</td>
                    <td class="py-2 text-right">₱${parseFloat(order.total_price).toFixed(2)}</td>
                </tr>
            `;
        });
        
        const content = `
            <div class="space-y-4">
                <div class="bg-blue-50 p-4 rounded">
                    <h4 class="font-medium text-blue-900">Batch Order: ${batchId}</h4>
                    <p class="text-sm text-blue-700">${batchOrders.length} items</p>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-600">Customer:</p>
                        <p class="font-medium">${formatUserName(firstOrder.user_name, firstOrder.user_type || 'student', firstOrder.role || null)}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Email:</p>
                        <p class="font-medium">${firstOrder.user_email}</p>
                    </div>
                </div>
                
                <div>
                    <h4 class="font-medium mb-2">Order Items:</h4>
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="py-2 text-left">Item</th>
                                <th class="py-2 text-center">Qty</th>
                                <th class="py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>${itemsHtml}</tbody>
                        <tfoot class="bg-gray-50 font-bold">
                            <tr>
                                <td class="py-2" colspan="2">TOTAL:</td>
                                <td class="py-2 text-right">₱${totalAmount.toFixed(2)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="flex justify-end gap-3 pt-4 border-t">
                    <button onclick="closeOrderModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        Close
                    </button>
                    <a href="print_batch_receipt.php?batch_id=${batchId}" 
                       target="_blank"
                       class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        <i class="fas fa-print mr-1"></i> Print
                    </a>
                </div>
            </div>
        `;
        
        document.getElementById('orderDetailsContent').innerHTML = content;
        document.getElementById('orderModal').classList.remove('hidden');
    }

    function viewSingleOrder(orderId) {
        const order = ordersData.find(o => o.id == orderId);
        if (!order) return;
        
        const content = `
            <div class="space-y-4">
                <div class="bg-blue-50 p-4 rounded">
                    <h4 class="font-medium text-blue-900">Order #${order.order_id}</h4>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-600">Customer:</p>
                        <p class="font-medium">${formatUserName(order.user_name, order.user_type || 'student', order.role || null)}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Email:</p>
                        <p class="font-medium">${order.user_email}</p>
                    </div>
                </div>
                
                <div class="border rounded p-4">
                    <h4 class="font-medium mb-2">Order Details:</h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Item:</span>
                            <span class="font-medium">${order.item_name}</span>
                        </div>
                        ${order.size ? `<div class="flex justify-between">
                            <span class="text-gray-600">Size:</span>
                            <span class="font-medium">${order.size}</span>
                        </div>` : ''}
                        <div class="flex justify-between">
                            <span class="text-gray-600">Quantity:</span>
                            <span class="font-medium">${order.quantity}</span>
                        </div>
                        <div class="flex justify-between border-t pt-2">
                            <span class="font-bold">Total:</span>
                            <span class="font-bold text-lg">₱${parseFloat(order.total_price).toFixed(2)}</span>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 pt-4 border-t">
                    <button onclick="closeOrderModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        Close
                    </button>
                    ${order.batch_id ? 
                        `<a href="print_batch_receipt.php?batch_id=${order.batch_id}" target="_blank" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            <i class="fas fa-print mr-1"></i> Print
                        </a>` :
                        `<a href="print_order_receipt.php?order_id=${order.order_id}" target="_blank" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            <i class="fas fa-print mr-1"></i> Print
                        </a>`
                    }
                </div>
            </div>
        `;
        
        document.getElementById('orderDetailsContent').innerHTML = content;
        document.getElementById('orderModal').classList.remove('hidden');
    }

    function closeOrderModal() {
        document.getElementById('orderModal').classList.add('hidden');
    }
    
    // Mark Complete Modal Functions
    let currentOrderType = ''; // 'batch' or 'single'
    let currentOrderId = '';
    
    function openMarkCompleteModal(orderId, type) {
        currentOrderType = type;
        currentOrderId = orderId;
        
        // Reset modal state
        document.getElementById('orQuestionSection').classList.remove('hidden');
        document.getElementById('markCompleteForm').classList.add('hidden');
        document.getElementById('or_number').value = '';
        document.getElementById('or_number').required = false;
        
        // Set form fields based on type
        if (type === 'batch') {
            document.getElementById('mark_complete_batch').value = '1';
            document.getElementById('mark_complete_batch_id').value = orderId;
            document.getElementById('mark_complete').value = '';
            document.getElementById('mark_complete_order_id').value = '';
        } else {
            document.getElementById('mark_complete').value = '1';
            document.getElementById('mark_complete_order_id').value = orderId;
            document.getElementById('mark_complete_batch').value = '';
            document.getElementById('mark_complete_batch_id').value = '';
        }
        
        document.getElementById('markCompleteModal').classList.remove('hidden');
    }
    
    function closeMarkCompleteModal() {
        document.getElementById('markCompleteModal').classList.add('hidden');
        // Reset form
        document.getElementById('orQuestionSection').classList.remove('hidden');
        document.getElementById('markCompleteForm').classList.add('hidden');
        document.getElementById('or_number').value = '';
    }
    
    function handleOrNoResponse(hasOrNo) {
        // Show OR number input field
        document.getElementById('orQuestionSection').classList.add('hidden');
        document.getElementById('markCompleteForm').classList.remove('hidden');
        document.getElementById('or_number').required = true;
        document.getElementById('or_number').focus();
    }
    
    // Prevent symbols in search field
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('search');
        if (searchInput) {
            // Remove symbols on input
            searchInput.addEventListener('input', function() {
                this.value = this.value.replace(/[!@#$%^&*()_+={}\[\]:;"'<>?\/\\|`~]/g, '');
            });
            
            // Prevent symbols on paste
            searchInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const cleanedText = pastedText.replace(/[!@#$%^&*()_+={}\[\]:;"'<>?\/\\|`~]/g, '');
                this.value = cleanedText;
            });
        }
    });
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>

