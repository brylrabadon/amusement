CREATE DATABASE IF NOT EXISTS amusepark;
USE amusepark;

-- Users Table
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(20),
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer', 'admin') DEFAULT 'customer',
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Rides Table
CREATE TABLE rides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  category ENUM('Thrill','Family','Kids','Water','Classic') DEFAULT 'Family',
  status ENUM('Open','Closed','Maintenance') DEFAULT 'Open',
  duration_minutes INT,
  min_height_cm INT,
  max_capacity INT,
  image_url LONGTEXT,
  is_featured TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Ticket Types Table
CREATE TABLE ticket_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  category ENUM('Single Day','Season Pass','Group','VIP','Child','Senior') DEFAULT 'Single Day',
  price DECIMAL(10,2) NOT NULL,
  max_rides INT,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bookings Table
CREATE TABLE bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_reference VARCHAR(50) NOT NULL UNIQUE,
  user_id INT,
  customer_name VARCHAR(150) NOT NULL,
  customer_email VARCHAR(150) NOT NULL,
  customer_phone VARCHAR(20),
  visit_date DATE NOT NULL,
  ticket_type_id INT,
  ticket_type_name VARCHAR(150),
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  payment_status ENUM('Pending','Paid','Cancelled','Refunded') DEFAULT 'Pending',
  payment_method VARCHAR(50) DEFAULT 'QR Ph',
  payment_reference VARCHAR(100),
  qr_code_data TEXT,
  status ENUM('Active','Used','Expired','Cancelled') DEFAULT 'Active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE SET NULL
);

-- Seed default admin
INSERT INTO users (full_name, email, phone, password_hash, role)
VALUES ('Admin', 'admin@amusepark.com', '', SHA2('Admin1234', 256), 'admin');

-- Seed sample ticket types
INSERT INTO ticket_types (name, description, category, price, max_rides, is_active) VALUES
('Regular Day Pass', 'Full day access to all standard rides', 'Single Day', 350.00, NULL, 1),
('Child Pass', 'For kids 3-12 years old', 'Child', 200.00, NULL, 1),
('Senior Pass', 'For guests 60 years and above', 'Senior', 200.00, NULL, 1),
('VIP All-Access', 'Skip the line + all premium rides', 'VIP', 850.00, NULL, 1),
('Group Package (10pax)', 'Group of 10 with 10% discount', 'Group', 3150.00, NULL, 1),
('Season Pass', 'Unlimited visits for 1 year', 'Season Pass', 2500.00, NULL, 1);

-- Seed sample rides
INSERT INTO rides (name, description, category, status, duration_minutes, min_height_cm, max_capacity, is_featured) VALUES
('Dragon Coaster', 'The most thrilling roller coaster in the park!', 'Thrill', 'Open', 3, 120, 24, 1),
('Splash Zone', 'Cool off with our epic water ride', 'Water', 'Open', 5, 110, 12, 1),
('Kiddie Carousel', 'A classic carousel perfect for little ones', 'Kids', 'Open', 4, 0, 20, 0),
('Sky Tower', 'See the whole park from 60 meters up', 'Thrill', 'Open', 2, 130, 8, 1),
('Family Flume', 'A gentle water ride for the whole family', 'Family', 'Open', 6, 90, 6, 0),
('Bumper Cars', 'Classic bumper car fun for all ages', 'Classic', 'Open', 5, 0, 16, 0);