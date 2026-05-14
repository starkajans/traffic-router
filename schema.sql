-- Traffic Router database schema
-- MySQL 5.7+ / MariaDB 10.2+

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(64) PRIMARY KEY,
    `value` TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    root_node_id INT NULL,
    default_redirect_url TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tree_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    node_type ENUM('check','redirect') NOT NULL,
    variable VARCHAR(32) NULL,
    redirect_url TEXT NULL,
    delivery_mode VARCHAR(20) NOT NULL DEFAULT 'redirect',
    label VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tree_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_node_id INT NOT NULL,
    child_node_id INT NULL,
    match_operator VARCHAR(20) NOT NULL,
    match_value TEXT NULL,
    label VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_parent (parent_node_id),
    FOREIGN KEY (parent_node_id) REFERENCES tree_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    html LONGTEXT NOT NULL,
    prompt TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NULL,
    hit_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    country_code CHAR(2) NULL,
    country_name VARCHAR(100) NULL,
    language VARCHAR(10) NULL,
    device_type VARCHAR(20) NULL,
    os VARCHAR(50) NULL,
    browser VARCHAR(50) NULL,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    bot_name VARCHAR(100) NULL,
    bot_category VARCHAR(20) NULL,
    ad_platform VARCHAR(20) NULL,
    referrer_host VARCHAR(255) NULL,
    referrer TEXT NULL,
    user_agent TEXT NULL,
    matched_node_id INT NULL,
    redirect_url TEXT NULL,
    INDEX idx_campaign_time (campaign_id, hit_at),
    INDEX idx_country (country_code),
    INDEX idx_is_bot (is_bot),
    INDEX idx_bot_category (bot_category),
    INDEX idx_ad_platform (ad_platform),
    INDEX idx_hit_at (hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
