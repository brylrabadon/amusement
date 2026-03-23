-- ============================================================
-- AmusePark v2 Migration — SAFE TO RUN MULTIPLE TIMES
<<<<<<< HEAD
--
-- HOW TO RUN (IMPORTANT):
--   1. Open phpMyAdmin
--   2. Click on the `amusepark` database in the LEFT sidebar
--      (make sure it says "Database: amusepark" at the top)
--   3. Click the SQL tab
--   4. Paste this entire file and click Go
--
-- DO NOT run this while `migrate_v2` or any other database
-- is selected — it must be run inside `amusepark`.
-- ============================================================

-- Safety: force correct database
USE amusepark;

-- Disable FK checks so tables can be created/altered safely
SET FOREIGN_KEY_CHECKS = 0;

=======
-- Instructions:
--   1. Open phpMyAdmin → select the `amusepark` database
--   2. Click the SQL tab
--   3. Paste this entire file and click Go
-- ============================================================

USE amusepark;

>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
-- ============================================================
-- STEP 1: ticket_ride mapping table
-- ============================================================
CREATE TABLE IF NOT EXISTS ticket_ride (
  ticket_type_id INT NOT NULL,
  ride_id        INT NOT NULL,
  PRIMARY KEY (ticket_type_id, ride_id),
  FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE,
  FOREIGN KEY (ride_id)        REFERENCES rides(id)        ON DELETE CASCADE
);

-- ============================================================
-- STEP 2: tickets table (one row per scannable entry ticket)
-- ============================================================
CREATE TABLE IF NOT EXISTS tickets (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  booking_id    INT NOT NULL,
  ticket_number VARCHAR(80) NOT NULL UNIQUE,
  status        ENUM('ACTIVE','USED','CANCELLED','EXPIRED') DEFAULT 'ACTIVE',
  scanned_at    DATETIME NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- ============================================================
-- STEP 3: Fix ticket_types ENUM to remove Child/Senior
<<<<<<< HEAD
=======
--         (safe — only runs if those values still exist)
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
-- ============================================================
UPDATE ticket_types SET category = 'Single Day' WHERE category IN ('Child', 'Senior');

ALTER TABLE ticket_types MODIFY COLUMN category
  ENUM('Single Day','Season Pass','Group','VIP') DEFAULT 'Single Day';

-- ============================================================
<<<<<<< HEAD
-- STEP 4: Ensure One-Day Pass exists and is active
=======
-- STEP 4: Deactivate all old ticket types, keep only One-Day Pass
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
-- ============================================================
UPDATE ticket_types SET is_active = 0;

INSERT INTO ticket_types (name, description, category, price, max_rides, is_active)
  VALUES ('One-Day Pass', 'Full day access to all included rides', 'Single Day', 350.00, NULL, 1)
  ON DUPLICATE KEY UPDATE is_active = 1;

<<<<<<< HEAD
=======
-- Make sure it's active even if it already existed
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
UPDATE ticket_types SET is_active = 1 WHERE name = 'One-Day Pass';

-- ============================================================
-- STEP 5: Assign all rides to the One-Day Pass
-- ============================================================
INSERT IGNORE INTO ticket_ride (ticket_type_id, ride_id)
  SELECT tt.id, r.id
  FROM ticket_types tt
  CROSS JOIN rides r
  WHERE tt.name = 'One-Day Pass';

<<<<<<< HEAD
-- Re-enable FK checks
SET FOREIGN_KEY_CHECKS = 1;

=======
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
-- ============================================================
-- Done! Your database is ready.
-- ============================================================
