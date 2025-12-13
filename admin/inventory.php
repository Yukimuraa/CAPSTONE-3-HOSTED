<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

$page_title = "Inventory Management - CHMSU BAO";
$base_url = "..";

// Create uploads directory if it doesn't exist
$upload_dir = "../uploads/inventory/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Ensure size_quantities column exists
$check_size_quantities = $conn->query("SHOW COLUMNS FROM inventory LIKE 'size_quantities'");
if ($check_size_quantities->num_rows == 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN size_quantities TEXT NULL AFTER sizes");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   if (isset($_POST['action'])) {
       // Add new item
       if ($_POST['action'] === 'add') {
           // Only admin can add items, not secretary
           if (is_secretary()) {
               $error = 'You do not have permission to add new items.';
           } else {
           $name = sanitize_input($_POST['name']);
           $description = sanitize_input($_POST['description']);
           $price = floatval($_POST['price']);
           $quantity = intval($_POST['quantity']);
           
           // Validate item name - letters and spaces only (no numbers, no symbols)
           if (!preg_match('/^[A-Za-z\s]+$/', $name)) {
               $error_message = "Item name must contain only letters and spaces (no numbers or symbols).";
           } elseif ($quantity > 5000) {
               $error_message = "Quantity cannot exceed 5000.";
           } else {
           $in_stock = isset($_POST['in_stock']) ? 1 : 0;
           
           // Handle sizes for specific clothing items
           $sizes = null;
           // Check if item name contains clothing keywords
           $clothingKeywords = ['shirt', 't-shirt', 'tshirt', 'pants', 'shorts', 'jacket', 'hoodie', 'polo', 'jersey'];
           $needs_sizes = false;
           
           foreach ($clothingKeywords as $keyword) {
               if (stripos($name, $keyword) !== false) {
                   $needs_sizes = true;
                   break;
               }
           }
           
           if ($needs_sizes) {
               // For clothing items, use the selected sizes from checkboxes
               if (isset($_POST['sizes']) && !empty($_POST['sizes'])) {
                   $sizes = json_encode($_POST['sizes']);
                   
                   // Handle size quantities
                   $size_quantities = [];
                   foreach ($_POST['sizes'] as $size) {
                       $qty_key = 'size_qty_' . $size;
                       $qty = isset($_POST[$qty_key]) ? intval($_POST[$qty_key]) : 0;
                       // Limit each size quantity to maximum 5000
                       if ($qty > 5000) {
                           $error_message = "Size quantity for {$size} cannot exceed 5000.";
                           break;
                       }
                       $size_quantities[$size] = $qty;
                   }
                   if (!isset($error_message)) {
                       $size_quantities_json = json_encode($size_quantities);
                       
                       // Calculate total quantity from size quantities
                       $quantity = array_sum($size_quantities);
                       // Check if total exceeds 5000
                       if ($quantity > 5000) {
                           $error_message = "Total quantity cannot exceed 5000.";
                       }
                   }
               } else {
                   // If no sizes selected, set to empty array
                   $sizes = json_encode([]);
                   $size_quantities_json = json_encode([]);
               }
           } elseif (isset($_POST['sizes']) && !empty($_POST['sizes'])) {
               $sizes = json_encode($_POST['sizes']);
               $size_quantities_json = json_encode([]);
           } else {
               $size_quantities_json = json_encode([]);
           }
           
           if (!isset($error_message)) {
           // Handle image upload
           $image_path = null;
           if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
               $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
               if (in_array($_FILES['image']['type'], $allowed_types)) {
                   $file_name = time() . '_' . basename($_FILES['image']['name']);
                   $target_path = $upload_dir . $file_name;
                   
                   if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                       $image_path = 'uploads/inventory/' . $file_name;
                   } else {
                       $error_message = "Error uploading file.";
                   }
               } else {
                   $error_message = "Invalid file type. Only JPG, PNG and GIF are allowed.";
               }
           }
           
           // Final safety check: ensure quantity never exceeds 5000
           if ($quantity > 5000) {
               $quantity = 5000;
               $error_message = "Quantity has been limited to 5000 (maximum allowed).";
           }
           
           if (!isset($error_message)) {
               // Check if product with same name already exists
               $check_stmt = $conn->prepare("SELECT id, quantity, size_quantities, sizes FROM inventory WHERE name = ?");
               $check_stmt->bind_param("s", $name);
               $check_stmt->execute();
               $check_result = $check_stmt->get_result();
               
               if ($check_result->num_rows > 0) {
                   // Product exists - update quantity instead of inserting
                   $existing_item = $check_result->fetch_assoc();
                   $existing_id = $existing_item['id'];
                   $existing_quantity = $existing_item['quantity'];
                   $existing_size_quantities = $existing_item['size_quantities'];
                   $existing_sizes = $existing_item['sizes'];
                   
                   // Handle size quantities merge if both have sizes
                   $final_size_quantities = [];
                   $final_quantity = $quantity;
                   
                   if (!empty($size_quantities_json) && $size_quantities_json !== '[]' && !empty($existing_size_quantities) && $existing_size_quantities !== '[]') {
                       // Both have sizes - merge them
                       $new_size_qty = json_decode($size_quantities_json, true);
                       $existing_size_qty = json_decode($existing_size_quantities, true);
                       
                       if ($new_size_qty && $existing_size_qty) {
                           // Merge size quantities
                           foreach ($existing_size_qty as $size => $qty) {
                               $final_size_quantities[$size] = $qty;
                           }
                           foreach ($new_size_qty as $size => $qty) {
                               if (isset($final_size_quantities[$size])) {
                                   $final_size_quantities[$size] += $qty;
                                   // Ensure no size exceeds 5000
                                   if ($final_size_quantities[$size] > 5000) {
                                       $final_size_quantities[$size] = 5000;
                                   }
                               } else {
                                   $final_size_quantities[$size] = $qty;
                               }
                           }
                           $final_quantity = array_sum($final_size_quantities);
                           $size_quantities_json = json_encode($final_size_quantities);
                           
                           // Ensure total doesn't exceed 5000
                           if ($final_quantity > 5000) {
                               $final_quantity = 5000;
                               $error_message = "Total quantity has been limited to 5000 (maximum allowed).";
                           }
                       }
                   } elseif (!empty($size_quantities_json) && $size_quantities_json !== '[]') {
                       // Only new item has sizes - use new sizes
                       $final_quantity = $quantity;
                   } elseif (!empty($existing_size_quantities) && $existing_size_quantities !== '[]') {
                       // Only existing item has sizes - keep existing sizes, add to total quantity
                       $final_size_quantities = json_decode($existing_size_quantities, true);
                       $final_quantity = $existing_quantity + $quantity;
                       $size_quantities_json = $existing_size_quantities;
                       
                       // Ensure total doesn't exceed 5000
                       if ($final_quantity > 5000) {
                           $final_quantity = 5000;
                           $error_message = "Total quantity has been limited to 5000 (maximum allowed).";
                       }
                   } else {
                       // Neither has sizes - simple quantity addition
                       $final_quantity = $existing_quantity + $quantity;
                       
                       // Ensure total doesn't exceed 5000
                       if ($final_quantity > 5000) {
                           $final_quantity = 5000;
                           $error_message = "Total quantity has been limited to 5000 (maximum allowed).";
                       }
                   }
                   
                   // Use existing sizes if new item doesn't have sizes
                   if (empty($sizes) || $sizes === '[]' || $sizes === null) {
                       $sizes = $existing_sizes;
                   }
                   
                   // Update existing product
                   $update_stmt = $conn->prepare("UPDATE inventory SET quantity = ?, size_quantities = ?, sizes = ?, in_stock = ?, updated_at = NOW() WHERE id = ?");
                   $update_stmt->bind_param("isssi", $final_quantity, $size_quantities_json, $sizes, $in_stock, $existing_id);
                   
                   if ($update_stmt->execute()) {
                       $success_message = "Item quantity updated successfully. Existing quantity: {$existing_quantity}, Added: {$quantity}, New total: {$final_quantity}";
                       
                       // Check if quantity is below 20 and create notification
                       if ($final_quantity < 20) {
                           require_once '../includes/notification_functions.php';
                           create_notification_for_admins(
                               "Low Stock Alert",
                               "Item '{$name}' has low stock (Quantity: {$final_quantity}). Please restock soon.",
                               "warning",
                               "admin/inventory.php"
                           );
                       }
                   } else {
                       $error_message = "Error updating item quantity: " . $conn->error;
                   }
               } else {
                   // Product doesn't exist - insert as new record
                   $stmt = $conn->prepare("INSERT INTO inventory (name, description, price, quantity, in_stock, image_path, sizes, size_quantities) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                   $stmt->bind_param("ssdissss", $name, $description, $price, $quantity, $in_stock, $image_path, $sizes, $size_quantities_json);
                   
                   if ($stmt->execute()) {
                       $success_message = "Item added successfully";
                       
                       // Check if quantity is below 20 and create notification
                       if ($quantity < 20) {
                           require_once '../includes/notification_functions.php';
                           create_notification_for_admins(
                               "Low Stock Alert",
                               "Item '{$name}' has low stock (Quantity: {$quantity}). Please restock soon.",
                               "warning",
                               "admin/inventory.php"
                           );
                       }
                   } else {
                       $error_message = "Error adding item: " . $conn->error;
                   }
               }
           }
           } // End if (!isset($error_message))
           } // End else - quantity validation
           } // End else - admin can add items
       }
       
       // Update item
       elseif ($_POST['action'] === 'update' && isset($_POST['id'])) {
           $id = intval($_POST['id']);
           $name = sanitize_input($_POST['name']);
           $description = sanitize_input($_POST['description']);
           $price = floatval($_POST['price']);
           $quantity = intval($_POST['quantity']);
           
           // Validate item name - letters and spaces only (no numbers, no symbols)
           if (!preg_match('/^[A-Za-z\s]+$/', $name)) {
               $error_message = "Item name must contain only letters and spaces (no numbers or symbols).";
           } elseif ($quantity > 5000) {
               $error_message = "Quantity cannot exceed 5000.";
           } else {
           $in_stock = isset($_POST['in_stock']) ? 1 : 0;
           
           // Handle sizes for specific clothing items
           $sizes = null;
           // Check if item name contains clothing keywords
           $clothingKeywords = ['shirt', 't-shirt', 'tshirt', 'pants', 'shorts', 'jacket', 'hoodie', 'polo', 'jersey'];
           $needs_sizes = false;
           
           foreach ($clothingKeywords as $keyword) {
               if (stripos($name, $keyword) !== false) {
                   $needs_sizes = true;
                   break;
               }
           }
           
           if ($needs_sizes) {
               // For clothing items, use the selected sizes from checkboxes
               if (isset($_POST['sizes']) && !empty($_POST['sizes'])) {
                   $sizes = json_encode($_POST['sizes']);
                   
                   // Handle size quantities
                   $size_quantities = [];
                   foreach ($_POST['sizes'] as $size) {
                       $qty_key = 'size_qty_' . $size;
                       $qty = isset($_POST[$qty_key]) ? intval($_POST[$qty_key]) : 0;
                       // Limit each size quantity to maximum 5000
                       if ($qty > 5000) {
                           $error_message = "Size quantity for {$size} cannot exceed 5000.";
                           break;
                       }
                       $size_quantities[$size] = $qty;
                   }
                   if (!isset($error_message)) {
                       $size_quantities_json = json_encode($size_quantities);
                       
                       // Calculate total quantity from size quantities
                       $quantity = array_sum($size_quantities);
                       // Check if total exceeds 5000
                       if ($quantity > 5000) {
                           $error_message = "Total quantity cannot exceed 5000.";
                       }
                   }
               } else {
                   // If no sizes selected, set to empty array
                   $sizes = json_encode([]);
                   $size_quantities_json = json_encode([]);
               }
           } elseif (isset($_POST['sizes']) && !empty($_POST['sizes'])) {
               $sizes = json_encode($_POST['sizes']);
               $size_quantities_json = json_encode([]);
           } else {
               $size_quantities_json = json_encode([]);
           }
           
           if (!isset($error_message)) {
           // Get current item data to check previous quantity
           $stmt = $conn->prepare("SELECT image_path, quantity, name FROM inventory WHERE id = ?");
           $stmt->bind_param("i", $id);
           $stmt->execute();
           $result = $stmt->get_result();
           $item = $result->fetch_assoc();
           $current_image = $item['image_path'];
           $previous_quantity = $item['quantity'];
           $item_name = $item['name'];
           
           // Handle image upload
           $image_path = $current_image;
           if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
               $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
               if (in_array($_FILES['image']['type'], $allowed_types)) {
                   $file_name = time() . '_' . basename($_FILES['image']['name']);
                   $target_path = $upload_dir . $file_name;
                   
                   if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                       $image_path = 'uploads/inventory/' . $file_name;
                       
                       // Delete old image if exists
                       if ($current_image && file_exists("../" . $current_image)) {
                           unlink("../" . $current_image);
                       }
                   } else {
                       $error_message = "Error uploading file.";
                   }
               } else {
                   $error_message = "Invalid file type. Only JPG, PNG and GIF are allowed.";
               }
           }
           
           // Handle image deletion
           if (isset($_POST['delete_image']) && $_POST['delete_image'] == 1) {
               if ($current_image && file_exists("../" . $current_image)) {
                   unlink("../" . $current_image);
               }
               $image_path = null;
           }
           
           // Final safety check: ensure quantity never exceeds 5000
           if ($quantity > 5000) {
               $quantity = 5000;
               $error_message = "Quantity has been limited to 5000 (maximum allowed).";
           }
           
           $stmt = $conn->prepare("UPDATE inventory SET name = ?, description = ?, price = ?, quantity = ?, in_stock = ?, image_path = ?, sizes = ?, size_quantities = ? WHERE id = ?");
           $stmt->bind_param("ssdissssi", $name, $description, $price, $quantity, $in_stock, $image_path, $sizes, $size_quantities_json, $id);
           
           if ($stmt->execute()) {
               $success_message = "Item updated successfully";
               
               require_once '../includes/notification_functions.php';
               
               // If quantity is now >= 20, mark all low stock notifications for this item as read for all admins
               if ($quantity >= 20 && $previous_quantity < 20) {
                   // Item is no longer low stock, mark related notifications as read for all admins
                   $search_pattern = "%Item '{$item_name}'%";
                   $update_query = "UPDATE notifications n
                                   INNER JOIN user_accounts u ON n.user_id = u.id
                                   SET n.is_read = 1
                                   WHERE n.type = 'warning' 
                                   AND n.title = 'Low Stock Alert' 
                                   AND n.message LIKE ?
                                   AND n.is_read = 0
                                   AND u.user_type IN ('admin', 'secretary')";
                   $stmt_notif = $conn->prepare($update_query);
                   $stmt_notif->bind_param("s", $search_pattern);
                   $stmt_notif->execute();
               }
               // Check if quantity is below 20 and create notification
               // Notify if quantity is below 20 and either:
               // 1. Just dropped from >= 20 to < 20, OR
               // 2. Decreased further when already below 20
               elseif ($quantity < 20) {
                   if ($previous_quantity >= 20 || ($previous_quantity < 20 && $quantity < $previous_quantity)) {
                       create_notification_for_admins(
                           "Low Stock Alert",
                           "Item '{$item_name}' has low stock (Quantity: {$quantity}). Please restock soon.",
                           "warning",
                           "admin/inventory.php"
                       );
                   }
               }
           } else {
               $error_message = "Error updating item: " . $conn->error;
           }
           } // End if (!isset($error_message))
           } // End else - quantity validation
       }
       
        // Delete item
        elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
            $id = intval($_POST['id']);
            
            // Get image path before deleting
            $stmt = $conn->prepare("SELECT image_path FROM inventory WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $item = $result->fetch_assoc();
            
            $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                // Delete image file if exists
                if ($item && $item['image_path'] && file_exists("../" . $item['image_path'])) {
                    unlink("../" . $item['image_path']);
                }
                $success_message = "Item deleted successfully";
            } else {
                $error_message = "Error deleting item: " . $conn->error;
            }
        }
   }
}

// Get inventory items
$query = "SELECT * FROM inventory ORDER BY name";
$result = $conn->query($query);


// Get low stock items count (less than 20)
$low_stock_query = "SELECT COUNT(*) as low_stock_count FROM inventory WHERE quantity < 20";
$low_stock_result = $conn->query($low_stock_query);
$low_stock_count = 0; // Default value
if ($low_stock_result && $low_stock_result->num_rows > 0) {
    $low_stock_count = $low_stock_result->fetch_assoc()['low_stock_count'];
}

// Get low stock items for notification
$low_stock_items_query = "SELECT * FROM inventory WHERE quantity < 20 ORDER BY quantity ASC";
$low_stock_items = $conn->query($low_stock_items_query);
if (!$low_stock_items) {
    $low_stock_items = []; // Set default empty array if query fails
}

// Get total inventory items count
$total_items_query = "SELECT COUNT(*) as total_inventory FROM inventory";
$total_items = $conn->query($total_items_query)->fetch_assoc();
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
   <?php include '../includes/admin_sidebar.php'; ?>
   
   <div class="flex-1 flex flex-col overflow-hidden">
       <!-- Top header -->
       <header class="bg-white shadow-sm z-10">
           <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
               <h1 class="text-2xl font-semibold text-gray-900">Inventory Management</h1>
               <div class="flex items-center space-x-4">
                   <?php require_once '../includes/inventory_notification_bell.php'; ?>
                   <span class="text-gray-700"><?php echo $_SESSION['user_name']; ?></span>
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
                   <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                       <p><?php echo $success_message; ?></p>
                   </div>
               <?php endif; ?>
               
               <?php if (isset($error_message)): ?>
                   <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                       <p><?php echo $error_message; ?></p>
                   </div>
               <?php endif; ?>
               
               <div class="flex items-center justify-between mb-6">
                  <div>
                      <h2 class="text-xl font-bold tracking-tight text-gray-900">Manage Inventory</h2>
                      <p class="text-gray-500">Add, edit, or remove items from the inventory</p>
                  </div>
                  <div class="flex items-center gap-4">
                      <!-- Inventory Statistics -->
                      <div class="bg-white rounded-lg shadow p-4">
                          <div class="text-center">
                              <h3 class="text-sm font-medium text-gray-500">Total Inventory Items</h3>
                              <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($total_items['total_inventory']); ?></p>
                          </div>
                      </div>
                      <?php if (!is_secretary()): ?>
                      <button type="button" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500" onclick="openAddModal()">
                          <i class="fas fa-plus mr-2"></i> Add New Item
                      </button>
                      <?php endif; ?>
                  </div>
               </div>
               
               <!-- Inventory table -->
               <div class="bg-white rounded-lg shadow">
                   <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
                       <h3 class="text-lg font-medium text-gray-900">Inventory Items</h3>
                   </div>
                   <div class="overflow-x-auto">
                       <table class="min-w-full divide-y divide-gray-200">
                           <thead class="bg-gray-50">
                               <tr>
                                   <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                   <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Name</th>
                                   <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                   <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                                   <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                   <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                   <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                               </tr>
                           </thead>
                           <tbody class="bg-white divide-y divide-gray-200">
                               <?php if ($result->num_rows > 0): ?>
                                   <?php while ($item = $result->fetch_assoc()): ?>
                                       <tr class="<?php echo $item['quantity'] < 20 ? 'bg-red-50' : ''; ?>">
                                           <td class="px-6 py-4 whitespace-nowrap">
                                               <?php if ($item['image_path']): ?>
                                                   <img src="<?php echo $base_url . '/' . $item['image_path']; ?>" alt="<?php echo $item['name']; ?>" class="h-16 w-16 object-cover rounded-md">
                                               <?php else: ?>
                                                   <div class="h-16 w-16 bg-gray-200 rounded-md flex items-center justify-center">
                                                       <i class="fas fa-image text-gray-400 text-xl"></i>
                                                   </div>
                                               <?php endif; ?>
                                           </td>
                                           <td class="px-6 py-4 text-sm font-medium text-gray-900 max-w-xs">
                                               <div class="truncate" title="<?php echo htmlspecialchars($item['name']); ?>"><?php echo htmlspecialchars($item['name']); ?></div>
                                           </td>
                                           <td class="px-6 py-4 text-sm text-gray-500 max-w-md">
                                               <div class="line-clamp-2" title="<?php echo htmlspecialchars($item['description']); ?>"><?php echo htmlspecialchars($item['description']); ?></div>
                                           </td>
                                           <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">₱<?php echo number_format($item['price'], 2); ?></td>
                                           <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">
                                               <?php 
                                               if (!empty($item['size_quantities'])) {
                                                   $size_qty = json_decode($item['size_quantities'], true);
                                                   if ($size_qty && is_array($size_qty)) {
                                                       echo '<div class="text-xs">';
                                                       foreach ($size_qty as $size => $qty) {
                                                           echo '<div>' . $size . ': <strong>' . $qty . '</strong></div>';
                                                       }
                                                       echo '</div>';
                                                       echo '<div class="text-xs text-gray-400 mt-1">Total: ' . $item['quantity'] . '</div>';
                                                   } else {
                                                       echo $item['quantity'];
                                                   }
                                               } else {
                                                   echo $item['quantity'];
                                               }
                                               ?>
                                           </td>
                                           <td class="px-6 py-4 whitespace-nowrap">
                                               <?php if ($item['in_stock']): ?>
                                                   <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">In Stock</span>
                                               <?php else: ?>
                                                   <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Out of Stock</span>
                                               <?php endif; ?>
                                           </td>
                                           <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                               <button type="button" class="text-emerald-600 hover:text-emerald-900 mr-3" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)'>Edit</button>
                                               <form method="POST" action="inventory.php" class="inline">
                                                   <input type="hidden" name="action" value="delete">
                                                   <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                   <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this item?')">Delete</button>
                                               </form>
                                           </td>
                                       </tr>
                                   <?php endwhile; ?>
                               <?php else: ?>
                                   <tr>
                                       <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No inventory items found</td>
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

<!-- Add Item Modal -->
<div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 overflow-y-auto py-4">
   <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl my-8 max-h-[90vh] flex flex-col">
       <div class="flex justify-between items-center p-6 border-b flex-shrink-0">
           <h3 class="text-lg font-medium text-gray-900">Add New Item</h3>
           <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeAddModal()">
               <i class="fas fa-times"></i>
           </button>
       </div>
       <div class="overflow-y-auto flex-1 p-6">
       <form method="POST" action="inventory.php" enctype="multipart/form-data">
           <input type="hidden" name="action" value="add">
           <div class="mb-4">
               <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
               <input type="text" id="name" name="name" required 
                      pattern="[A-Za-z\s]+"
                      title="Item name must contain only letters and spaces (no numbers or symbols)"
                      onkeypress="return /[A-Za-z\s]/.test(event.key)"
                      oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
                      class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
               <p class="mt-1 text-xs text-gray-500">Letters and spaces only</p>
           </div>
           <div class="mb-4">
               <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
               <textarea id="description" name="description" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50"></textarea>
           </div>
           <div id="sizes-container" class="mb-4 hidden">
               <label class="block text-sm font-medium text-gray-700 mb-2">Available Sizes & Stock</label>
               <div class="space-y-3 border rounded-lg p-4 bg-gray-50">
                   <div id="size-entries-container" class="max-h-64 overflow-y-auto space-y-2">
                       <!-- Size entries will be added dynamically -->
                   </div>
                   <button type="button" id="add-size-btn" class="w-full px-4 py-2 text-sm font-medium text-emerald-600 bg-white border border-emerald-600 rounded-md hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                       <i class="fas fa-plus mr-2"></i> Add Size
                   </button>
               </div>
           </div>
           <div class="grid grid-cols-2 gap-4 mb-4">
               <div>
                   <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Price (₱)</label>
                   <input type="number" id="price" name="price" step="0.01" min="0" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
               </div>
               <div id="quantity-container">
                   <label for="quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity <span id="quantity-note" class="text-xs text-gray-500 hidden">(Auto-calculated from sizes)</span></label>
                   <input type="number" id="quantity" name="quantity" min="0" max="5000" step="1" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50" oninput="if(this.value > 5000) this.value = 5000;">
               </div>
           </div>
           <div class="mb-4">
               <label for="image" class="block text-sm font-medium text-gray-700 mb-1">Item Image</label>
               <div class="flex items-center justify-center w-full">
                   <label for="image" class="flex flex-col items-center justify-center w-full h-32 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100">
                       <div class="flex flex-col items-center justify-center pt-5 pb-6">
                           <i class="fas fa-cloud-upload-alt text-gray-400 text-2xl mb-2"></i>
                           <p class="mb-2 text-sm text-gray-500"><span class="font-semibold">Click to upload</span> or drag and drop</p>
                           <p class="text-xs text-gray-500">PNG, JPG or GIF (Max. 2MB)</p>
                       </div>
                       <input id="image" name="image" type="file" class="hidden" accept="image/png, image/jpeg, image/gif" />
                   </label>
               </div>
               <div id="image-preview-container" class="mt-2 hidden">
                   <div class="relative w-full h-32">
                       <img id="image-preview" class="w-full h-full object-contain" src="#" alt="Image preview" />
                       <button type="button" id="remove-image" class="absolute top-0 right-0 bg-red-500 text-white rounded-full p-1 shadow-md">
                           <i class="fas fa-times"></i>
                       </button>
                   </div>
               </div>
           </div>
           <div class="mb-6">
               <div class="flex items-center">
                   <input type="checkbox" id="in_stock" name="in_stock" checked class="rounded border-gray-300 text-emerald-600 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
                   <label for="in_stock" class="ml-2 block text-sm text-gray-700">In Stock</label>
               </div>
           </div>
           <div class="flex justify-end">
               <button type="button" class="bg-gray-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeAddModal()">
                   Cancel
               </button>
               <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                   Add Item
               </button>
           </div>
       </form>
       </div>
   </div>
</div>

<!-- Edit Item Modal -->
<div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50 overflow-y-auto py-4">
   <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl my-8 max-h-[90vh] flex flex-col">
       <div class="flex justify-between items-center p-6 border-b flex-shrink-0">
           <h3 class="text-lg font-medium text-gray-900">Edit Item</h3>
           <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeEditModal()">
               <i class="fas fa-times"></i>
           </button>
       </div>
       <div class="overflow-y-auto flex-1 p-6">
       <form method="POST" action="inventory.php" enctype="multipart/form-data">
           <input type="hidden" name="action" value="update">
           <input type="hidden" id="edit_id" name="id">
           <div class="mb-4">
               <label for="edit_name" class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
               <input type="text" id="edit_name" name="name" required 
                      pattern="[A-Za-z\s]+"
                      title="Item name must contain only letters and spaces (no numbers or symbols)"
                      onkeypress="return /[A-Za-z\s]/.test(event.key)"
                      oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
                      class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
               <p class="mt-1 text-xs text-gray-500">Letters and spaces only</p>
           </div>
           <div class="mb-4">
               <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
               <textarea id="edit_description" name="description" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50"></textarea>
           </div>
           <div id="edit_sizes_container" class="mb-4 hidden">
               <label class="block text-sm font-medium text-gray-700 mb-2">Available Sizes & Stock</label>
               <div class="space-y-3 border rounded-lg p-4 bg-gray-50">
                   <div id="edit-size-entries-container" class="max-h-64 overflow-y-auto space-y-2">
                       <!-- Size entries will be added dynamically -->
                   </div>
                   <button type="button" id="edit-add-size-btn" class="w-full px-4 py-2 text-sm font-medium text-emerald-600 bg-white border border-emerald-600 rounded-md hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                       <i class="fas fa-plus mr-2"></i> Add Size
                   </button>
               </div>
           </div>
           <div class="grid grid-cols-2 gap-4 mb-4">
               <div>
                   <label for="edit_price" class="block text-sm font-medium text-gray-700 mb-1">Price (₱)</label>
                   <input type="number" id="edit_price" name="price" step="0.01" min="0" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
               </div>
               <div id="edit-quantity-container">
                   <label for="edit_quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity <span id="edit-quantity-note" class="text-xs text-gray-500 hidden">(Auto-calculated from sizes)</span></label>
                   <input type="number" id="edit_quantity" name="quantity" min="0" max="5000" step="1" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50" oninput="if(this.value > 5000) this.value = 5000;">
               </div>
           </div>
           <div class="mb-4">
               <label for="edit_image" class="block text-sm font-medium text-gray-700 mb-1">Item Image</label>
               <div id="current-image-container" class="mb-2 hidden">
                   <div class="relative w-full h-32">
                       <img id="current-image" class="w-full h-full object-contain border rounded-md" src="#" alt="Current image" />
                       <div class="mt-1">
                           <label class="inline-flex items-center">
                               <input type="checkbox" id="delete_image" name="delete_image" value="1" class="rounded border-gray-300 text-red-600">
                               <span class="ml-2 text-sm text-red-600">Delete current image</span>
                           </label>
                       </div>
                   </div>
               </div>
               <div class="flex items-center justify-center w-full">
                   <label for="edit_image" class="flex flex-col items-center justify-center w-full h-32 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100">
                       <div class="flex flex-col items-center justify-center pt-5 pb-6">
                           <i class="fas fa-cloud-upload-alt text-gray-400 text-2xl mb-2"></i>
                           <p class="mb-2 text-sm text-gray-500"><span class="font-semibold">Click to upload</span> or drag and drop</p>
                           <p class="text-xs text-gray-500">PNG, JPG or GIF (Max. 2MB)</p>
                       </div>
                       <input id="edit_image" name="image" type="file" class="hidden" accept="image/png, image/jpeg, image/gif" />
                   </label>
               </div>
               <div id="edit-image-preview-container" class="mt-2 hidden">
                   <div class="relative w-full h-32">
                       <img id="edit-image-preview" class="w-full h-full object-contain" src="#" alt="Image preview" />
                       <button type="button" id="edit-remove-image" class="absolute top-0 right-0 bg-red-500 text-white rounded-full p-1 shadow-md">
                           <i class="fas fa-times"></i>
                       </button>
                   </div>
               </div>
           </div>
           <div class="mb-6">
               <div class="flex items-center">
                   <input type="checkbox" id="edit_in_stock" name="in_stock" class="rounded border-gray-300 text-emerald-600 shadow-sm focus:border-emerald-500 focus:ring focus:ring-emerald-500 focus:ring-opacity-50">
                   <label for="edit_in_stock" class="ml-2 block text-sm text-gray-700">In Stock</label>
               </div>
           </div>
           <div class="flex justify-end">
               <button type="button" class="bg-blue-200 text-gray-700 py-2 px-4 rounded-md mr-2 hover:bg-blue-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2" onclick="closeEditModal()">
                   Cancel
               </button>
               <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                   Update Item
               </button>
           </div>
       </form>
       </div>
   </div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<script>
   // Mobile menu toggle
   document.getElementById('menu-button').addEventListener('click', function() {
       document.getElementById('sidebar').classList.toggle('-translate-x-full');
   });
   
   // Add modal functions
   function openAddModal() {
       document.getElementById('addModal').classList.remove('hidden');
   }
   
   function closeAddModal() {
       document.getElementById('addModal').classList.add('hidden');
       document.getElementById('image-preview-container').classList.add('hidden');
       document.getElementById('image').value = '';
       // Clear size entries
       document.getElementById('size-entries-container').innerHTML = '';
       sizeEntryCounter = 0;
   }
   
   // Edit modal functions
   function openEditModal(item) {
       document.getElementById('edit_id').value = item.id;
       document.getElementById('edit_name').value = item.name;
       document.getElementById('edit_description').value = item.description;
       document.getElementById('edit_price').value = item.price;
       document.getElementById('edit_quantity').value = item.quantity;
       document.getElementById('edit_in_stock').checked = item.in_stock == 1;
       
       // Check if item needs sizes (using clothing keywords)
       const itemNameLower = item.name.toLowerCase();
       const needsSizes = clothingKeywords.some(keyword => itemNameLower.includes(keyword));
       const sizesContainer = document.getElementById('edit_sizes_container');
       
       // Clear existing size entries
       document.getElementById('edit-size-entries-container').innerHTML = '';
       editSizeEntryCounter = 0;
       
       const quantityField = document.getElementById('edit_quantity');
       const quantityNote = document.getElementById('edit-quantity-note');
       
       if (needsSizes) {
           sizesContainer.classList.remove('hidden');
           quantityField.readOnly = true;
           quantityField.classList.add('bg-gray-100');
           if (quantityNote) quantityNote.classList.remove('hidden');
           
           // Parse sizes and size quantities
           let sizeArray = [];
           let sizeQuantities = {};
           
           if (item.sizes) {
               try {
                   sizeArray = JSON.parse(item.sizes);
               } catch (e) {
                   console.error('Error parsing sizes JSON:', e);
               }
           }
           
           if (item.size_quantities) {
               try {
                   sizeQuantities = JSON.parse(item.size_quantities);
               } catch (e) {
                   console.error('Error parsing size_quantities JSON:', e);
               }
           }
           
           // Create size entries for each existing size
           if (sizeArray && sizeArray.length > 0) {
               sizeArray.forEach(size => {
                   const qty = sizeQuantities[size] || 0;
                   createSizeEntry('edit-size-entries-container', size, qty, true);
               });
           }
           
           // Calculate and set total quantity
           setTimeout(() => {
               quantityField.value = calculateTotalQuantity('edit-size-entries-container');
           }, 100);
       } else {
           sizesContainer.classList.add('hidden');
           quantityField.readOnly = false;
           quantityField.classList.remove('bg-gray-100');
           if (quantityNote) quantityNote.classList.add('hidden');
       }
       
       // Handle existing image display
       const currentImageContainer = document.getElementById('current-image-container');
       if (item.image_path) {
           currentImageContainer.classList.remove('hidden');
           document.getElementById('current-image').src = '../' + item.image_path;
       } else {
           currentImageContainer.classList.add('hidden');
       }
       
       // Clear edit image preview
       document.getElementById('edit-image-preview-container').classList.add('hidden');
       document.getElementById('edit_image').value = '';
       document.getElementById('delete_image').checked = false;
       
       document.getElementById('editModal').classList.remove('hidden');
   }
   
   function closeEditModal() {
       document.getElementById('editModal').classList.add('hidden');
   }
   
   // Image preview for add form
   document.getElementById('image').addEventListener('change', function(e) {
       const file = e.target.files[0];
       if (file) {
           const reader = new FileReader();
           reader.onload = function(e) {
               const previewContainer = document.getElementById('image-preview-container');
               const preview = document.getElementById('image-preview');
               preview.src = e.target.result;
               previewContainer.classList.remove('hidden');
           }
           reader.readAsDataURL(file);
       }
   });
   
   // Remove image preview for add form
   document.getElementById('remove-image').addEventListener('click', function() {
       document.getElementById('image').value = '';
       document.getElementById('image-preview-container').classList.add('hidden');
   });
   
   // Image preview for edit form
   document.getElementById('edit_image').addEventListener('change', function(e) {
       const file = e.target.files[0];
       if (file) {
           const reader = new FileReader();
           reader.onload = function(e) {
               const previewContainer = document.getElementById('edit-image-preview-container');
               const preview = document.getElementById('edit-image-preview');
               preview.src = e.target.result;
               previewContainer.classList.remove('hidden');
               
               // Uncheck delete image checkbox if it was checked
               document.getElementById('delete_image').checked = false;
           }
           reader.readAsDataURL(file);
       }
   });
   
   // Remove image preview for edit form
   document.getElementById('edit-remove-image').addEventListener('click', function() {
       document.getElementById('edit_image').value = '';
       document.getElementById('edit-image-preview-container').classList.add('hidden');
   });
   
   // Handle delete image checkbox
   document.getElementById('delete_image').addEventListener('change', function() {
       if (this.checked) {
           // If delete is checked, clear any new image upload
           document.getElementById('edit_image').value = '';
           document.getElementById('edit-image-preview-container').classList.add('hidden');
       }
   });

   // Notification bell handling is now in inventory_notification_bell.php
   // Removed duplicate code to prevent conflicts

   // Add the JavaScript for size options display
   const clothingKeywords = [
       'shirt',
       't-shirt',
       'tshirt',
       'pants',
       'shorts',
       'jacket',
       'hoodie',
       'polo',
       'jersey'
   ];
   
   const availableSizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'];
   let sizeEntryCounter = 0;
   let editSizeEntryCounter = 0;
   
   // Function to calculate total quantity from size quantities
   function calculateTotalQuantity(containerId) {
       let total = 0;
       const container = document.getElementById(containerId);
       if (container) {
           container.querySelectorAll('.size-qty-input').forEach(input => {
               if (input.value) {
                   total += parseInt(input.value) || 0;
               }
           });
       }
       return total;
   }
   
   // Function to create a size entry row
   function createSizeEntry(containerId, size = '', qty = 0, isEdit = false) {
       const container = document.getElementById(containerId);
       const entryId = isEdit ? 'edit-size-entry-' + (editSizeEntryCounter++) : 'size-entry-' + (sizeEntryCounter++);
       
       const sizeEntry = document.createElement('div');
       sizeEntry.className = 'flex items-end gap-2 p-3 bg-white rounded border size-entry-row';
       sizeEntry.id = entryId;
       
       // Size label and select container
       const sizeContainer = document.createElement('div');
       sizeContainer.className = 'flex-1';
       
       const sizeLabel = document.createElement('label');
       sizeLabel.className = 'block text-xs font-medium text-gray-700 mb-1';
       sizeLabel.textContent = 'Size';
       
       const sizeSelect = document.createElement('select');
       sizeSelect.name = 'sizes[]';
       sizeSelect.className = 'w-full size-select px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500';
       sizeSelect.required = true;
       
       // Add empty option
       const emptyOption = document.createElement('option');
       emptyOption.value = '';
       emptyOption.textContent = 'Select Size';
       sizeSelect.appendChild(emptyOption);
       
       // Add all available sizes
       availableSizes.forEach(s => {
           const option = document.createElement('option');
           option.value = s;
           option.textContent = s;
           if (s === size) option.selected = true;
           sizeSelect.appendChild(option);
       });
       
       sizeContainer.appendChild(sizeLabel);
       sizeContainer.appendChild(sizeSelect);
       
       // Quantity label and input container
       const qtyContainer = document.createElement('div');
       qtyContainer.className = 'w-32';
       
       const qtyLabel = document.createElement('label');
       qtyLabel.className = 'block text-xs font-medium text-gray-700 mb-1';
       qtyLabel.textContent = 'Quantity';
       
       const qtyInput = document.createElement('input');
       qtyInput.type = 'number';
       qtyInput.name = 'size_qty_' + (size || '');
       qtyInput.className = 'w-full size-qty-input px-2 py-2 text-sm border border-gray-300 rounded-md focus:border-emerald-500 focus:ring focus:ring-emerald-500';
       qtyInput.min = 0;
       qtyInput.max = 5000;
       qtyInput.step = 1;
       qtyInput.value = qty;
       qtyInput.placeholder = '0';
       qtyInput.required = true;
       qtyInput.setAttribute('oninput', 'if(this.value > 5000) this.value = 5000;');
       
       qtyContainer.appendChild(qtyLabel);
       qtyContainer.appendChild(qtyInput);
       
       const removeBtn = document.createElement('button');
       removeBtn.type = 'button';
       removeBtn.className = 'px-3 py-2 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md mb-0.5';
       removeBtn.innerHTML = '<i class="fas fa-times"></i>';
       removeBtn.onclick = function() {
           sizeEntry.remove();
           updateSizeDropdowns(containerId);
           updateTotalQuantity();
       };
       
       sizeEntry.appendChild(sizeContainer);
       sizeEntry.appendChild(qtyContainer);
       sizeEntry.appendChild(removeBtn);
       
       // Update quantity input name when size changes
       sizeSelect.addEventListener('change', function() {
           qtyInput.name = 'size_qty_' + this.value;
           updateSizeDropdowns(containerId);
           updateTotalQuantity();
       });
       
       // Update total when quantity changes and enforce max limit
       qtyInput.addEventListener('input', function() {
           // Enforce max limit of 5000
           if (this.value > 5000) {
               this.value = 5000;
               alert('Maximum quantity per size is 5000.');
           }
           updateTotalQuantity();
       });
       
       // Also validate on blur
       qtyInput.addEventListener('blur', function() {
           if (this.value > 5000) {
               this.value = 5000;
               alert('Maximum quantity per size is 5000.');
               updateTotalQuantity();
           }
       });
       
       container.appendChild(sizeEntry);
       
       // Update dropdowns after adding to DOM
       updateSizeDropdowns(containerId);
       
       return sizeEntry;
   }
   
   // Function to update size dropdowns to prevent duplicates
   function updateSizeDropdowns(containerId) {
       const container = document.getElementById(containerId);
       const selects = container.querySelectorAll('.size-select');
       const selectedValues = [];
       
       // Get all selected values
       selects.forEach(select => {
           if (select.value) {
               selectedValues.push(select.value);
           }
       });
       
       // Update each dropdown
       selects.forEach(select => {
           const currentValue = select.value;
           select.innerHTML = '<option value="">Select Size</option>';
           
           availableSizes.forEach(size => {
               // Show size if it's not selected elsewhere, or if it's the current selection
               if (!selectedValues.includes(size) || size === currentValue) {
                   const option = document.createElement('option');
                   option.value = size;
                   option.textContent = size;
                   if (size === currentValue) option.selected = true;
                   select.appendChild(option);
               }
           });
       });
   }
   
   // Function to update total quantity
   function updateTotalQuantity() {
       const addQuantity = document.getElementById('quantity');
       const editQuantity = document.getElementById('edit_quantity');
       
       if (addQuantity && addQuantity.readOnly) {
           addQuantity.value = calculateTotalQuantity('size-entries-container');
       }
       if (editQuantity && editQuantity.readOnly) {
           editQuantity.value = calculateTotalQuantity('edit-size-entries-container');
       }
   }
   
   // Add size button handlers
   document.getElementById('add-size-btn').addEventListener('click', function() {
       createSizeEntry('size-entries-container');
   });
   
   document.getElementById('edit-add-size-btn').addEventListener('click', function() {
       createSizeEntry('edit-size-entries-container', '', 0, true);
   });
   
   // Add validation for quantity input fields
   const quantityInput = document.getElementById('quantity');
   if (quantityInput) {
       quantityInput.addEventListener('input', function() {
           if (this.value > 5000) {
               this.value = 5000;
               alert('Maximum quantity is 5000.');
           }
       });
       
       quantityInput.addEventListener('blur', function() {
           if (this.value > 5000) {
               this.value = 5000;
               alert('Maximum quantity is 5000.');
           }
       });
   }
   
   const editQuantityInput = document.getElementById('edit_quantity');
   if (editQuantityInput) {
       editQuantityInput.addEventListener('input', function() {
           if (this.value > 5000) {
               this.value = 5000;
               alert('Maximum quantity is 5000.');
           }
       });
       
       editQuantityInput.addEventListener('blur', function() {
           if (this.value > 5000) {
               this.value = 5000;
               alert('Maximum quantity is 5000.');
           }
       });
   }
   
   // Form submission validation
   const addForm = document.querySelector('#addModal form');
   if (addForm) {
       addForm.addEventListener('submit', function(e) {
           const quantity = document.getElementById('quantity');
           if (quantity && !quantity.readOnly && parseInt(quantity.value) > 5000) {
               e.preventDefault();
               alert('Quantity cannot exceed 5000.');
               quantity.focus();
               return false;
           }
           
           // Check size quantities
           const sizeQtyInputs = document.querySelectorAll('.size-qty-input');
           let hasError = false;
           sizeQtyInputs.forEach(input => {
               if (parseInt(input.value) > 5000) {
                   hasError = true;
                   input.value = 5000;
               }
           });
           
           if (hasError) {
               e.preventDefault();
               alert('Size quantities cannot exceed 5000. Values have been adjusted.');
               return false;
           }
           
           // Check total quantity
           const totalQty = calculateTotalQuantity('size-entries-container');
           if (totalQty > 5000) {
               e.preventDefault();
               alert('Total quantity cannot exceed 5000. Please adjust size quantities.');
               return false;
           }
       });
   }
   
   const editForm = document.querySelector('#editModal form');
   if (editForm) {
       editForm.addEventListener('submit', function(e) {
           const quantity = document.getElementById('edit_quantity');
           if (quantity && !quantity.readOnly && parseInt(quantity.value) > 5000) {
               e.preventDefault();
               alert('Quantity cannot exceed 5000.');
               quantity.focus();
               return false;
           }
           
           // Check size quantities
           const sizeQtyInputs = document.querySelectorAll('#edit-size-entries-container .size-qty-input');
           let hasError = false;
           sizeQtyInputs.forEach(input => {
               if (parseInt(input.value) > 5000) {
                   hasError = true;
                   input.value = 5000;
               }
           });
           
           if (hasError) {
               e.preventDefault();
               alert('Size quantities cannot exceed 5000. Values have been adjusted.');
               return false;
           }
           
           // Check total quantity
           const totalQty = calculateTotalQuantity('edit-size-entries-container');
           if (totalQty > 5000) {
               e.preventDefault();
               alert('Total quantity cannot exceed 5000. Please adjust size quantities.');
               return false;
           }
       });
   }
   
   // For the add item form
   document.getElementById('name').addEventListener('input', function() {
       const itemName = this.value.trim().toLowerCase();
       const sizesContainer = document.getElementById('sizes-container');
       const quantityField = document.getElementById('quantity');
       const quantityNote = document.getElementById('quantity-note');
       
       // Check if current item name contains clothing keywords
       const needsSizes = clothingKeywords.some(keyword => itemName.includes(keyword));
       
       if (needsSizes) {
           sizesContainer.classList.remove('hidden');
           quantityField.readOnly = true;
           quantityField.classList.add('bg-gray-100');
           if (quantityNote) quantityNote.classList.remove('hidden');
       } else {
           sizesContainer.classList.add('hidden');
           quantityField.readOnly = false;
           quantityField.classList.remove('bg-gray-100');
           if (quantityNote) quantityNote.classList.add('hidden');
           // Clear all size entries
           document.getElementById('size-entries-container').innerHTML = '';
           quantityField.value = 0;
       }
   });
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>
