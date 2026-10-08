-- MySQL 5.7.44 compatible. InnoDB transactions; timestamps are UTC Unix seconds.
CREATE TABLE IF NOT EXISTS schema_versions (version INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS users (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 username VARCHAR(191) NOT NULL UNIQUE, email VARCHAR(191) NULL UNIQUE, display_name VARCHAR(80) NOT NULL,
 password_hash VARCHAR(255) NULL, email_verified TINYINT NOT NULL DEFAULT 0,
 enabled TINYINT NOT NULL DEFAULT 1, role VARCHAR(16) NOT NULL DEFAULT 'user', session_version INT NOT NULL DEFAULT 1,
 totp_secret TEXT NULL, totp_last_step BIGINT NOT NULL DEFAULT -1, recovery_hashes TEXT NULL,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE IF NOT EXISTS identities (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 app_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 openid VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY identity_app_openid(app_id,openid), UNIQUE KEY identity_user_app(user_id,app_id),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS clients (
 id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 name VARCHAR(191) NOT NULL, secret_hash VARCHAR(255) NULL, confidential TINYINT NOT NULL,
 redirect_uris TEXT NOT NULL, logout_uris TEXT NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_requests (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 browser_hash CHAR(64) CHARACTER SET ascii NOT NULL, key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 intent VARCHAR(8) NOT NULL, payload MEDIUMTEXT NOT NULL, client_name VARCHAR(191) NOT NULL,
 target_user CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'WAITING', app_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 openid VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 qr_image MEDIUMBLOB NULL, qr_type VARCHAR(32) NULL,
 created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, KEY login_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS email_codes (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 purpose VARCHAR(16) NOT NULL, email VARCHAR(191) NOT NULL, browser_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 code_hash CHAR(64) CHARACTER SET ascii NOT NULL, attempts INT NOT NULL DEFAULT 0, consumed TINYINT NOT NULL DEFAULT 0,
 expires_at BIGINT NOT NULL, KEY email_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS auth_codes (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 client_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 nonce VARCHAR(255) NOT NULL, auth_time BIGINT NOT NULL, session_version INT NOT NULL,
 revoked TINYINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL, KEY codes_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS access_tokens (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 client_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scopes TEXT NOT NULL, nonce VARCHAR(255) NOT NULL, auth_time BIGINT NOT NULL, session_version INT NOT NULL,
 revoked TINYINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL,
 KEY access_user(user_id), KEY access_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS refresh_tokens (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 access_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revoked TINYINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL, KEY refresh_access(access_id), KEY refresh_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS rate_limits (id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,hits INT NOT NULL,window_start BIGINT NOT NULL,KEY rate_cleanup(window_start)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settings (name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,value MEDIUMTEXT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,event VARCHAR(64) NOT NULL,user_id CHAR(36) CHARACTER SET ascii NULL,ip_hash CHAR(64) CHARACTER SET ascii NOT NULL,detail VARCHAR(255) NOT NULL,created_at BIGINT NOT NULL,KEY audit_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_versions(version) VALUES(1);
