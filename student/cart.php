<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is student
require_student();

$page_title = "Shopping Cart - CHMSU BAO";
$base_url = "..";

$error = '';
$success = '';

// Get user profile picture
$user_stmt = $conn->prepare("SELECT profile_pic FROM user_accounts WHERE id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$profile_pic = $user_data['profile_pic'] ?? '';

// Handle cart updates and deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update') {
            $cart_id = intval($_POST['cart_id']);
            $quantity = intval($_POST['quantity']);
            
            // Get item details to validate quantity
            $check_stmt = $conn->prepare("SELECT c.*, i.quantity as max_quantity FROM cart c JOIN inventory i ON c.inventory_id = i.id WHERE c.id = ? AND c.user_id = ?");
            $check_stmt->bind_param("ii", $cart_id, $_SESSION['user_id']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $cart_item = $check_result->fetch_assoc();
                
                if ($quantity > 0 && $quantity <= $cart_item['max_quantity']) {
                    $update_stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
                    $update_stmt->bind_param("iii", $quantity, $cart_id, $_SESSION['user_id']);
                    $update_stmt->execute();
                    $success = 'Cart updated successfully';
                } else {
                    $error = 'Invalid quantity';
                }
            }
        } elseif ($_POST['action'] === 'remove') {
            $cart_id = intval($_POST['cart_id']);
            $delete_stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
            $delete_stmt->bind_param("ii", $cart_id, $_SESSION['user_id']);
            $delete_stmt->execute();
            $success = 'Item removed from cart';
        } elseif ($_POST['action'] === 'clear') {
            $clear_stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
            $clear_stmt->bind_param("i", $_SESSION['user_id']);
            $clear_stmt->execute();
            $success = 'Cart cleared successfully';
        }
    }
}

// Get cart items
$stmt = $conn->prepare("SELECT c.*, i.name, i.description, i.price, i.quantity as available_quantity, i.image_path 
                        FROM cart c 
                        JOIN inventory i ON c.inventory_id = i.id 
                        WHERE c.user_id = ? 
                        ORDER BY c.added_at DESC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$cart_items = [];
$total_price = 0;

while ($row = $result->fetch_assoc()) {
    $cart_items[] = $row;
    $total_price += $row['price'] * $row['quantity'];
}

// Validate P.E. T-shirt and P.E. Pants requirement: They must be ordered together with matching quantities
$pe_tshirt_quantity = 0;
$pe_pants_quantity = 0;
$pe_tshirt_name = '';
$pe_pants_name = '';

foreach ($cart_items as $item) {
    $item_name_lower = strtolower($item['name']);
    // Check for P.E. T-shirt (various formats: "P.E T-Shirt", "P.E. T-Shirt", "PE T-Shirt", etc.)
    if ((stripos($item_name_lower, 'p.e') !== false || stripos($item_name_lower, 'pe') !== false) && 
        (stripos($item_name_lower, 't-shirt') !== false || stripos($item_name_lower, 'tshirt') !== false)) {
        $pe_tshirt_quantity += $item['quantity'];
        if (empty($pe_tshirt_name)) {
            $pe_tshirt_name = $item['name'];
        }
    }
    // Check for P.E. Pants (various formats: "P.E - Pants", "P.E. Pants", "PE Pants", etc.)
    if ((stripos($item_name_lower, 'p.e') !== false || stripos($item_name_lower, 'pe') !== false) && 
        stripos($item_name_lower, 'pants') !== false) {
        $pe_pants_quantity += $item['quantity'];
        if (empty($pe_pants_name)) {
            $pe_pants_name = $item['name'];
        }
    }
}

$has_pe_tshirt = $pe_tshirt_quantity > 0;
$has_pe_pants = $pe_pants_quantity > 0;
$pe_quantities_match = ($pe_tshirt_quantity === $pe_pants_quantity);
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/student_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Shopping Cart</h1>
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
            <div class="max-w-7xl mx-auto">
                <?php if (!empty($error)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                        <p><?php echo $error; ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                        <p><?php echo $success; ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($has_pe_tshirt && !$has_pe_pants): ?>
                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 p-4 mb-4">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mr-3 mt-0.5"></i>
                            <div>
                                <p class="font-semibold">P.E. Pants Required</p>
                                <p class="text-sm mt-1">You have <strong><?php echo htmlspecialchars($pe_tshirt_name); ?></strong> (Quantity: <?php echo $pe_tshirt_quantity; ?>) in your cart. P.E. Pants is required when ordering P.E. T-shirt. Please add <?php echo $pe_tshirt_quantity; ?> P.E. Pants to your cart before proceeding to checkout.</p>
                            </div>
                        </div>
                    </div>
                <?php elseif ($has_pe_pants && !$has_pe_tshirt): ?>
                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 p-4 mb-4">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mr-3 mt-0.5"></i>
                            <div>
                                <p class="font-semibold">P.E. T-shirt Required</p>
                                <p class="text-sm mt-1">You have <strong><?php echo htmlspecialchars($pe_pants_name); ?></strong> (Quantity: <?php echo $pe_pants_quantity; ?>) in your cart. P.E. T-shirt is required when ordering P.E. Pants. Please add <?php echo $pe_pants_quantity; ?> P.E. T-shirt(s) to your cart before proceeding to checkout.</p>
                            </div>
                        </div>
                    </div>
                <?php elseif ($has_pe_tshirt && $has_pe_pants && !$pe_quantities_match): ?>
                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 p-4 mb-4">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mr-3 mt-0.5"></i>
                            <div>
                                <p class="font-semibold">Quantity Mismatch</p>
                                <p class="text-sm mt-1">You have <strong><?php echo $pe_tshirt_quantity; ?> P.E. T-shirt(s)</strong> but only <strong><?php echo $pe_pants_quantity; ?> P.E. Pants</strong> in your cart. The quantities must match. Please adjust your cart so you have <?php echo $pe_tshirt_quantity; ?> P.E. Pants to match your <?php echo $pe_tshirt_quantity; ?> P.E. T-shirt(s).</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (count($cart_items) > 0): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Cart Items -->
                        <div class="lg:col-span-2">
                            <div class="bg-white rounded-lg shadow">
                                <div class="px-4 py-5 border-b border-gray-200 sm:px-6 flex justify-between items-center">
                                    <h3 class="text-lg font-medium text-gray-900">Cart Items (<?php echo count($cart_items); ?>)</h3>
                                    <form method="POST" action="" class="inline">
                                        <input type="hidden" name="action" value="clear">
                                        <button type="submit" class="text-sm text-red-600 hover:text-red-800" onclick="return confirm('Are you sure you want to clear your cart?')">
                                            <i class="fas fa-trash mr-1"></i> Clear Cart
                                        </button>
                                    </form>
                                </div>
                                <div class="divide-y divide-gray-200">
                                    <?php foreach ($cart_items as $item): ?>
                                        <div class="p-4 sm:p-6">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-24 w-24 bg-gray-100 rounded-md flex items-center justify-center">
                                                    <?php if (!empty($item['image_path']) && file_exists('../' . $item['image_path'])): ?>
                                                        <img src="<?php echo '../' . $item['image_path']; ?>" alt="<?php echo $item['name']; ?>" class="h-full w-full object-contain rounded-md">
                                                    <?php else: ?>
                                                        <i class="fas fa-box text-gray-400 text-3xl"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4 flex-1">
                                                    <h4 class="text-lg font-medium text-gray-900"><?php echo $item['name']; ?></h4>
                                                    <p class="text-sm text-gray-500"><?php echo $item['description']; ?></p>
                                                    <?php if (!empty($item['size'])): ?>
                                                        <p class="text-sm text-gray-600 mt-1">Size: <span class="font-medium"><?php echo $item['size']; ?></span></p>
                                                    <?php endif; ?>
                                                    <p class="text-lg font-bold text-gray-900 mt-2">₱<?php echo number_format($item['price'], 2); ?></p>
                                                </div>
                                                <div class="ml-4">
                                                    <form method="POST" action="" class="flex items-center space-x-2">
                                                        <input type="hidden" name="action" value="update">
                                                        <input type="hidden" name="cart_id" value="<?php echo $item['id']; ?>">
                                                        <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" 
                                                               min="1" max="<?php echo $item['available_quantity']; ?>"
                                                               class="w-20 px-2 py-1 border border-gray-300 rounded-md text-center"
                                                               onchange="this.form.submit()">
                                                        <span class="text-sm text-gray-500">/ <?php echo $item['available_quantity']; ?></span>
                                                    </form>
                                                    <p class="text-sm text-gray-600 mt-1 text-right">
                                                        Subtotal: ₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                                    </p>
                                                    <form method="POST" action="" class="mt-2">
                                                        <input type="hidden" name="action" value="remove">
                                                        <input type="hidden" name="cart_id" value="<?php echo $item['id']; ?>">
                                                        <button type="submit" class="text-sm text-red-600 hover:text-red-800">
                                                            <i class="fas fa-trash mr-1"></i> Remove
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <a href="inventory.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    <i class="fas fa-arrow-left mr-2"></i> Continue Shopping
                                </a>
                            </div>
                        </div>
                        
                        <!-- Order Summary -->
                        <div class="lg:col-span-1">
                            <div class="bg-white rounded-lg shadow sticky top-4">
                                <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                                    <h3 class="text-lg font-medium text-gray-900">Order Summary</h3>
                                </div>
                                <div class="px-4 py-5 sm:p-6">
                                    <div class="space-y-4">
                                        <div class="flex justify-between text-base">
                                            <span class="text-gray-600">Subtotal:</span>
                                            <span class="font-medium">₱<?php echo number_format($total_price, 2); ?></span>
                                        </div>
                                        <div class="flex justify-between text-base">
                                            <span class="text-gray-600">Items:</span>
                                            <span class="font-medium"><?php echo count($cart_items); ?></span>
                                        </div>
                                        <div class="pt-4 border-t border-gray-200">
                                            <div class="flex justify-between text-lg font-bold">
                                                <span>Total:</span>
                                                <span class="text-emerald-600">₱<?php echo number_format($total_price, 2); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="pt-4">
                                            <?php if (($has_pe_tshirt && !$has_pe_pants) || ($has_pe_pants && !$has_pe_tshirt) || ($has_pe_tshirt && $has_pe_pants && !$pe_quantities_match)): ?>
                                                <button disabled class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-gray-400 cursor-not-allowed opacity-50">
                                                    <i class="fas fa-ban mr-2"></i> Cannot Proceed to Checkout
                                                </button>
                                                <div class="mt-3 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                                                    <p class="text-xs text-yellow-800">
                                                        <i class="fas fa-info-circle mr-1"></i>
                                                        <?php if ($has_pe_tshirt && !$has_pe_pants): ?>
                                                            P.E. Pants is required when ordering P.E. T-shirt. Please add <?php echo $pe_tshirt_quantity; ?> P.E. Pants to your cart.
                                                        <?php elseif ($has_pe_pants && !$has_pe_tshirt): ?>
                                                            P.E. T-shirt is required when ordering P.E. Pants. Please add <?php echo $pe_pants_quantity; ?> P.E. T-shirt(s) to your cart.
                                                        <?php else: ?>
                                                            P.E. T-shirt quantity (<?php echo $pe_tshirt_quantity; ?>) must match P.E. Pants quantity (<?php echo $pe_pants_quantity; ?>). Please adjust your cart.
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            <?php else: ?>
                                                <a href="checkout.php" class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                    <i class="fas fa-check mr-2"></i> Proceed to Checkout
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                                            <p class="text-xs text-blue-800">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                you will need to proceed to the BAO Office to take your order of payment slip after placing your order. and go back to BAO Office
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12 bg-white rounded-lg shadow">
                        <i class="fas fa-shopping-cart text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-xl font-medium text-gray-900 mb-2">Your cart is empty</h3>
                        <p class="text-gray-500 mb-6">Add items to your cart to get started</p>
                        <a href="inventory.php" class="inline-flex items-center px-6 py-3 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-emerald-600 hover:bg-emerald-700">
                            <i class="fas fa-shopping-bag mr-2"></i> Browse Items
                        </a>
                    </div>
                <?php endif; ?>
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


