-- Create database
CREATE DATABASE IF NOT EXISTS boots_qc_db;
USE boots_qc_db;

-- Create users table for quality checkers
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    EFC_no VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    age INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create qcc table for quality checking counters
CREATE TABLE IF NOT EXISTS qcc (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create admin table
CREATE TABLE IF NOT EXISTS admin (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create operations table
CREATE TABLE IF NOT EXISTS operations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    operation VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create defects table
CREATE TABLE IF NOT EXISTS defects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    defect VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create styles table
CREATE TABLE IF NOT EXISTS styles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    style_no VARCHAR(4) NOT NULL,
    po_no VARCHAR(6) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY style_po_unique (style_no, po_no)
);

-- Create qc_records table
CREATE TABLE IF NOT EXISTS qc_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    style_no VARCHAR(4) NOT NULL,
    po_no VARCHAR(6) NOT NULL,
    operation_id INT NOT NULL,
    defect_id INT,
    user_id INT NOT NULL,
    qcc_id INT NOT NULL,
    status ENUM('Pass', 'Rework', 'Reject') NOT NULL,
    quantity INT DEFAULT 1,
    check_date DATE NOT NULL,
    check_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (operation_id) REFERENCES operations(id),
    FOREIGN KEY (defect_id) REFERENCES defects(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (qcc_id) REFERENCES users(id),
    FOREIGN KEY (style_no, po_no) REFERENCES styles(style_no, po_no)
);

-- Insert sample QCC user
INSERT INTO qcc (username, password) VALUES 
('test', 'password');

-- Insert sample admin user
INSERT INTO admin (username, password) VALUES 
('admin', 'admin');

-- Insert sample quality checker
INSERT INTO users (EFC_no, name, age) VALUES 
('EFC001', 'John Doe', 25);

-- Insert sample operations
INSERT INTO operations (operation) VALUES 
('Stitching'),
('Sole Attachment'),
('Upper Assembly'),
('Finishing');

-- Insert sample defects
INSERT INTO defects (defect) VALUES 
('Loose Stitching'),
('Uneven Sole'),
('Color Mismatch'),
('Size Issue'),
('Material Defect'); 