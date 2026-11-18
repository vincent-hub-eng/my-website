CREATE DATABASE IF NOT EXISTS africa_trade_os
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE africa_trade_os;

-- Safe, non-destructive schema: existing tables and records are never dropped.

CREATE TABLE IF NOT EXISTS countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    iso2 CHAR(2) NOT NULL UNIQUE,
    currency_code CHAR(3) NOT NULL,
    phone_code VARCHAR(10),
    region VARCHAR(60) DEFAULT 'East Africa',
    is_active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(30),
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('buyer','seller','supplier','logistics','admin') NOT NULL DEFAULT 'buyer',
    can_buy TINYINT(1) DEFAULT 1,
    can_sell TINYINT(1) DEFAULT 0,
    country_id INT,
    email_verified_at DATETIME NULL,
    email_verification_code VARCHAR(255) NULL,
    email_verification_expires_at DATETIME NULL,
    password_reset_token VARCHAR(100) NULL,
    password_reset_expires DATETIME NULL,
    id_number VARCHAR(255) NULL,
    id_front_path VARCHAR(255) NULL,
    id_back_path VARCHAR(255) NULL,
    selfie_path VARCHAR(255) NULL,
    mobile_kyc_token VARCHAR(64) NULL,
    mobile_kyc_expires_at DATETIME NULL,
    id_verification_status ENUM('not_required','unsubmitted','pending','verified','rejected') DEFAULT 'not_required',
    terms_accepted_at DATETIME NULL,
    account_status ENUM('pending','active','suspended','rejected') DEFAULT 'active',
    rating DECIMAL(3,2) DEFAULT 5.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL,
    INDEX idx_user_role (role),
    INDEX idx_id_verif (id_verification_status)
);

CREATE TABLE IF NOT EXISTS business_profiles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL UNIQUE,
    business_name VARCHAR(190) NOT NULL,
    tagline VARCHAR(255),
    city VARCHAR(100),
    address TEXT,
    country_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL UNIQUE,
    icon VARCHAR(50) DEFAULT 'box',
    spec_template JSON NULL,
    is_active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS products (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    seller_id BIGINT NOT NULL,
    category_id INT,
    sku VARCHAR(60) NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT,
    specifications TEXT,
    selected_specs JSON NULL,
    available_colors VARCHAR(255) NULL,
    condition_grade VARCHAR(40) DEFAULT 'Brand New',
    warranty_info VARCHAR(100) NULL,
    shipping_scope ENUM('domestic','cross_border','both') DEFAULT 'both',
    unit VARCHAR(40) DEFAULT 'piece',
    minimum_order_quantity DECIMAL(14,2) DEFAULT 1,
    price DECIMAL(14,2) NOT NULL,
    compare_price DECIMAL(14,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    stock_quantity DECIMAL(14,2) DEFAULT 10,
    origin_country_id INT,
    image_path VARCHAR(255) NULL DEFAULT NULL,
    gallery_paths TEXT NULL,
    is_featured TINYINT(1) DEFAULT 0,
    status ENUM('active','inactive','rejected') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (origin_country_id) REFERENCES countries(id) ON DELETE SET NULL,
    INDEX idx_product_status (status)
);

CREATE TABLE IF NOT EXISTS rfqs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rfq_reference VARCHAR(50) NOT NULL UNIQUE,
    buyer_id BIGINT NOT NULL,
    product_id BIGINT NULL,
    category_id INT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT,
    quantity DECIMAL(14,2) NOT NULL,
    unit VARCHAR(40) DEFAULT 'pieces',
    target_price DECIMAL(14,2) NULL,
    currency CHAR(3) DEFAULT 'KES',
    destination_country_id INT,
    delivery_city VARCHAR(120),
    trade_type ENUM('domestic','cross_border') NOT NULL DEFAULT 'domestic',
    deadline DATE NULL,
    status ENUM('open','closed','awarded','cancelled') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (destination_country_id) REFERENCES countries(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS quotations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    quote_reference VARCHAR(50) NOT NULL UNIQUE,
    rfq_id BIGINT NOT NULL,
    seller_id BIGINT NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    quantity DECIMAL(14,2) NOT NULL,
    shipping_estimate DECIMAL(14,2) DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    delivery_days INT DEFAULT 5,
    terms TEXT,
    status ENUM('submitted','accepted','rejected','expired') DEFAULT 'submitted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_reference VARCHAR(50) NOT NULL UNIQUE,
    buyer_id BIGINT NOT NULL,
    seller_id BIGINT NOT NULL,
    quotation_id BIGINT NULL,
    product_id BIGINT NULL,
    title VARCHAR(190) NULL,
    selected_variant VARCHAR(190) NULL,
    quantity DECIMAL(14,2) DEFAULT 1,
    unit VARCHAR(40) DEFAULT 'pieces',
    trade_type ENUM('domestic','cross_border') NOT NULL DEFAULT 'domestic',
    delivery_address TEXT,
    subtotal DECIMAL(14,2) NOT NULL,
    shipping_cost DECIMAL(14,2) DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    escrow_status ENUM('unfunded','funded_held','released','refunded','disputed') DEFAULT 'unfunded',
    status ENUM('pending','confirmed','processing','shipped','delivered','completed','cancelled','disputed') DEFAULT 'pending',
    seller_confirmed_at DATETIME NULL,
    admin_dispatch_approved_at DATETIME NULL,
    logistics_collected_at DATETIME NULL,
    logistics_delivered_at DATETIME NULL,
    admin_delivery_confirmed_at DATETIME NULL,
    escrow_released_at DATETIME NULL,
    payout_status ENUM('not_applicable','awaiting_transfer','paid','failed') NOT NULL DEFAULT 'not_applicable',
    payout_reference VARCHAR(120) NULL,
    payout_confirmed_at DATETIME NULL,
    stock_reserved TINYINT(1) NOT NULL DEFAULT 0,
    stock_released_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (seller_id) REFERENCES users(id),
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    payer_id BIGINT NOT NULL,
    payment_reference VARCHAR(100) UNIQUE,
    checkout_request_id VARCHAR(120) NULL UNIQUE,
    mpesa_phone VARCHAR(30) NULL,
    mpesa_receipt VARCHAR(60) NULL,
    provider_reference VARCHAR(120) NULL,
    provider VARCHAR(60) NOT NULL,
    payment_method VARCHAR(60),
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    escrow_state ENUM('awaiting_payment','held_in_escrow','released_to_seller','refunded_to_buyer') DEFAULT 'awaiting_payment',
    status ENUM('initiated','pending','successful','failed','refunded') DEFAULT 'initiated',
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (payer_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS shipments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    logistics_provider_id BIGINT NULL,
    tracking_number VARCHAR(100) UNIQUE,
    carrier_name VARCHAR(150),
    transport_mode ENUM('road','air','sea','rail','rider') DEFAULT 'road',
    origin_hub VARCHAR(120),
    destination_hub VARCHAR(120),
    current_location VARCHAR(150),
    status ENUM('pending','collected','in_transit','at_customs','out_for_delivery','delivered','exception') DEFAULT 'pending',
    estimated_delivery DATE NULL,
    tracking_notes TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (logistics_provider_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS trade_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    uploaded_by BIGINT NOT NULL,
    doc_type ENUM('commercial_invoice','packing_list','certificate_of_origin','waybill','other') NOT NULL,
    document_number VARCHAR(100),
    title VARCHAR(190) NOT NULL,
    generated_content MEDIUMTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sender_id BIGINT NOT NULL,
    receiver_id BIGINT NOT NULL,
    subject VARCHAR(190) NULL,
    message TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    category VARCHAR(40) DEFAULT 'trade',
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS disputes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    dispute_reference VARCHAR(50) NOT NULL UNIQUE,
    order_id BIGINT NOT NULL,
    payment_id BIGINT NULL,
    affects_order TINYINT(1) NOT NULL DEFAULT 1,
    opened_by BIGINT NOT NULL,
    reason VARCHAR(190) NOT NULL,
    claim_amount DECIMAL(14,2) DEFAULT 0,
    description TEXT,
    status ENUM('open','under_review','resolved','rejected') DEFAULT 'open',
    resolution TEXT,
    resolved_by BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (opened_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(60),
    entity_id BIGINT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- African Countries & Currencies
INSERT IGNORE INTO countries (id, name, iso2, currency_code, phone_code, region) VALUES
(1, 'Kenya', 'KE', 'KES', '+254', 'East Africa'),
(2, 'Nigeria', 'NG', 'NGN', '+234', 'West Africa'),
(3, 'South Africa', 'ZA', 'ZAR', '+27', 'Southern Africa'),
(4, 'Ghana', 'GH', 'GHS', '+233', 'West Africa'),
(5, 'Tanzania', 'TZ', 'TZS', '+255', 'East Africa'),
(6, 'Uganda', 'UG', 'UGX', '+256', 'East Africa'),
(7, 'Rwanda', 'RW', 'RWF', '+250', 'East Africa'),
(8, 'Ethiopia', 'ET', 'ETB', '+251', 'East Africa'),
(9, 'Egypt', 'EG', 'EGP', '+20', 'North Africa'),
(10, 'Morocco', 'MA', 'MAD', '+212', 'North Africa');

-- Comprehensive Categories with Smart Spec Templates for Sellers
INSERT IGNORE INTO categories (id, name, slug, icon, spec_template) VALUES
(1, 'Laptops & Computers', 'laptops-computers', 'laptop', '{"RAM":["4GB","8GB","16GB","32GB","64GB"],"Storage":["128GB SSD","256GB SSD","512GB SSD","1TB SSD","2TB SSD"],"Processor":["Intel Core i3","Intel Core i5","Intel Core i7","Intel Core i9","AMD Ryzen 5","AMD Ryzen 7","Apple M1/M2/M3"],"Screen Size":["13.3 inch","14 inch","15.6 inch","16 inch"]}'),
(2, 'Phones & Tablets', 'phones-tablets', 'smartphone', '{"RAM":["3GB","4GB","6GB","8GB","12GB","16GB"],"Storage":["64GB","128GB","256GB","512GB","1TB"],"Network":["4G LTE","5G Dual SIM","eSIM + Physical SIM"],"Battery":["4000mAh","5000mAh","6000mAh"]}'),
(3, 'TVs, Audio & Electronics', 'electronics-appliances', 'tv', '{"Display":["HD Ready","Full HD 1080p","4K UHD","QLED / OLED"],"Size":["32 inch","43 inch","50 inch","55 inch","65 inch","75 inch"],"Smart Features":["Android TV","Google TV","WebOS","Bluetooth & Wi-Fi"]}'),
(4, 'Fashion, Shoes & Bags', 'fashion-footwear', 'shirt', '{"Sizes Available":["XS","S","M","L","XL","XXL","EU 38-45"],"Material":["100% Cotton","Genuine Leather","Polyester Blend","Denim","Canvas"],"Gender":["Men","Women","Unisex","Kids"]}'),
(5, 'Agri-Commodities & Produce', 'agri-commodities', 'sprout', '{"Grade":["Grade 1 Export","Grade A Local","Organic Certified","FairTrade"],"Packaging":["50kg Woven Bag","90kg Sack","10kg Carton","Bulk Loose"],"Moisture / Purity":["< 12% Moisture","99% Clean","Cold-Chain Fresh"]}'),
(6, 'Home, Kitchen & Furniture', 'home-kitchen', 'home', '{"Material":["Hardwood","Stainless Steel","Tempered Glass","MDF / Laminate"],"Capacity / Size":["Single / Compact","Family Size","Commercial Heavy-Duty"]}'),
(7, 'Health, Beauty & Cosmetics', 'health-beauty', 'sparkles', '{"Skin / Hair Type":["All Skin Types","Dry Skin","Oily Skin","Natural Hair"],"Certification":["KEBS Certified","Dermatologically Tested","100% Organic"]}'),
(8, 'Solar & Power Solutions', 'solar-power', 'sun', '{"Capacity":["100W - 500W","1kVA - 3kVA","5kVA - 10kVA","20kVA+ Industrial"],"Battery Type":["Lithium LiFePO4","Tubular Gel","Deep Cycle AGM"],"System Voltage":["12V","24V","48V Hybrid"]}'),
(9, 'Industrial, Construction & Hardware', 'industrial-hardware', 'wrench', '{"Duty Grade":["Light Commercial","Heavy Industrial","ISO Certified"],"Power Source":["Electric 220V","3-Phase 415V","Diesel / Petrol","Manual"]}'),
(10, 'Auto Parts & Accessories', 'auto-parts', 'car', '{"Compatibility":["Toyota / Lexus","Nissan / Mitsubishi","Subaru / Mazda","Mercedes / BMW","Universal Fit"],"Part Type":["OEM Genuine","Aftermarket Brand New","Refurbished"]}');



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

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts (email, ip_hash, attempted_at)
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
