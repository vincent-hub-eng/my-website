-- Africa Trade OS production upgrade (run ONCE after backing up your database).
-- This script is additive and does not drop or truncate existing records.
ALTER TABLE products MODIFY image_path VARCHAR(255) NULL DEFAULT NULL;
UPDATE payments SET checkout_request_id=NULL WHERE checkout_request_id='';
ALTER TABLE payments MODIFY checkout_request_id VARCHAR(120) NULL UNIQUE;
ALTER TABLE payments MODIFY escrow_state ENUM('awaiting_payment','held_in_escrow','released_to_seller','refunded_to_buyer') NOT NULL DEFAULT 'awaiting_payment';
ALTER TABLE payments MODIFY status ENUM('initiated','pending','successful','failed','refunded') NOT NULL DEFAULT 'initiated';
ALTER TABLE payments ADD COLUMN provider_reference VARCHAR(120) NULL;
ALTER TABLE orders
  ADD COLUMN payout_status ENUM('not_applicable','awaiting_transfer','paid','failed') NOT NULL DEFAULT 'not_applicable',
  ADD COLUMN payout_reference VARCHAR(120) NULL,
  ADD COLUMN payout_confirmed_at DATETIME NULL,
  ADD COLUMN stock_reserved TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN stock_released_at DATETIME NULL,
  ADD COLUMN seller_confirmed_at DATETIME NULL,
  ADD COLUMN admin_dispatch_approved_at DATETIME NULL,
  ADD COLUMN logistics_collected_at DATETIME NULL,
  ADD COLUMN logistics_delivered_at DATETIME NULL,
  ADD COLUMN admin_delivery_confirmed_at DATETIME NULL,
  ADD COLUMN escrow_released_at DATETIME NULL;
ALTER TABLE users ADD COLUMN mobile_kyc_expires_at DATETIME NULL;
ALTER TABLE users MODIFY email_verification_code VARCHAR(255) NULL;
ALTER TABLE users MODIFY id_number VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN email_verification_expires_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS login_attempts (id BIGINT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL, ip_hash CHAR(64) NOT NULL, attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_login_attempts (email, ip_hash, attempted_at));
-- Lock the original public demo credentials if they are still present. Create a new admin with scripts/create-admin.php.
UPDATE users SET account_status='suspended' WHERE email IN ('admin@africatradeos.com','seller@africatradeos.com');
ALTER TABLE disputes ADD COLUMN payment_id BIGINT NULL;
ALTER TABLE disputes ADD COLUMN affects_order TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE disputes ADD CONSTRAINT fk_disputes_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS order_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    actor_id BIGINT NULL,
    event_type VARCHAR(80) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_events_order (order_id, created_at),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS email_outbox (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(190) NOT NULL,
    recipient_name VARCHAR(150) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    html_body MEDIUMTEXT NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_email_outbox_retry (status, next_attempt_at)
);
