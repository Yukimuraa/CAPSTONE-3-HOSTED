# Database Setup Instructions

## Problem
The error "Table 'chmsu_bao.user_accounts' doesn't exist" occurs because the database tables haven't been created yet.

## Solution
Follow these steps to set up the database:

### Method 1: Automated Setup (Recommended)
1. Make sure your XAMPP Apache and MySQL services are running
2. Open your web browser
3. Go to: `http://localhost/Capstone-3/setup_database.php`
   - OR directly: `http://localhost/Capstone-3/database/setup_complete.php`
4. Wait for the setup to complete
5. Once you see "Setup Complete!", click "Go to Login Page"

### Method 2: Manual SQL Import
1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Select the database `chmsu_bao` (create it if it doesn't exist)
3. Click on "Import" tab
4. Choose the file: `database/complete_database_setup.sql`
5. Click "Go" to import

### Method 3: Using PHP Setup Scripts
You can also run individual setup scripts:
- `database/setup_user_accounts.php` - Creates user accounts table
- `database/setup_bus.php` - Creates bus management tables
- `database/setup_calendar.php` - Creates facility booking tables
- `database/setup_complete.php` - Creates ALL tables at once (recommended)

## After Setup

### Default Login Credentials
All sample accounts use password: `admin123`

- **Admin:** admin@chmsu.edu.ph
- **Staff:** staff@chmsu.edu.ph
- **Student:** student@chmsu.edu.ph
- **External:** external@example.com

## Tables Created

The setup creates the following tables:
1. `user_accounts` - User authentication and profiles
2. `inventory` - Product/item inventory
3. `orders` - Order management
4. `buses` - Bus fleet information
5. `bus_schedules` - Bus booking schedules
6. `bus_bookings` - Active bus bookings
7. `billing_statements` - Billing and receipts
8. `facilities` - Facility information (gym, courts, etc.)
9. `bookings` - Facility bookings
10. `requests` - General user requests

## Troubleshooting

### Connection Failed Error
- Make sure MySQL is running in XAMPP
- Check `config/database.php` for correct credentials
- Default: host=localhost, user=root, password=(empty), database=chmsu_bao

### Table Already Exists Error
- This is normal if you run the setup multiple times
- The script uses `CREATE TABLE IF NOT EXISTS` to prevent errors
- Sample data uses `ON DUPLICATE KEY UPDATE` to prevent duplicates

### Permission Denied Error
- Make sure you have write permissions to the database
- Run XAMPP as administrator if needed

## Need Help?
If you encounter any issues:
1. Check that XAMPP is running
2. Verify the database name in `config/database.php` matches your MySQL database
3. Ensure you have proper MySQL user permissions
4. Check the error messages in the setup script for specific issues









































































