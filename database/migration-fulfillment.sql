-- Run this SQL once on your africa_trade_os database (or let app/bootstrap.php auto-apply it):
ALTER TABLE orders
  ADD COLUMN seller_rejected_at DATETIME NULL,
  ADD COLUMN seller_rejection_reason VARCHAR(255) NULL,
  ADD COLUMN admin_order_approved_at DATETIME NULL,
  ADD COLUMN delivery_otp_code VARCHAR(10) NULL,
  ADD COLUMN delivery_otp_verified_at DATETIME NULL;

ALTER TABLE shipments
  ADD COLUMN distance_km DECIMAL(10,1) NULL,
  ADD COLUMN estimated_hours INT NULL,
  ADD COLUMN estimated_delivery_at DATETIME NULL,
  ADD COLUMN corridor_note VARCHAR(255) NULL;
