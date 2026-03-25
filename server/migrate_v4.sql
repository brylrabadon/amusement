-- ============================================================
-- AmusePark v4 Migration
-- Run in phpMyAdmin → select `amusepark` DB → SQL tab → Go
-- ============================================================

USE amusepark;

-- 1. Proper relational table for booking ↔ rides (replaces comma-separated values)
CREATE TABLE IF NOT EXISTS booking_rides (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  ride_id    INT NOT NULL,
  UNIQUE KEY uq_booking_ride (booking_id, ride_id),
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (ride_id)    REFERENCES rides(id)    ON DELETE CASCADE
);

-- 2. Add expires_at column to bookings for explicit 3-min deadline tracking
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER created_at;

-- 3. Add payment_deadline column (same as expires_at, shown to customer)
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS payment_deadline DATETIME NULL AFTER expires_at;

-- 4. Ensure tickets table has all needed columns
ALTER TABLE tickets
  ADD COLUMN IF NOT EXISTS scanned_at DATETIME NULL AFTER status,
  ADD COLUMN IF NOT EXISTS scanned_by INT NULL AFTER scanned_at;

-- 5. Backfill expires_at for existing pending bookings (3 min from created_at)
UPDATE bookings
SET expires_at = DATE_ADD(created_at, INTERVAL 3 MINUTE),
    payment_deadline = DATE_ADD(created_at, INTERVAL 3 MINUTE)
WHERE expires_at IS NULL AND payment_status = 'Pending';

-- ============================================================
-- Done!
-- ============================================================
