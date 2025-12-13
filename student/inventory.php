<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is student
require_student();

$page_title = "Order Items - CHMSU BAO";
$base_url = "..";

// Get all available inventory items
$query = "SELECT * FROM inventory ORDER BY name";
$result = $conn->query($query);

// Get cart items for current user to calculate available stock
$cart_items_query = $conn->prepare("SELECT inventory_id, size, quantity FROM cart WHERE user_id = ?");
$cart_items_query->bind_param("i", $_SESSION['user_id']);
$cart_items_query->execute();
$cart_items_result = $cart_items_query->get_result();

// Build a map of cart quantities by item_id and size
$cart_quantities = [];
while ($cart_item = $cart_items_result->fetch_assoc()) {
    $key = $cart_item['inventory_id'] . '_' . ($cart_item['size'] ?? 'no_size');
    if (!isset($cart_quantities[$key])) {
        $cart_quantities[$key] = 0;
    }
    $cart_quantities[$key] += $cart_item['quantity'];
}

// Create images directory if it doesn't exist
$upload_dir = "../uploads/inventory/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Get user profile picture
$user_stmt = $conn->prepare("SELECT profile_pic FROM user_accounts WHERE id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$profile_pic = $user_data['profile_pic'] ?? '';

// Get user role from session
$user_role = $_SESSION['user_sessions']['student']['role'] ?? 'student';

// If role is not in session, fetch it from database
if (empty($user_role) || $user_role === null) {
    $role_stmt = $conn->prepare("SELECT role FROM user_accounts WHERE id = ?");
    $role_stmt->bind_param("i", $_SESSION['user_id']);
    $role_stmt->execute();
    $role_result = $role_stmt->get_result();
    if ($role_result->num_rows > 0) {
        $role_data = $role_result->fetch_assoc();
        $user_role = $role_data['role'] ?? 'student';
        // Update session with role
        $_SESSION['user_sessions']['student']['role'] = $user_role;
    } else {
        $user_role = 'student'; // Default fallback
    }
}

// Filter inventory items based on user role
$filtered_items = [];
while ($item = $result->fetch_assoc()) {
    $item_name_lower = strtolower($item['name']);
    
    // Check if item is a faculty ID card/cord
    $is_faculty_id_card = (strpos($item_name_lower, 'faculty') !== false && 
                          (strpos($item_name_lower, 'id') !== false || 
                           strpos($item_name_lower, 'cord') !== false ||
                           strpos($item_name_lower, 'card') !== false));
    
    // Check if item is a staff ID card/cord
    $is_staff_id_card = (strpos($item_name_lower, 'staff') !== false && 
                        (strpos($item_name_lower, 'id') !== false || 
                         strpos($item_name_lower, 'cord') !== false ||
                         strpos($item_name_lower, 'card') !== false));
    
    // Apply filtering based on user role
    if ($user_role === 'student') {
        // Students: Hide faculty ID cards/cords and staff ID cards/cords, show everything else
        if ($is_faculty_id_card || $is_staff_id_card) {
            continue; // Skip this item
        }
    } elseif ($user_role === 'faculty') {
        // Faculty: Only show faculty ID cards/cords, hide everything else
        if (!$is_faculty_id_card) {
            continue; // Skip all items that are not faculty ID cards/cords
        }
    } elseif ($user_role === 'staff') {
        // Staff: Only show staff ID cards/cords, hide everything else
        if (!$is_staff_id_card) {
            continue; // Skip all items that are not staff ID cards/cords
        }
    }
    
    // If item passed the filter, add it to filtered_items
    $filtered_items[] = $item;
}
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
    <?php include '../includes/student_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top header -->
        <header class="bg-white shadow-sm z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900">Available Items</h1>
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
                    <span class="text-gray-700 hidden sm:inline"><?php echo $_SESSION['user_name']; ?></span>
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
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-xl font-bold tracking-tight text-gray-900">Browse and Order Items</h2>
                        <p class="text-gray-500">Order school-related items from the Business Affairs Office</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-box text-gray-500"></i>
                            <span class="text-sm text-gray-500">
                                <?php 
                                $in_stock_query = "SELECT COUNT(*) as count FROM inventory WHERE in_stock = 1";
                                $in_stock_result = $conn->query($in_stock_query);
                                $in_stock_count = $in_stock_result->fetch_assoc()['count'];
                                echo $in_stock_count; 
                                ?> 
                                items available
                            </span>
                        </div>
                        <a href="cart.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-black bg-emerald-600 hover:bg-emerald-700">
                            <i class="fas fa-shopping-cart mr-2"></i> 
                            View Cart 
                            <span id="cart-badge" class="ml-2 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-emerald-600 bg-white rounded-full">
                                <?php
                                $cart_count_query = $conn->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
                                $cart_count_query->bind_param("i", $_SESSION['user_id']);
                                $cart_count_query->execute();
                                $cart_count = $cart_count_query->get_result()->fetch_assoc()['count'];
                                echo $cart_count;
                                ?>
                            </span>
                        </a>
                    </div>
                </div>
                
                <!-- Items grid -->
                <div class="grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                    <?php if (count($filtered_items) > 0): ?>
                        <?php foreach ($filtered_items as $item): ?>
                            <div class="bg-white rounded-lg shadow overflow-hidden <?php echo $item['in_stock'] ? '' : 'opacity-70'; ?>">
                                <div class="p-4">
                                    <div class="h-48 w-full bg-gray-100 rounded-md flex items-center justify-center mb-4 overflow-hidden">
                                        <?php if (!empty($item['image_path']) && file_exists('../' . $item['image_path'])): ?>
                                            <img src="<?php echo '../' . $item['image_path']; ?>" alt="<?php echo $item['name']; ?>" class="h-full w-full object-contain">
                                        <?php elseif (!empty($item['image_path'])): ?>
                                            <img src="<?php echo $item['image_path']; ?>" alt="<?php echo $item['name']; ?>" class="h-full w-full object-contain">
                                        <?php else: ?>
                                            <!-- Default image based on item type -->
                                            <?php 
                                            $icon_class = 'fa-box';
                                            $item_name_lower = strtolower($item['name']);
                                            
                                            if (strpos($item_name_lower, 'uniform') !== false) {
                                                $icon_class = 'fa-tshirt';
                                            } elseif (strpos($item_name_lower, 'cord') !== false) {
                                                $icon_class = 'fa-graduation-cap';
                                            } elseif (strpos($item_name_lower, 'id') !== false || strpos($item_name_lower, 'lace') !== false) {
                                                $icon_class = 'fa-id-card';
                                            } elseif (strpos($item_name_lower, 'pin') !== false) {
                                                $icon_class = 'fa-thumbtack';
                                            } elseif (strpos($item_name_lower, 'jacket') !== false) {
                                                $icon_class = 'fa-vest';
                                            } elseif (strpos($item_name_lower, 'patch') !== false || strpos($item_name_lower, 'logo') !== false) {
                                                $icon_class = 'fa-certificate';
                                            }
                                            ?>
                                            <i class="fas <?php echo $icon_class; ?> text-gray-400 text-5xl"></i>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-900"><?php echo $item['name']; ?></h3>
                                    <p class="text-sm text-gray-500 mb-4"><?php echo $item['description']; ?></p>
                                    <div class="flex justify-between items-center">
                                        <p class="font-medium text-lg">₱<?php echo number_format($item['price'], 2); ?></p>
                                        <div class="text-right">
                                            <?php if ($item['in_stock']): ?>
                                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800">
                                                    In Stock
                                                </span>
                                                <div class="mt-1">
                                                    <span class="text-xs font-medium text-gray-600">
                                                        Stock: <span class="font-bold text-blue-600"><?php echo $item['quantity']; ?></span>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800">
                                                    Out of Stock
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-4 py-3 bg-gray-50 flex justify-center items-center">
                                    <button onclick="openQuickAddModal(<?php echo htmlspecialchars(json_encode($item)); ?>)" 
                                            class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 <?php echo $item['in_stock'] ? '' : 'opacity-50 cursor-not-allowed'; ?>"
                                            <?php echo $item['in_stock'] ? '' : 'disabled'; ?>>
                                        <i class="fas fa-cart-plus mr-2"></i> Add to Cart
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-3 text-center py-8 bg-white rounded-lg shadow">
                            <i class="fas fa-box-open text-gray-400 text-4xl mb-2"></i>
                            <p class="text-gray-500">No inventory items available at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Quick Add to Cart Modal -->
<div id="quickAddModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md mx-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Add to Cart</h3>
            <button onclick="closeQuickAddModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="quick-add-form">
            <input type="hidden" id="modal-item-id" name="item_id">
            
            <div class="mb-4">
                <h4 class="font-medium text-gray-900" id="modal-item-name"></h4>
                <p class="text-sm text-gray-600" id="modal-item-price"></p>
            </div>
            
            <div id="modal-size-section" class="mb-4 hidden">
                <label for="modal-size" class="block text-sm font-medium text-gray-700 mb-1">Size</label>
                <select id="modal-size" name="size" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Select Size</option>
                </select>
            </div>
            
            <div class="mb-4">
                <label for="modal-quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                <input type="number" id="modal-quantity" name="quantity" value="1" min="1" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closeQuickAddModal()" 
                        class="flex-1 px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="flex-1 px-4 py-2 border border-transparent rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-cart-plus mr-2"></i> Add to Cart
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Notification Toast Modal -->
<div id="notificationToast" class="fixed top-4 right-4 z-50 transform translate-x-full transition-transform duration-300 ease-in-out">
    <div class="bg-white rounded-lg shadow-lg border-l-4 p-4 min-w-[300px] max-w-md">
        <div class="flex items-start">
            <div id="notificationIcon" class="flex-shrink-0 mr-3">
                <i class="fas fa-check-circle text-2xl"></i>
            </div>
            <div class="flex-1">
                <p id="notificationMessage" class="text-sm font-medium text-gray-900"></p>
            </div>
            <button onclick="hideNotification()" class="ml-3 text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.getElementById('menu-button').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
    });
    
    // Quick Add Modal Functions
    let currentItem = null;
    
    function openQuickAddModal(item) {
        currentItem = item;
        document.getElementById('modal-item-id').value = item.id;
        document.getElementById('modal-item-name').textContent = item.name;
        document.getElementById('modal-item-price').textContent = '₱' + parseFloat(item.price).toFixed(2);
        document.getElementById('modal-quantity').max = item.quantity;
        
        // Check if item needs size selection
        const clothingKeywords = ['shirt', 't-shirt', 'tshirt', 'pants', 'shorts', 'jacket', 'hoodie', 'polo', 'jersey', 'uniform'];
        let needsSize = false;
        
        for (let keyword of clothingKeywords) {
            if (item.name.toLowerCase().includes(keyword)) {
                needsSize = true;
                break;
            }
        }
        
        const sizeSection = document.getElementById('modal-size-section');
        const sizeSelect = document.getElementById('modal-size');
        
        if (needsSize) {
            sizeSection.classList.remove('hidden');
            sizeSelect.required = true;
            
            // Clear existing options except first
            sizeSelect.innerHTML = '<option value="">Select Size</option>';
            
            // Parse size_quantities for later use
            let sizeQuantities = {};
            if (item.size_quantities) {
                try {
                    sizeQuantities = JSON.parse(item.size_quantities);
                } catch (e) {
                    console.error('Error parsing size_quantities:', e);
                }
            }
            
            // Store sizeQuantities and cart stock data for validation
            sizeSelect.dataset.sizeQuantities = JSON.stringify(sizeQuantities);
            
            // Fetch cart stock first, then display sizes with accurate available stock
            fetch('get_cart_stock.php?item_id=' + item.id)
                .then(response => response.json())
                .then(cartStock => {
                    // Get all sizes from both sizes array and size_quantities object
                    let allSizes = [];
                    
                    // First, get sizes from sizes array if it exists
                    if (item.sizes) {
                        try {
                            const sizesArray = JSON.parse(item.sizes);
                            if (Array.isArray(sizesArray)) {
                                allSizes = [...sizesArray];
                            }
                        } catch (e) {
                            console.error('Error parsing sizes:', e);
                        }
                    }
                    
                    // Then, add any sizes from size_quantities that aren't already in the list
                    if (sizeQuantities && typeof sizeQuantities === 'object') {
                        Object.keys(sizeQuantities).forEach(size => {
                            if (!allSizes.includes(size)) {
                                allSizes.push(size);
                            }
                        });
                    }
                    
                    // Sort sizes in a logical order
                    const sizeOrder = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
                    allSizes.sort((a, b) => {
                        const indexA = sizeOrder.indexOf(a);
                        const indexB = sizeOrder.indexOf(b);
                        if (indexA !== -1 && indexB !== -1) return indexA - indexB;
                        if (indexA !== -1) return -1;
                        if (indexB !== -1) return 1;
                        return a.localeCompare(b);
                    });
                    
                    // Show all sizes with available stock
                    allSizes.forEach(size => {
                        const totalStock = sizeQuantities[size] || 0;
                        const inCart = (cartStock[size] || 0);
                        const availableStock = totalStock - inCart;
                        
                        const option = document.createElement('option');
                        option.value = size;
                        // Display available stock (updates after adding to cart)
                        option.textContent = size + ' (Stock: ' + availableStock + ')';
                        // Store both for validation
                        option.dataset.totalStock = totalStock;
                        option.dataset.availableStock = availableStock;
                        option.dataset.itemId = item.id;
                        
                        // Disable if no stock available
                        if (availableStock <= 0) {
                            option.disabled = true;
                            option.style.color = '#9CA3AF';
                        }
                        
                        sizeSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error fetching cart stock:', error);
                    // Fallback: show sizes with total stock if fetch fails
                    // Get all sizes from both sizes array and size_quantities object
                    let allSizes = [];
                    
                    // First, get sizes from sizes array if it exists
                    if (item.sizes) {
                        try {
                            const sizesArray = JSON.parse(item.sizes);
                            if (Array.isArray(sizesArray)) {
                                allSizes = [...sizesArray];
                            }
                        } catch (e) {
                            console.error('Error parsing sizes:', e);
                        }
                    }
                    
                    // Then, add any sizes from size_quantities that aren't already in the list
                    if (sizeQuantities && typeof sizeQuantities === 'object') {
                        Object.keys(sizeQuantities).forEach(size => {
                            if (!allSizes.includes(size)) {
                                allSizes.push(size);
                            }
                        });
                    }
                    
                    // Sort sizes in a logical order
                    const sizeOrder = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
                    allSizes.sort((a, b) => {
                        const indexA = sizeOrder.indexOf(a);
                        const indexB = sizeOrder.indexOf(b);
                        if (indexA !== -1 && indexB !== -1) return indexA - indexB;
                        if (indexA !== -1) return -1;
                        if (indexB !== -1) return 1;
                        return a.localeCompare(b);
                    });
                    
                    allSizes.forEach(size => {
                        const totalStock = sizeQuantities[size] || 0;
                        
                        const option = document.createElement('option');
                        option.value = size;
                        option.textContent = size + ' (Stock: ' + totalStock + ')';
                        option.dataset.totalStock = totalStock;
                        option.dataset.availableStock = totalStock;
                        option.dataset.itemId = item.id;
                        
                        if (totalStock <= 0) {
                            option.disabled = true;
                            option.style.color = '#9CA3AF';
                        }
                        
                        sizeSelect.appendChild(option);
                    });
                });
            
            // Update quantity max when size is selected - use pre-calculated available stock
            sizeSelect.onchange = function() {
                const quantityInput = document.getElementById('modal-quantity');
                const selectedOption = this.options[this.selectedIndex];
                
                if (selectedOption && selectedOption.dataset.availableStock) {
                    const availableStock = parseInt(selectedOption.dataset.availableStock);
                    
                    // Update max quantity based on available stock
                    quantityInput.max = Math.max(0, availableStock);
                    quantityInput.value = Math.min(parseInt(quantityInput.value) || 1, Math.max(1, availableStock));
                    
                    // Show alert if no stock available
                    if (availableStock <= 0) {
                        alert('This size is currently unavailable. Please select another size.');
                        this.value = '';
                        quantityInput.max = 0;
                        quantityInput.value = 1;
                    }
                } else {
                    quantityInput.max = item.quantity;
                }
            };
        } else {
            sizeSection.classList.add('hidden');
            sizeSelect.required = false;
        }
        
        document.getElementById('quickAddModal').classList.remove('hidden');
    }
    
    function closeQuickAddModal() {
        document.getElementById('quickAddModal').classList.add('hidden');
        document.getElementById('quick-add-form').reset();
    }
    
    // Notification Toast Functions
    function showNotification(message, type = 'success') {
        const toast = document.getElementById('notificationToast');
        const icon = document.getElementById('notificationIcon');
        const messageEl = document.getElementById('notificationMessage');
        
        // Set message
        messageEl.textContent = message;
        
        // Set icon and color based on type
        if (type === 'success') {
            icon.innerHTML = '<i class="fas fa-check-circle text-2xl text-emerald-500"></i>';
            toast.querySelector('.border-l-4').classList.remove('border-red-500', 'border-yellow-500');
            toast.querySelector('.border-l-4').classList.add('border-emerald-500');
        } else if (type === 'error') {
            icon.innerHTML = '<i class="fas fa-exclamation-circle text-2xl text-red-500"></i>';
            toast.querySelector('.border-l-4').classList.remove('border-emerald-500', 'border-yellow-500');
            toast.querySelector('.border-l-4').classList.add('border-red-500');
        } else {
            icon.innerHTML = '<i class="fas fa-info-circle text-2xl text-yellow-500"></i>';
            toast.querySelector('.border-l-4').classList.remove('border-emerald-500', 'border-red-500');
            toast.querySelector('.border-l-4').classList.add('border-yellow-500');
        }
        
        // Show notification
        toast.classList.remove('translate-x-full');
        toast.classList.add('translate-x-0');
        
        // Auto hide after 3 seconds
        setTimeout(() => {
            hideNotification();
        }, 3000);
    }
    
    function hideNotification() {
        const toast = document.getElementById('notificationToast');
        toast.classList.remove('translate-x-0');
        toast.classList.add('translate-x-full');
    }
    
    // Handle quick add form submission
    document.getElementById('quick-add-form').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('add_to_cart.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update cart badge
                const cartBadge = document.getElementById('cart-badge');
                if (cartBadge) {
                    cartBadge.textContent = data.cart_count;
                }
                
                // Show success notification
                showNotification(data.message, 'success');
                
                // Close modal - stock will be refreshed when reopened
                closeQuickAddModal();
                
                // Store current item to potentially reopen with updated stock
                const itemToRefresh = currentItem;
                if (itemToRefresh) {
                    // Reset currentItem so modal can be reopened fresh
                    currentItem = null;
                }
            } else {
                // Show error notification
                showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred while adding to cart', 'error');
        });
    });
    
    // Close modal on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeQuickAddModal();
        }
    });
    
    // Close modal on outside click
    document.getElementById('quickAddModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeQuickAddModal();
        }
    });
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
