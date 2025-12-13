<?php
// Setup script for user accounts system
require_once dirname(__DIR__) . '/config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>User Accounts Setup</title>";
echo "<style>body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }";
echo ".success { color: green; } .error { color: red; } .info { color: blue; }";
echo "ul { line-height: 2; } a { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #1E40AF; color: white; text-decoration: none; border-radius: 5px; }";
echo "</style></head><body>";

echo "<h1>User Accounts Table Setup</h1>";

// Read and execute the SQL file
$sql = file_get_contents(__DIR__ . '/user_accounts_tables.sql');

// Split the SQL into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success_count = 0;
$error_count = 0;
$errors = [];

foreach ($statements as $statement) {
    if (!empty($statement) && !preg_match('/^--/', $statement)) {
        if ($conn->query($statement)) {
            $success_count++;
        } else {
            $error_count++;
            $errors[] = $conn->error;
            echo "<p class='error'>✗ Error: " . $conn->error . "</p>";
        }
    }
}

echo "<hr>";
echo "<h2>Setup Results</h2>";
echo "<p class='info'>Successful statements: $success_count</p>";

if ($error_count > 0) {
    echo "<p class='error'>Failed statements: $error_count</p>";
} else {
    echo "<p class='success'>Failed statements: $error_count</p>";
}

// Verify the table was created
$result = $conn->query("SHOW TABLES LIKE 'user_accounts'");
if ($result && $result->num_rows > 0) {
    echo "<p class='success'>✓ user_accounts table exists!</p>";
    
    // Check the structure
    $structure = $conn->query("DESCRIBE user_accounts");
    if ($structure) {
        echo "<h3>Table Structure:</h3>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $structure->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Count users
    $count_result = $conn->query("SELECT COUNT(*) as count FROM user_accounts");
    if ($count_result) {
        $count = $count_result->fetch_assoc()['count'];
        echo "<p class='success'>✓ Found $count user(s) in the database</p>";
    }
    
    echo "<h3>Sample Login Credentials:</h3>";
    echo "<p>All sample accounts use password: <strong>admin123</strong></p>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> admin@chmsu.edu.ph</li>";
    echo "<li><strong>Staff:</strong> staff@chmsu.edu.ph</li>";
    echo "<li><strong>Student:</strong> student@chmsu.edu.ph</li>";
    echo "<li><strong>External:</strong> external@example.com</li>";
    echo "</ul>";
    
    echo "<p class='success' style='font-weight: bold; font-size: 18px;'>✓ Setup Complete! You can now use the login and registration features.</p>";
    echo "<p><a href='../login.php'>Go to Login Page</a> <a href='../register.php' style='background: #059669;'>Go to Register Page</a></p>";
    
} else {
    echo "<p class='error'>✗ user_accounts table was not created!</p>";
    echo "<p>Please check the errors above and try again.</p>";
}

$conn->close();

echo "</body></html>";
?>









































































