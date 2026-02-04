-- Create users table for authentication
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert a sample admin user (username: admin, password: admin123)
INSERT INTO `users` (`username`, `email`, `password`) VALUES 
('admin', 'admin@dhsud.com', '$2y$10$aBJpl0gNN4xrsYQn9nQm8OWOQnnPprmZAI9FdT5nTfHsLJCDDn7em');

