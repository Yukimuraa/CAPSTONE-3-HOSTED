<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is student
require_student();

$page_title = "Order Receipt - CHMSU BAO";
$base_url = "..";

// Check if order ID is provided
if (!isset($_GET['order_id'])) {
    header("Location: orders.php");
    exit();
}

$order_id = sanitize_input($_GET['order_id']);

// Get the first order to check if it has a batch_id
$stmt = $conn->prepare("SELECT o.*, i.name as item_name, i.description as item_description, 
                        i.price as item_price
                        FROM orders o 
                        JOIN inventory i ON o.inventory_id = i.id 
                        WHERE o.order_id = ? AND o.user_id = ?");
$stmt->bind_param("si", $order_id, $_SESSION['user_id']);
$stmt->execute();
$first_order_result = $stmt->get_result();

if ($first_order_result->num_rows === 0) {
    header("Location: orders.php");
    exit();
}

$first_order = $first_order_result->fetch_assoc();

// Get user details
$user_stmt = $conn->prepare("SELECT name, email, profile_pic FROM user_accounts WHERE id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$profile_pic = $user_info['profile_pic'] ?? '';

// Get all orders for this customer - if batch_id exists, get all orders in batch, otherwise get ALL orders for the customer
if (!empty($first_order['batch_id'])) {
    // Get all orders in the batch
    $orders_stmt = $conn->prepare("SELECT o.*, i.name as item_name, i.description as item_description, 
                            i.price as item_price
                            FROM orders o 
                            JOIN inventory i ON o.inventory_id = i.id 
                            WHERE o.batch_id = ? AND o.user_id = ?
                            ORDER BY o.created_at ASC");
    $orders_stmt->bind_param("si", $first_order['batch_id'], $_SESSION['user_id']);
    $orders_stmt->execute();
    $orders_result = $orders_stmt->get_result();
} else {
    // Get ALL orders for this customer (1 customer = 1 receipt)
    $orders_stmt = $conn->prepare("SELECT o.*, i.name as item_name, i.description as item_description, 
                            i.price as item_price
                            FROM orders o 
                            JOIN inventory i ON o.inventory_id = i.id 
                            WHERE o.user_id = ?
                            ORDER BY o.created_at ASC");
    $orders_stmt->bind_param("i", $_SESSION['user_id']);
    $orders_stmt->execute();
    $orders_result = $orders_stmt->get_result();
}

$orders = [];
$total_amount = 0;
$order_datetime = '';
$status = '';

while ($order = $orders_result->fetch_assoc()) {
    $orders[] = $order;
    $total_amount += $order['total_price'];
    if (empty($order_datetime)) {
        $order_datetime = $order['created_at'];
        $status = $order['status'];
    }
}

// Use first order for reference
$order = $first_order;

// Format date
$order_date = date('F j, Y', strtotime($order_datetime));
$order_time = date('h:i A', strtotime($order_datetime));

// Get school information
$school_name = "Carlos Hilado Memorial State University";
$school_address = "Talisay City, Negros Occidental";
$school_contact = "Phone: (034) 495-3461";
$school_email = "bao@chmsu.edu.ph";

// Generate receipt number (if not already in the database)
$receipt_number = "RCPT-" . date('Ymd', strtotime($order_datetime)) . "-" . substr($order_id, -3);
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/student_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Order Receipt</h1>
                <div class="flex items-center gap-3">
                    <?php require_once '../includes/notification_bell.php'; ?>
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
                    <button class="md:hidden rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-500" id="menu-button">
                        <span class="sr-only">Open menu</span>
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Main content -->
        <main class="flex-1 overflow-y-auto p-4">
            <div class="max-w-4xl mx-auto">
                <div class="mb-4 flex justify-between items-center">
                    <a href="orders.php" class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Orders
                    </a>
                </div>
                
                <!-- Receipt -->
                <div id="receipt" class="bg-white rounded-lg shadow overflow-hidden">
                    <!-- Receipt Header -->
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900"><?php echo $school_name; ?></h2>
                                <p class="text-gray-600"><?php echo $school_address; ?></p>
                                <p class="text-gray-600"><?php echo $school_contact; ?></p>
                                <p class="text-gray-600"><?php echo $school_email; ?></p>
                            </div>
                            <div class="text-right">
                                <h3 class="text-xl font-bold text-gray-900">RECEIPT</h3>
                                <p class="text-gray-600"><?php echo $receipt_number; ?></p>
                                <?php if (!empty($first_order['batch_id'])): ?>
                                    <p class="text-gray-600 font-semibold">Batch ID: <?php echo htmlspecialchars($first_order['batch_id']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($first_order['or_number'])): ?>
                                    <p class="text-gray-600 font-semibold text-green-600">OR No: <?php echo htmlspecialchars($first_order['or_number']); ?></p>
                                <?php endif; ?>
                                <p class="text-gray-600">Date: <?php echo $order_date; ?></p>
                                <p class="text-gray-600">Time: <?php echo $order_time; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Customer Information -->
                    <div class="p-6 border-b border-gray-200 bg-gray-50">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Customer Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-600">Name:</p>
                                <p class="font-medium"><?php echo htmlspecialchars($user_info['name']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Email:</p>
                                <p class="font-medium"><?php echo htmlspecialchars($user_info['email']); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Order Details -->
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Order Details</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order ID</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($orders as $order_item): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $order_item['order_id']; ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <div>
                                                <p class="font-medium"><?php echo htmlspecialchars($order_item['item_name']); ?></p>
                                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars(substr($order_item['item_description'] ?? '', 0, 50)) . (strlen($order_item['item_description'] ?? '') > 50 ? '...' : ''); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                            <?php echo !empty($order_item['size']) ? htmlspecialchars($order_item['size']) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center"><?php echo $order_item['quantity']; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">₱<?php echo number_format($order_item['item_price'], 2); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">₱<?php echo number_format($order_item['total_price'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">Subtotal:</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">₱<?php echo number_format($total_amount, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 whitespace-nowrap text-base font-bold text-gray-900 text-right">Total:</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-base font-bold text-gray-900 text-right">₱<?php echo number_format($total_amount, 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Payment Status -->
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Payment Status</h3>
                                <p class="text-gray-600 mt-1">
                                    <?php if ($status == 'pending'): ?>
                                        Payment pending. Please proceed to the cashier for payment.
                                    <?php elseif ($status == 'approved'): ?>
                                        Payment received. Thank you for your order.
                                    <?php elseif ($status == 'completed'): ?>
                                        Order completed. Thank you for your business.
                                    <?php else: ?>
                                        Order rejected. Please contact the BAO for more information.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <?php if ($status == 'pending'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        Pending
                                    </span>
                                <?php elseif ($status == 'approved'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        Approved
                                    </span>
                                <?php elseif ($status == 'completed'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        Completed
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        Rejected
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Footer -->
                    <div class="p-6 bg-gray-50">
                        <div class="text-center">
                            <p class="text-sm text-gray-600">Thank you for your order!</p>
                            <p class="text-xs text-gray-500 mt-2">This receipt was generated on <?php echo date('F j, Y h:i A'); ?></p>
                            <p class="text-xs text-gray-500 mt-1">For any questions, please contact the Business Affairs Office.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.getElementById('menu-button').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
    });
</script>

<style>
    @media print {
        body * {
            visibility: hidden;
        }
        #receipt, #receipt * {
            visibility: visible;
        }
        #receipt {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
        .shadow {
            box-shadow: none !important;
        }
    }
</style>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
