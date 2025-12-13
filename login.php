<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

$error = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $user_type = $_POST['user_type'] ?? '';
    $selected_role = $_POST['role'] ?? '';

    if (empty($email) || empty($password) || empty($user_type)) {
        $error = "All fields are required";
    } else {
        // For student type, we need to validate the specific role
        if ($user_type === 'student') {
            if (empty($selected_role)) {
                $error = "Please select Student, Faculty, or Staff";
            } else {
                // Check if role column exists in database
                $check_column = $conn->query("SHOW COLUMNS FROM user_accounts LIKE 'role'");
                $role_column_exists = $check_column && $check_column->num_rows > 0;
                
                if ($role_column_exists) {
                    // Query with role validation
                    $stmt = $conn->prepare("SELECT * FROM user_accounts WHERE email = ? AND user_type = ? AND role = ?");
                    $stmt->bind_param("sss", $email, $user_type, $selected_role);
                } else {
                    // Fallback if role column doesn't exist
                    $stmt = $conn->prepare("SELECT * FROM user_accounts WHERE email = ? AND user_type = ?");
                    $stmt->bind_param("ss", $email, $user_type);
                }
                
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    
                    // Verify password
                    if (password_verify($password, $user['password'])) {
                        // Check if the role matches (if role column exists)
                        if ($role_column_exists && isset($user['role']) && $user['role'] !== $selected_role) {
                            $error = "The selected user type does not match your account. Please select the correct option (Student, Faculty, or Staff).";
                        } else {
                            // Initialize user_sessions array if it doesn't exist
                            if (!isset($_SESSION['user_sessions'])) {
                                $_SESSION['user_sessions'] = [];
                            }
                            
                            // Store user data in the session
                            $_SESSION['user_sessions'][$user_type] = [
                                'user_id' => $user['id'],
                                'user_name' => $user['name'],
                                'user_email' => $user['email'],
                                'user_type' => $user['user_type'],
                                'role' => $user['role'] ?? null
                            ];
                            
                            // Set this user type as active
                            $_SESSION['active_user_type'] = $user_type;
                            
                            // Redirect to student dashboard (all student/faculty/staff go to same dashboard)
                            header("Location: student/dashboard.php");
                            exit();
                        }
                    } else {
                        $error = "Incorrect password. Please try again.";
                    }
                } else {
                    // Check if email exists but role doesn't match
                    $check_email = $conn->prepare("SELECT * FROM user_accounts WHERE email = ?");
                    $check_email->bind_param("s", $email);
                    $check_email->execute();
                    $email_result = $check_email->get_result();
                    
                    if ($email_result->num_rows > 0) {
                        $error = "The selected user type does not match your account. Please select the correct option (Student, Faculty, or Staff).";
                    } else {
                        $error = "Email address not found. Please check your email and try again.";
                    }
                }
            }
        } else {
            // For other user types (admin, secretary, external, etc.)
            $stmt = $conn->prepare("SELECT * FROM user_accounts WHERE email = ? AND user_type = ?");
            $stmt->bind_param("ss", $email, $user_type);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Initialize user_sessions array if it doesn't exist
                    if (!isset($_SESSION['user_sessions'])) {
                        $_SESSION['user_sessions'] = [];
                    }
                    
                    // Store user data in the session
                    $_SESSION['user_sessions'][$user_type] = [
                        'user_id' => $user['id'],
                        'user_name' => $user['name'],
                        'user_email' => $user['email'],
                        'user_type' => $user['user_type']
                    ];
                    
                    // Set this user type as active
                    $_SESSION['active_user_type'] = $user_type;
                    
                    // Redirect based on user type
                    switch ($user_type) {
                        case 'admin':
                            header("Location: admin/dashboard.php");
                            break;
                        case 'secretary':
                            header("Location: admin/dashboard.php");
                            break;
                        case 'external':
                            header("Location: external/dashboard.php");
                            break;
                        default:
                            header("Location: index.php");
                    }
                    exit();
                } else {
                    $error = "Incorrect password. Please try again.";
                }
            } else {
                $error = "Email address not found. Please check your email and try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Login - CHMSU Business Affairs Office</title>
 <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
 <link rel="stylesheet" href="assets/css/styles.css">
 <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
 <style>
     body {
         background-image: url('image/ChamsuBackround.jpg');
         background-size: cover;
         background-position: center;
         background-repeat: no-repeat;
         background-attachment: fixed;
         position: relative;
     }
     body::before {
         content: '';
         position: fixed;
         top: 0;
         left: 0;
         width: 100%;
         height: 100%;
         background: linear-gradient(135deg, rgba(0, 100, 0, 0.3) 0%, rgba(0, 80, 0, 0.4) 100%);
         z-index: 0;
     }
     .login-container {
         position: relative;
         z-index: 1;
         background: rgba(255, 255, 255, 0.1);
         backdrop-filter: blur(20px);
         border: 1px solid rgba(255, 255, 255, 0.2);
         box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
         border-radius: 20px;
         overflow: hidden;
     }
     .header-section {
         background: linear-gradient(135deg, rgba(0, 100, 0, 0.8) 0%, rgba(0, 80, 0, 0.9) 100%);
         backdrop-filter: blur(10px);
         border-bottom: 2px solid rgba(255, 255, 255, 0.1);
     }
     .form-section {
         background: rgba(255, 255, 255, 0.95);
     }
     .login-btn {
         background: linear-gradient(135deg, #1E40AF 0%, #1E3A8A 100%);
         transition: all 0.3s ease;
         box-shadow: 0 4px 15px rgba(30, 64, 175, 0.4);
     }
     .login-btn:hover {
         transform: translateY(-2px);
         box-shadow: 0 6px 20px rgba(30, 64, 175, 0.6);
     }
     .input-with-icon {
         position: relative;
     }
     .input-with-icon .icon {
         position: absolute;
         left: 15px;
         top: 50%;
         transform: translateY(-50%);
         color: #6B7280;
         pointer-events: none;
         z-index: 10;
     }
     .input-with-icon input,
     .input-with-icon select {
         padding-left: 45px !important;
         background: rgba(255, 255, 255, 0.9);
         border: 2px solid rgba(0, 0, 0, 0.1);
         transition: all 0.3s ease;
     }
     .input-with-icon input:focus,
     .input-with-icon select:focus {
         background: rgba(255, 255, 255, 1);
         border-color: #1E40AF;
         box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
     }
     label {
         color: #374151;
         font-weight: 600;
     }
     .error-message {
         background: rgba(239, 68, 68, 0.1);
         border: 1px solid rgba(239, 68, 68, 0.3);
         color: #DC2626;
         backdrop-filter: blur(10px);
     }
 </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
 <div class="login-container w-full max-w-md">
     <div class="header-section p-8 text-center text-white">
         <div class="flex justify-center mb-4">
             <div class="bg-white rounded-full p-3 shadow-lg">
                 <img src="image/CHMSUWebLOGO.png" alt="CHMSU Logo" width="60px" height="60px">
             </div>
         </div>
         <h2 class="text-2xl font-bold mb-2">Welcome Back</h2>
         <p class="text-sm opacity-90">Enter your credentials to access the system</p>
     </div>

     <div class="form-section p-8">
         <?php if (!empty($error)): ?>
             <div class="error-message px-4 py-3 rounded-lg mb-6 text-sm">
                 <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?>
             </div>
         <?php endif; ?>

         <form action="login.php" method="POST">
             <div class="mb-4">
                 <label for="user_type" class="block text-gray-700 font-medium mb-2">User Type</label>
                 <div class="input-with-icon">
                     <span class="icon">
                         <i class="fas fa-user-tag"></i>
                     </span>
                     <select id="user_type" name="user_type" onchange="updateLoginRole()" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                         <option value="student" data-role="student">Student</option>
                         <option value="student" data-role="faculty">Faculty</option>
                         <option value="student" data-role="staff">Staff</option>
                         <option value="external" data-role="">External User</option>
                     </select>
                     <input type="hidden" id="role" name="role" value="student">
                 </div>
             </div>

             <div class="mb-4">
                 <label for="email" class="block text-gray-700 font-medium mb-2">Email Address</label>
                 <div class="input-with-icon">
                     <span class="icon">
                         <i class="fas fa-envelope"></i>
                     </span>
                     <input type="email" id="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter your email" required>
                 </div>
             </div>

             <div class="mb-4">
                 <div class="flex items-center justify-between mb-2">
                     <label for="password" class="block text-gray-700 font-medium">Password</label>
                     <a href="forgot-password.php" class="text-sm text-blue-600 hover:underline">Forgot password?</a>
                 </div>
                 <div class="input-with-icon" style="position: relative;">
                     <span class="icon">
                         <i class="fas fa-lock"></i>
                     </span>
                     <input type="password" id="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" style="padding-right: 40px;" required>
                     <button type="button" onclick="togglePassword('password', 'togglePasswordIcon')" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #6B7280;">
                         <i class="fas fa-eye" id="togglePasswordIcon"></i>
                     </button>
                 </div>
             </div>

             <button type="submit" class="w-full login-btn text-white py-3 px-4 rounded-lg font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                 <i class="fas fa-sign-in-alt mr-2"></i>Login
             </button>

             <div class="text-center mt-6 pt-6 border-t border-gray-200">
                 <p class="text-gray-600 text-sm">
                     Don't have an account? 
                     <a href="register.php" class="text-blue-600 hover:text-blue-700 font-semibold hover:underline">Register Now</a>
                 </p>
             </div>
         </form>
     </div>
 </div>
 
 <script>
     function togglePassword(inputId, iconId) {
         const passwordInput = document.getElementById(inputId);
         const toggleIcon = document.getElementById(iconId);
         
         if (passwordInput.type === 'password') {
             passwordInput.type = 'text';
             toggleIcon.classList.remove('fa-eye');
             toggleIcon.classList.add('fa-eye-slash');
         } else {
             passwordInput.type = 'password';
             toggleIcon.classList.remove('fa-eye-slash');
             toggleIcon.classList.add('fa-eye');
         }
     }
     
     // Update the hidden role field based on selected dropdown option
     function updateLoginRole() {
         const userTypeSelect = document.getElementById('user_type');
         const roleField = document.getElementById('role');
         
         if (userTypeSelect && roleField) {
             const selectedOption = userTypeSelect.options[userTypeSelect.selectedIndex];
             const role = selectedOption.getAttribute('data-role') || '';
             roleField.value = role;
             
             console.log('Selected option:', selectedOption.text, 'Role set to:', role);
         }
     }
     
     // Initialize role on page load
     document.addEventListener('DOMContentLoaded', function() {
         updateLoginRole();
     });
     
     // Update role before form submission
     document.querySelector('form').addEventListener('submit', function() {
         updateLoginRole();
     });
 </script>
</body>
</html>
