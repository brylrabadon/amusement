-- Migration v5: email verification + abandoned payment notification
ALTER TABLE users
  ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
  ADD COLUMN email_verify_token VARCHAR(64) NULL AFTER email_verified,
  ADD COLUMN email_verify_expires DATETIME NULL AFTER email_verify_token;
