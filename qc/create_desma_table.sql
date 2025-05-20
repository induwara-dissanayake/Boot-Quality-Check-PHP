CREATE TABLE IF NOT EXISTS qc_desma_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    style_no VARCHAR(4) NOT NULL,
    po_no VARCHAR(6) NOT NULL,
    defect_id INT,
    user_id INT NOT NULL,
    qcc_id INT NOT NULL,
    status ENUM('Pass', 'Rework', 'Reject') NOT NULL,
    quantity INT DEFAULT 1,
    check_date DATE NOT NULL,
    check_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (defect_id) REFERENCES defects(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (qcc_id) REFERENCES users(id),
    FOREIGN KEY (style_no, po_no) REFERENCES styles(style_no, po_no)
); 