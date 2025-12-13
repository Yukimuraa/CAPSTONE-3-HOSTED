-- User Accounts System Database Schema

-- Create user_accounts table
CREATE TABLE IF NOT EXISTS user_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('admin', 'staff', 'student', 'external') NOT NULL,
    organization VARCHAR(255) NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_user_email ON user_accounts(email);
CREATE INDEX IF NOT EXISTS idx_user_type ON user_accounts(user_type);
CREATE INDEX IF NOT EXISTS idx_user_status ON user_accounts(status);

-- Insert sample users (password for all: admin123)
INSERT INTO user_accounts (name, email, password, user_type, organization, status) VALUES
('System Administrator', 'admin@chmsu.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NULL, 'active'),
('BAO Staff', 'staff@chmsu.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NULL, 'active'),
('Test Student', 'student@chmsu.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', NULL, 'active'),
('External User', 'external@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'external', 'Sample Organization', 'active')
ON DUPLICATE KEY UPDATE name=name;









































































