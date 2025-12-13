<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHMSU Business Affairs Office</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Modern gradient background */
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #006400 50%, #00008b 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Glass morphism effect */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
        }

        /* Feature cards with hover effects */
        .feature-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .feature-card:hover::before {
            left: 100%;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        /* Icon animations */
        .icon-bounce {
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        /* Header styling */
        .header-modern {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        /* Button modern styles */
        .btn-modern {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .btn-modern::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn-modern:hover::after {
            width: 300px;
            height: 300px;
        }

        /* Hero text animation */
        .hero-text {
            animation: fadeInUp 1s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Feature icon containers */
        .icon-container {
            background: linear-gradient(135deg, #006400 0%, #00008b 100%);
            transition: all 0.3s ease;
        }

        .feature-card:hover .icon-container {
            transform: scale(1.1) rotate(5deg);
            background: linear-gradient(135deg, #00008b 0%, #006400 100%);
        }

        /* Footer gradient */
        .footer-gradient {
            background: linear-gradient(135deg, #00008b 0%, #006400 100%);
        }

        /* Smooth scroll */
        html {
            scroll-behavior: smooth;
        }

        /* Text gradient effect */
        .text-gradient {
            background: linear-gradient(135deg, #00008b 0%, #006400 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header-modern sticky top-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="icon-container p-2 rounded-lg">
                    <i class="fas fa-school text-white text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gradient">CHMSU</h1>
                    <p class="text-sm text-gray-600 font-medium">Business Affairs Office</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="login.php" class="px-5 py-2.5 border-2 border-blue-600 rounded-lg text-blue-600 font-semibold hover:bg-blue-50 transition-all duration-300 hover:scale-105">
                    <i class="fas fa-sign-in-alt mr-2"></i>Login
                </a>
                <a href="admin_login.php" class="px-5 py-2.5 border-2 border-red-600 rounded-lg text-red-600 font-semibold hover:bg-red-50 transition-all duration-300 hover:scale-105">
                    <i class="fas fa-user-shield mr-2"></i>Admin Login
                </a>
                <a href="register.php" class="px-5 py-2.5 bg-gradient-to-r from-blue-600 to-green-600 text-white rounded-lg font-semibold btn-modern hover:shadow-lg transition-all duration-300 hover:scale-105">
                    <i class="fas fa-user-plus mr-2"></i>Register
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="container mx-auto px-4 py-20 text-center">
        <div class="hero-text">
            <div class="mb-6">
                <span class="inline-block px-4 py-2 bg-white/20 backdrop-blur-sm rounded-full text-white text-sm font-semibold mb-4">
                    <i class="fas fa-star mr-2"></i>Digital Transformation Platform
                </span>
            </div>
            <h1 class="text-5xl md:text-6xl font-bold text-white mb-6 leading-tight">
                Business Affairs Office
                <span class="block text-yellow-300 mt-2">Information System</span>
            </h1>
            <p class="text-xl md:text-2xl text-white/90 max-w-3xl mx-auto mb-10 leading-relaxed">
                Streamline your transactions, manage requests efficiently, and experience seamless digital services at CHMSU
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <a href="login.php" class="px-8 py-4 bg-white text-blue-600 rounded-lg font-bold text-lg btn-modern hover:shadow-2xl transition-all duration-300 hover:scale-105 flex items-center justify-center gap-2">
                    Get Started <i class="fas fa-arrow-right"></i>
                </a>
                <a href="#features" class="px-8 py-4 bg-white/10 backdrop-blur-sm text-white border-2 border-white rounded-lg font-bold text-lg hover:bg-white/20 transition-all duration-300 hover:scale-105 flex items-center justify-center gap-2">
                    <i class="fas fa-info-circle"></i> Learn More
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="container mx-auto px-4 py-20">
        <div class="text-center mb-16">
            <h2 class="text-4xl md:text-5xl font-bold text-white mb-4">Key Features</h2>
            <p class="text-xl text-white/80 max-w-2xl mx-auto">
                Comprehensive solutions for all your business affairs needs
            </p>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <!-- Gym Reservations Card -->
            <div class="feature-card glass-card p-8 rounded-2xl">
                <div class="icon-container w-16 h-16 rounded-xl flex items-center justify-center mb-6 mx-auto">
                    <i class="fas fa-calendar-alt text-white text-2xl"></i>
                </div>
                <h3 class="text-2xl font-bold mb-4 text-center text-gray-800">Gym Reservations</h3>
                <p class="text-gray-600 mb-6 text-center leading-relaxed">
                    Book the gymnasium for events, practices, and activities. Students, staff, and external organizations can reserve the gym online with real-time availability.
                </p>
                <a href="login.php" class="block text-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg font-semibold hover:shadow-lg transition-all duration-300 hover:scale-105">
                    <i class="fas fa-calendar-check mr-2"></i>Book Now
                </a>
            </div>

            <!-- Bus Scheduling Card -->
            <div class="feature-card glass-card p-8 rounded-2xl">
                <div class="icon-container w-16 h-16 rounded-xl flex items-center justify-center mb-6 mx-auto">
                    <i class="fas fa-bus text-white text-2xl"></i>
                </div>
                <h3 class="text-2xl font-bold mb-4 text-center text-gray-800">Bus Scheduling</h3>
                <p class="text-gray-600 mb-6 text-center leading-relaxed">
                    Reserve university buses for official trips and events. Staff can schedule bus usage through an intuitive interface with automatic billing.
                </p>
                <a href="login.php" class="block text-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg font-semibold hover:shadow-lg transition-all duration-300 hover:scale-105">
                    <i class="fas fa-route mr-2"></i>Schedule Now
                </a>
            </div>

            <!-- Inventory Tracking Card -->
            <div class="feature-card glass-card p-8 rounded-2xl">
                <div class="icon-container w-16 h-16 rounded-xl flex items-center justify-center mb-6 mx-auto">
                    <i class="fas fa-box text-white text-2xl"></i>
                </div>
                <h3 class="text-2xl font-bold mb-4 text-center text-gray-800">Inventory Tracking</h3>
                <p class="text-gray-600 mb-6 text-center leading-relaxed">
                    Manage school-related items like cords, PE uniforms, and logos. Track inventory levels and place orders through the digital system.
                </p>
                <a href="login.php" class="block text-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg font-semibold hover:shadow-lg transition-all duration-300 hover:scale-105">
                    <i class="fas fa-warehouse mr-2"></i>View Inventory
                </a>
            </div>

            <!-- Request Tracking Card -->
            <div class="feature-card glass-card p-8 rounded-2xl">
                <div class="icon-container w-16 h-16 rounded-xl flex items-center justify-center mb-6 mx-auto">
                    <i class="fas fa-clipboard-list text-white text-2xl"></i>
                </div>
                <h3 class="text-2xl font-bold mb-4 text-center text-gray-800">Request Tracking</h3>
                <p class="text-gray-600 mb-6 text-center leading-relaxed">
                    Submit and monitor the status of your requests. Receive real-time updates on approval status and automated notifications.
                </p>
                <a href="login.php" class="block text-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg font-semibold hover:shadow-lg transition-all duration-300 hover:scale-105">
                    <i class="fas fa-tasks mr-2"></i>Submit Request
                </a>
            </div>

            <!-- External User Access Card -->
            <div class="feature-card glass-card p-8 rounded-2xl">
                <div class="icon-container w-16 h-16 rounded-xl flex items-center justify-center mb-6 mx-auto">
                    <i class="fas fa-school text-white text-2xl"></i>
                </div>
                <h3 class="text-2xl font-bold mb-4 text-center text-gray-800">External User Access</h3>
                <p class="text-gray-600 mb-6 text-center leading-relaxed">
                    Allow external organizations to book facilities. Schools and organizations can request to use the gymnasium for events.
                </p>
                <a href="register.php" class="block text-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg font-semibold hover:shadow-lg transition-all duration-300 hover:scale-105">
                    <i class="fas fa-user-plus mr-2"></i>Register
                </a>
            </div>

            <!-- Automated Notifications Card -->
            <div class="feature-card glass-card p-8 rounded-2xl">
                <div class="icon-container w-16 h-16 rounded-xl flex items-center justify-center mb-6 mx-auto">
                    <i class="fas fa-bell text-white text-2xl icon-bounce"></i>
                </div>
                <h3 class="text-2xl font-bold mb-4 text-center text-gray-800">Automated Notifications</h3>
                <p class="text-gray-600 mb-6 text-center leading-relaxed">
                    Get notified about request approvals, reservation confirmations, and other important updates automatically via email.
                </p>
                <a href="login.php" class="block text-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg font-semibold hover:shadow-lg transition-all duration-300 hover:scale-105">
                    <i class="fas fa-envelope mr-2"></i>Learn More
                </a>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="container mx-auto px-4 py-20">
        <div class="glass-card p-12 rounded-3xl text-center">
            <h2 class="text-3xl font-bold text-gray-800 mb-12">Why Choose Our System?</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <div class="icon-container w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-bolt text-white text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">Fast & Efficient</h3>
                    <p class="text-gray-600">Streamlined processes for quick transactions</p>
                </div>
                <div>
                    <div class="icon-container w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-shield-alt text-white text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">Secure & Reliable</h3>
                    <p class="text-gray-600">Your data is protected with enterprise-grade security</p>
                </div>
                <div>
                    <div class="icon-container w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-mobile-alt text-white text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">Accessible Anywhere</h3>
                    <p class="text-gray-600">Works seamlessly on all devices</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-gradient text-white py-16 mt-20">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 mb-12">
                <div>
                    <h3 class="text-2xl font-bold mb-4 flex items-center gap-3">
                        <div class="icon-container p-2 rounded-lg">
                            <i class="fas fa-school text-white"></i>
                        </div>
                        CHMSU
                    </h3>
                    <p class="text-white/80 leading-relaxed">
                        Carlos Hilado Memorial State University<br />
                        Business Affairs Office Information System
                    </p>
                </div>
                <div>
                    <h3 class="text-xl font-bold mb-4">Quick Links</h3>
                    <ul class="space-y-3">
                        <li><a href="login.php" class="text-white/80 hover:text-white transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-sm"></i> Login</a></li>
                        <li><a href="register.php" class="text-white/80 hover:text-white transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-sm"></i> Register</a></li>
                        <li><a href="#features" class="text-white/80 hover:text-white transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-sm"></i> Features</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-xl font-bold mb-4">Contact</h3>
                    <p class="text-white/80 leading-relaxed">
                        <i class="fas fa-building mr-2"></i> Business Affairs Office<br />
                        <i class="fas fa-map-marker-alt mr-2"></i> CHMSU Campus<br />
                        <i class="fas fa-envelope mr-2"></i> bao@chmsu.edu.ph<br />
                        <i class="fas fa-phone mr-2"></i> (123) 456-7890
                    </p>
                </div>
            </div>
            <div class="border-t border-white/20 pt-8 text-center text-white/80">
                <p>&copy; <?php echo date("Y"); ?> Carlos Hilado Memorial State University. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>

