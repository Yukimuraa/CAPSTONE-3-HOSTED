<?php
// Update passwords with correct hashes
require_once dirname(__DIR__) . '/config/database.php';

echo "<h2>Updating User Passwords</h2>";

// Generate correct password hash for 'admin123'
$password = 'admin123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

echo "<p>Generated password hash: " . $hashed_password . "</p>";

// Update all user passwords
$update_passwords = "UPDATE user_accounts SET password = ?";
$stmt = $conn->prepare($update_passwords);
$stmt->bind_param("s", $hashed_password);

if ($stmt->execute()) {
    echo "<p style='color: green;'>✓ All user passwords updated successfully</p>";
} else {
    echo "<p style='color: red;'>✗ Error updating passwords: " . $conn->error . "</p>";
}

// Verify the update
$result = $conn->query("SELECT email, password FROM user_accounts");
if ($result) {
    echo "<p>Updated passwords for users:</p>";
    while ($row = $result->fetch_assoc()) {
        echo "<p>" . $row['email'] . " - " . substr($row['password'], 0, 20) . "...</p>";
    }
}

echo "<hr>";
echo "<h3>Password Update Complete!</h3>";
echo "<p style='color: green; font-weight: bold;'>All passwords have been updated to 'admin123'</p>";

echo "<p><a href='../test_login.php' style='background: #1E40AF; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Test Login Again</a></p>";

$conn->close();
?>










































































