-- nsmfy Database Schema
-- Compatible with MySQL/MariaDB (via XAMPP phpMyAdmin)

CREATE DATABASE IF NOT EXISTS `nsmfy` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `nsmfy`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `role` ENUM('buyer', 'seller', 'admin') DEFAULT 'buyer',
  `seller_status` ENUM('pending', 'approved', 'rejected', 'none') DEFAULT 'none',
  `sa_id_number` VARCHAR(13) DEFAULT NULL,
  `id_doc_path` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Products Table
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `seller_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `category` VARCHAR(80) NOT NULL,
  `condition_grade` ENUM('New', 'Like New', 'Good', 'Fair') NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `listing_type` ENUM('fixed', 'auction') NOT NULL DEFAULT 'fixed',
  `auction_hours` INT DEFAULT NULL,
  `auction_ends_at` DATETIME DEFAULT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `status` ENUM('active', 'sold', 'draft') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Bids Table
CREATE TABLE IF NOT EXISTS `bids` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `bidder_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`bidder_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Cart Items Table
CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `session_id` VARCHAR(64) DEFAULT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Orders Table
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_code` VARCHAR(20) NOT NULL UNIQUE,
  `buyer_id` INT DEFAULT NULL,
  `ship_name` VARCHAR(120) NOT NULL,
  `ship_address` VARCHAR(255) NOT NULL,
  `ship_city` VARCHAR(100) NOT NULL,
  `ship_province` VARCHAR(80) NOT NULL,
  `ship_postal` VARCHAR(10) NOT NULL,
  `ship_phone` VARCHAR(20) NOT NULL,
  `payment_method` ENUM('card', 'eft') NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `shipping_cost` DECIMAL(10,2) NOT NULL,
  `total` DECIMAL(10,2) NOT NULL,
  `status` ENUM('pending', 'shipped', 'completed', 'cancelled') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Order Items Table
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `image_path` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Messages Table
CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `sender_id` INT NOT NULL,
  `recipient_id` INT NOT NULL,
  `message_text` TEXT NOT NULL,
  `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Tracking Table
CREATE TABLE IF NOT EXISTS `tracking` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `seller_id` INT NOT NULL,
  `courier` VARCHAR(80) NOT NULL,
  `tracking_number` VARCHAR(80) NOT NULL,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================
-- SEED DATA
-- =====================================================

-- Users (Password for all is 'password123')
-- Hash: $2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2
INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `phone`, `role`, `seller_status`, `sa_id_number`, `id_doc_path`, `city`, `bio`) VALUES
(1, 'Admin Administrator', 'admin@nsmfy.co.za', '$2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2', '0821112222', 'admin', 'none', NULL, NULL, 'Cape Town', 'Platform administrator account.'),
(2, 'Alex M.', 'alex@gmail.com', '$2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2', '0821234567', 'seller', 'approved', '9001015000081', 'uploads/id_docs/alex_id.pdf', 'Johannesburg', 'Selling top quality pre-loved electronics and accessories.'),
(3, 'Sarah T.', 'sarah@gmail.com', '$2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2', '0837654321', 'seller', 'approved', '9102025000082', 'uploads/id_docs/sarah_id.pdf', 'Durban', 'Unpacking my home library. Lots of textbooks and novels.'),
(4, 'James L.', 'james@gmail.com', '$2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2', '0714445555', 'seller', 'approved', '8803035000083', 'uploads/id_docs/james_id.pdf', 'Pretoria', 'Office clearance sales and high-quality furniture.'),
(5, 'BrightDesigns', 'bright@gmail.com', '$2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2', '0726667777', 'seller', 'approved', '8904045000084', 'uploads/id_docs/bright_id.pdf', 'Port Elizabeth', 'Custom home decor and modern lighting designs.'),
(6, 'Elena R.', 'elena@gmail.com', '$2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2', '0648889999', 'seller', 'approved', '9505055000085', 'uploads/id_docs/elena_id.pdf', 'Cape Town', 'Passionate about retro style, leather goods, and fashion.'),
(7, 'Ryan K.', 'ryan@gmail.com', '$2y$10$8W3Y68nL3cI30BghB8Nf0.06e7Cms6C11H7yBebmCgOsh92dZq.J2', '0810001111', 'seller', 'approved', '9206065000086', 'uploads/id_docs/ryan_id.pdf', 'Stellenbosch', 'Developer selling my spare hardware and gear.');

-- Products 
INSERT INTO `products` (`id`, `seller_id`, `title`, `description`, `category`, `condition_grade`, `price`, `listing_type`, `auction_hours`, `auction_ends_at`, `image_path`, `status`) VALUES
(1, 2, 'Sony WH-1000XM4 Wireless Headphones', 'Active noise cancelling headphones in matte silver. Barely used, comes with original case and accessories.', 'ELECTRONICS', 'Like New', 2999.00, 'fixed', NULL, NULL, 'headphones.png', 'active'),
(2, 3, 'Calculus: Early Transcendentals (8th Edition)', 'Essential university mathematics reference textbook. Light notes inside, otherwise in great readable condition.', 'BOOKS & LITERATURE', 'Good', 450.00, 'fixed', NULL, NULL, 'calculus_book.png', 'active'),
(3, 4, 'Ergonomic Desk Chair with Lumbar Support', 'Adjustable height, tension control, and breathable mesh backing. Highly comfortable for home office sessions.', 'OFFICE & HOME', 'Good', 1250.00, 'auction', 24, DATE_ADD(NOW(), INTERVAL 4 HOUR), 'office_chair.png', 'active'),
(4, 5, 'Modern LED Desk Lamp with Wireless Charging', 'Adjustable design with wood detailing. Built-in wireless charging pad for smartphone convenience.', 'HOME DECOR', 'New', 399.00, 'fixed', NULL, NULL, 'desk_lamp.png', 'active'),
(5, 6, 'Premium Leather Campus Backpack', 'Vintage brown leather backpack with dedicated laptop sleeve. Spacious, durable, and highly weather-resistant.', 'FASHION & BAGS', 'Like New', 899.00, 'fixed', NULL, NULL, 'backpack.png', 'active'),
(6, 7, 'Keychron K2 Mechanical Keyboard', 'Compact wireless mechanical keyboard featuring tactile brown switches and full RGB backlight customization.', 'ELECTRONICS', 'Good', 1499.00, 'auction', 48, DATE_ADD(NOW(), INTERVAL 18 HOUR), 'keyboard.png', 'active');

-- Bids
INSERT INTO `bids` (`product_id`, `bidder_id`, `amount`) VALUES
(3, 2, 1250.00),
(6, 3, 1499.00);
