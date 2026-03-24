-- ============================================================
-- AmusePark v3 Migration — Add staff role + PayMongo fields
-- Instructions:
--   1. Open phpMyAdmin → select the `amusepark` database
--   2. Click the SQL tab
--   3. Paste this entire file and click Go
-- ============================================================

USE amusepark;

-- Add 'staff' to the users role ENUM
ALTER TABLE users
  MODIFY COLUMN role ENUM('customer','admin','staff') NOT NULL DEFAULT 'customer';

-- Add PayMongo tracking columns to bookings
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS paymongo_intent_id  VARCHAR(120) NULL AFTER payment_reference,
  ADD COLUMN IF NOT EXISTS paymongo_qr_image   MEDIUMTEXT   NULL AFTER paymongo_intent_id,
  ADD COLUMN IF NOT EXISTS paymongo_qr_code_id VARCHAR(120) NULL AFTER paymongo_qr_image;

-- ============================================================
-- Done! Staff role and PayMongo columns are now available.
-- ============================================================
