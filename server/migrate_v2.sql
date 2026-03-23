-- ============================================================
-- AmusePark v2 Migration — SAFE TO RUN MULTIPLE TIMES
-- Instructions:
--   1. Open phpMyAdmin → select the `amusepark` database
--   2. Click the SQL tab
--   3. Paste this entire file and click Go
-- ============================================================

USE amusepark;

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
--         (safe — only runs if those values still exist)
-- ============================================================
UPDATE ticket_types SET category = 'Single Day' WHERE category IN ('Child', 'Senior');

ALTER TABLE ticket_types MODIFY COLUMN category
  ENUM('Single Day','Season Pass','Group','VIP') DEFAULT 'Single Day';

-- ============================================================
-- STEP 4: Deactivate all old ticket types, keep only One-Day Pass
-- ============================================================
UPDATE ticket_types SET is_active = 0;

INSERT INTO ticket_types (name, description, category, price, max_rides, is_active)
  VALUES ('One-Day Pass', 'Full day access to all included rides', 'Single Day', 350.00, NULL, 1)
  ON DUPLICATE KEY UPDATE is_active = 1;

-- Make sure it's active even if it already existed
UPDATE ticket_types SET is_active = 1 WHERE name = 'One-Day Pass';

-- ============================================================
-- STEP 5: Assign all rides to the One-Day Pass
-- ============================================================
INSERT IGNORE INTO ticket_ride (ticket_type_id, ride_id)
  SELECT tt.id, r.id
  FROM ticket_types tt
  CROSS JOIN rides r
  WHERE tt.name = 'One-Day Pass';

-- ============================================================
-- Done! Your database is ready.
-- ============================================================
