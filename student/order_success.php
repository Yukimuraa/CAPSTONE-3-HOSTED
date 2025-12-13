<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is student
require_student();

$page_title = "Order Success - CHMSU BAO";
$base_url = "..";

// Get user profile picture
$user_stmt = $conn->prepare("SELECT profile_pic FROM user_accounts WHERE id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$profile_pic = $user_data['profile_pic'] ?? '';

// Get batch ID from URL
if (!isset($_GET['batch_id'])) {
    header("Location: orders.php");
    exit();
}

$batch_id = sanitize_input($_GET['batch_id']);

// Get orders for this batch
$stmt = $conn->prepare("SELECT o.*, i.name as item_name 
                        FROM orders o 
                        JOIN inventory i ON o.inventory_id = i.id 
                        WHERE o.batch_id = ? AND o.user_id = ? 
                        ORDER BY o.created_at ASC");
$stmt->bind_param("si", $batch_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
$total_amount = 0;

while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
    $total_amount += $row['total_price'];
}

// If no orders found, redirect
if (count($orders) === 0) {
    header("Location: orders.php");
    exit();
}
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/student_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Order Confirmation</h1>
                <div class="flex items-center">
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
            <div class="max-w-3xl mx-auto">
                <!-- Success Message -->
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-emerald-600 px-6 py-8 text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-full mb-4">
                            <i class="fas fa-check text-emerald-600 text-3xl"></i>
                        </div>
                        <h2 class="text-3xl font-bold text-white mb-2">Order Placed Successfully!</h2>
                        <p class="text-emerald-100">Your order has been submitted and is pending approval</p>
                    </div>
                    
                    <div class="px-6 py-8">
                        <!-- Order Info -->
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-600">Batch Order ID:</span>
                                <span class="text-lg font-bold text-gray-900"><?php echo $batch_id; ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-600">Order Date:</span>
                                <span class="text-sm text-gray-900"><?php echo date('F j, Y g:i A'); ?></span>
                            </div>
                        </div>
                        
                        <!-- Order Items -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Order Items</h3>
                            <div class="space-y-3">
                                <?php foreach ($orders as $order): ?>
                                    <div class="flex justify-between items-start py-3 border-b border-gray-200">
                                        <div class="flex-1">
                                            <p class="font-medium text-gray-900"><?php echo $order['item_name']; ?></p>
                                            <p class="text-sm text-gray-600">
                                                Quantity: <?php echo $order['quantity']; ?>
                                                <?php if (!empty($order['size'])): ?>
                                                    | Size: <?php echo $order['size']; ?>
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-xs text-gray-500 mt-1">Order ID: <?php echo $order['order_id']; ?></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-gray-900">₱<?php echo number_format($order['total_price'], 2); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Total -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <div class="flex justify-between items-center">
                                <span class="text-lg font-medium text-gray-900">Total Amount:</span>
                                <span class="text-2xl font-bold text-emerald-600">₱<?php echo number_format($total_amount, 2); ?></span>
                            </div>
                        </div>
                        
                        <!-- Important Notice -->
                        <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-info-circle text-blue-400"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800">Next Steps</h3>
                                    <div class="mt-2 text-sm text-blue-700">
                                        <ol class="list-decimal list-inside space-y-1">
                                            <li>Your order is now pending approval from the Business Affairs Office</li>
                                            <li>Once you submit request,you can go to the BAO Office to get the order of payment slip</li>
                                            <li>Then you can go the cashier office to pay</li>
                                            <li>Then go back the BAO Office  for approval and claim your items</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="flex flex-col sm:flex-row gap-3">
                            <a href="batch_receipt.php?batch_id=<?php echo $batch_id; ?>" class="flex-1 inline-flex justify-center items-center px-6 py-3 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-emerald-600 hover:bg-emerald-700">
                                <i class="fas fa-receipt mr-2"></i> View Receipt
                            </a>
                            <a href="orders.php" class="flex-1 inline-flex justify-center items-center px-6 py-3 border border-gray-300 rounded-md shadow-sm text-base font-medium text-gray-700 bg-white hover:bg-gray-50">
                                <i class="fas fa-list mr-2"></i> View All Orders
                            </a>
                            <a href="inventory.php" class="flex-1 inline-flex justify-center items-center px-6 py-3 border border-gray-300 rounded-md shadow-sm text-base font-medium text-gray-700 bg-white hover:bg-gray-50">
                                <i class="fas fa-shopping-bag mr-2"></i> Continue Shopping
                            </a>
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

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>



