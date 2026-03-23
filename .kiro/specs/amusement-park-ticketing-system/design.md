# Design Document

## Amusement Park Ticketing System

---

## Overview

This design describes the implementation of all ten requirements into the existing PHP + MySQL AmusePark web application. The approach is additive: new tables, new PHP files, and targeted modifications to existing files. No framework is introduced; the existing PDO + session pattern is preserved throughout.

Key changes at a glance:
- `rides.php` becomes publicly accessible (no login required).
- The `ticket_types` table is simplified to a single "One-Day Pass" and the `ride_ids` CSV column is replaced by a normalized `ticket_ride` mapping table.
- A new `tickets` table stores one row per individual entry ticket (child of a booking).
- A new `admin/scanner.php` lets staff scan QR codes and mark individual tickets as used.
- A `cron/expire_bookings.php` script handles pending-booking expiry (3-minute limit) and sends cancellation emails via PHP's `mail()`.
- The booking confirmation screen gains a dismissible Reference_Popup modal showing all ticket numbers and ride details.
- The admin bookings view gains a ticket-number drill-down panel.

---

## Architecture

```
Browser
  │
  ├── Public (no auth)
  │     rides.php          ← browse rides, "Buy Tickets" → tickets.php
  │     index.php          ← landing page
  │
  ├── Customer (auth: role=customer)
  │     tickets.php        ← 4-step booking flow (select → details → pay → confirm+popup)
  │     my-bookings.php    ← booking history
  │     booking-qr.php     ← per-booking QR view
  │
  ├── Admin (auth: role=admin)
  │     admin/admin-dashboard.php
  │     admin/bookings.php          ← searchable list + ticket-number drill-down
  │     admin/rides.php
  │     admin/ticket-types.php      ← simplified (no Child/Senior categories)
  │     admin/scanner.php           ← NEW: QR scan → mark ticket USED
  │
  └── Cron
        cron/expire_bookings.php    ← NEW: expire pending bookings + send email
```

All pages share `config.php` (PDO singleton, helpers) and `lib/auth.php` (session auth).

---

## Components and Interfaces

### 1. Public Rides Page (`rides.php`)

- Remove the `require_login('customer')` guard; replace with an optional `current_user()` call so the nav adapts (show Login vs. Logout).
- "Buy Tickets" button links to `tickets.php`; unauthenticated users are redirected to `login.php` only when they reach the payment step.

### 2. Ticket Booking Flow (`tickets.php`)

Step 0 — Select ticket: shows the single One-Day Pass with its ride list pulled from `ticket_ride`.  
Step 1 — Details: customer name, email, phone, visit date. Login required here; unauthenticated users are redirected to `login.php?next=tickets.php%3Fstep%3D1`.  
Step 2 — Payment: QR Ph payment screen. On page load, call `expire_pending_bookings()` to cancel stale bookings.  
Step 3 — Confirm: booking summary + Reference_Popup modal + all individual ticket QR codes.

On `action=details` (step 1 → 2):
- Insert one row into `bookings`.
- Insert N rows into `tickets` (one per quantity unit), each with a unique `ticket_number` (`TK-<booking_ref>-<seq>`).

On `action=confirm_payment` (step 2 → 3):
- Check booking is still `Pending` and not expired; if expired, show "Booking expired" and abort.
- Set `bookings.payment_status = 'Paid'`.

### 3. Individual Tickets Table

New table `tickets` — one row per scannable entry. The booking flow inserts N rows when a booking is created.

### 4. Staff QR Scanner (`admin/scanner.php`)

- Accessible only to `role=admin`.
- Accepts a `ticket_number` via GET (from QR scan) or a manual text input form.
- Looks up the ticket; applies state machine:
  - `ACTIVE` → set to `USED`, display customer info + rides.
  - `USED` → display "Ticket Already Used" error, no DB change.
  - `CANCELLED` / `EXPIRED` → display rejection message, no DB change.

### 5. Booking Expiry (`cron/expire_bookings.php`)

- Callable as a cron job: `php cron/expire_bookings.php`
- Also callable as a function `expire_pending_bookings(PDO $pdo)` included by `tickets.php` on each checkout page load.
- Finds all bookings where `payment_status = 'Pending'` and `created_at < NOW() - INTERVAL 3 MINUTE`.
- For each: sets `payment_status = 'Cancelled'`, `status = 'Cancelled'`, then calls `send_cancellation_email()`.

### 6. Cancellation Email (`lib/mailer.php`)

New helper file `lib/mailer.php` with `send_cancellation_email(array $booking, array $tickets, PDO $pdo): bool`.

- Builds subject: `"Ticket Cancellation Notice"`.
- Body includes: ticket numbers, customer name, email, booking reference, visit date, ticket type name, quantity, total amount.
- If `visit_date >= today`: appends a `Continue_Booking_Link` (`tickets.php?prefill=<booking_ref>`).
- If `visit_date < today`: appends a permanent cancellation notice with no link.
- Uses PHP `mail()`. On failure, logs to `logs/email_failures.log` with booking reference and customer email.

### 7. Reference Popup (`tickets.php` step 3)

- A `<div id="ref-popup">` modal rendered server-side, visible by default (`display:block`).
- Contains: booking reference, customer name/email/phone, ticket type, quantity, visit date, payment datetime, total amount, ride list, and all individual ticket numbers.
- A "Got it" dismiss button sets `display:none` via inline JS and keeps the booking summary below visible.

### 8. Admin Bookings (`admin/bookings.php`)

- Search by booking reference, customer name, or email (already exists).
- Add a "View Tickets" expand/collapse section per booking row that lists all `tickets.ticket_number` values for that booking.
- Booking reference rendered in `<code>` tag with blue monospace styling (already partially done; ensure consistent).

### 9. Ticket Types Admin (`admin/ticket-types.php`)

- Remove `'Child'` and `'Senior'` from the `$categories` array.
- Remove the `ride_ids` CSV column logic; replace with `ticket_ride` join for ride assignment.
- Add a multi-select checklist of rides to assign to a ticket type (writes to `ticket_ride`).

---

## Data Models

### Schema Changes (migration SQL)

```sql
-- 1. New ticket_ride mapping table (normalized ride-to-ticket relationship)
CREATE TABLE IF NOT EXISTS ticket_ride (
  ticket_type_id INT NOT NULL,
  ride_id        INT NOT NULL,
  PRIMARY KEY (ticket_type_id, ride_id),
  FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE,
  FOREIGN KEY (ride_id)        REFERENCES rides(id)        ON DELETE CASCADE
);

-- 2. New tickets table (one row per individual entry ticket)
CREATE TABLE IF NOT EXISTS tickets (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  booking_id     INT NOT NULL,
  ticket_number  VARCHAR(80) NOT NULL UNIQUE,
  status         ENUM('ACTIVE','USED','CANCELLED','EXPIRED') DEFAULT 'ACTIVE',
  scanned_at     DATETIME NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- 3. Add email_log_path column to bookings for failure tracking (optional; log file preferred)
-- No schema change needed; failures go to logs/email_failures.log

-- 4. Simplify ticket_types: remove ride_ids CSV column if present
ALTER TABLE ticket_types DROP COLUMN IF EXISTS ride_ids;

-- 5. Ensure ticket_types has no Child/Senior category values
UPDATE ticket_types SET category = 'Single Day' WHERE category IN ('Child','Senior');
ALTER TABLE ticket_types MODIFY COLUMN category
  ENUM('Single Day','Season Pass','Group','VIP') DEFAULT 'Single Day';

-- 6. Seed the single One-Day Pass (remove all others or deactivate)
-- Run manually as part of migration:
-- DELETE FROM ticket_types WHERE name != 'One-Day Pass';
-- INSERT IGNORE INTO ticket_types (name, description, category, price, max_rides, is_active)
--   VALUES ('One-Day Pass', 'Full day access to all included rides', 'Single Day', 350.00, NULL, 1);
```

### Entity Relationships

```
users (1) ──< bookings (1) ──< tickets
                  │
ticket_types (1) ─┘
      │
ticket_ride >──< rides
```

### `tickets` table

| Column        | Type                                      | Notes                          |
|---------------|-------------------------------------------|--------------------------------|
| id            | INT PK AUTO_INCREMENT                     |                                |
| booking_id    | INT FK → bookings.id ON DELETE CASCADE    |                                |
| ticket_number | VARCHAR(80) UNIQUE NOT NULL               | e.g. `TK-AP-XXXXXX-001`        |
| status        | ENUM('ACTIVE','USED','CANCELLED','EXPIRED')| Default: ACTIVE               |
| scanned_at    | DATETIME NULL                             | Set when staff scans           |
| created_at    | DATETIME DEFAULT CURRENT_TIMESTAMP        |                                |

### `ticket_ride` table

| Column         | Type                                       | Notes              |
|----------------|--------------------------------------------|--------------------|
| ticket_type_id | INT FK → ticket_types.id ON DELETE CASCADE | Composite PK       |
| ride_id        | INT FK → rides.id ON DELETE CASCADE        | Composite PK       |

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Rides page displays all required fields

*For any* set of ride records in the database, the rendered rides landing page HTML should contain each ride's name, description, category, status, duration, minimum height, and image reference.

**Validates: Requirements 1.2**

---

### Property 2: One-Day Pass displays price, description, and ride allowance

*For any* One-Day Pass ticket type record, the rendered ticket selection page should contain the ticket's price, description, and a ride allowance section.

**Validates: Requirements 2.4**

---

### Property 3: Ride allowance label correctness

*For any* ticket type, the rendered ride allowance label should display "Unlimited rides" when `max_rides` is null, and the exact count (e.g., "5 rides included") when `max_rides` is a positive integer.

**Validates: Requirements 3.2, 3.4**

---

### Property 4: Ticket generation invariants

*For any* confirmed booking with quantity N, the system should insert exactly N rows into the `tickets` table, each with a unique `ticket_number`, each with `booking_id` equal to the parent booking's id, and each with initial `status = 'ACTIVE'`.

**Validates: Requirements 5.1, 5.2, 5.3**

---

### Property 5: Ticket scan state machine

*For any* ticket in state `ACTIVE`, scanning it should transition its status to `USED` and return success. *For any* ticket already in state `USED`, scanning it again should leave the status unchanged and return a "Ticket Already Used" error. *For any* ticket in state `CANCELLED` or `EXPIRED`, scanning should return a rejection message and leave the status unchanged.

**Validates: Requirements 6.1, 6.2, 6.3**

---

### Property 6: Scan response contains required customer fields

*For any* valid (ACTIVE) ticket scan, the response payload should contain the customer's name, the booking reference, the visit date, and the list of rides included in the ticket.

**Validates: Requirements 6.4**

---

### Property 7: Pending booking expiry transition

*For any* booking with `payment_status = 'Pending'` whose `created_at` is more than 3 minutes in the past, running the expiry process should set both `payment_status` and `status` to `'Cancelled'`.

**Validates: Requirements 7.2**

---

### Property 8: Expired booking payment rejection

*For any* booking that has been marked `Cancelled` due to expiry, attempting to confirm payment for that booking should be rejected and return a "Booking expired" message without changing the booking's status.

**Validates: Requirements 7.3**

---

### Property 9: Cancellation email content

*For any* booking cancelled by expiry, the cancellation email sent to the customer should have the subject "Ticket Cancellation Notice" and its body should contain the ticket number(s), customer full name, customer email, booking reference, visit date, ticket type name, quantity, and total amount. When `visit_date >= today`, the body should also contain a Continue_Booking_Link. When `visit_date < today`, the body should not contain a Continue_Booking_Link.

**Validates: Requirements 8.1, 8.2, 8.3, 8.4**

---

### Property 10: Email failure logging

*For any* cancellation email that fails to send, the system should append a log entry to `logs/email_failures.log` containing the booking reference and the customer email address.

**Validates: Requirements 8.6**

---

### Property 11: Reference popup content

*For any* confirmed (Paid) booking with N individual tickets, the confirmation page's popup HTML should contain the booking reference, customer name, customer email, customer phone, ticket type name, quantity, visit date, payment datetime, total amount, the list of included rides, and all N individual ticket numbers.

**Validates: Requirements 9.1, 9.2, 9.3**

---

### Property 12: Admin dashboard booking list completeness

*For any* set of bookings in the database, the admin bookings page should render a row for each booking containing the booking reference, customer name, ticket type, visit date, payment status, and total amount.

**Validates: Requirements 10.1**

---

### Property 13: Admin booking reference search round-trip

*For any* booking reference string, submitting it as a search query on the admin bookings page should return exactly the booking(s) whose `booking_reference` matches that string and no others.

**Validates: Requirements 10.2**

---

### Property 14: Normalized ticket_ride — no CSV in ticket_types

*For any* insert or update to the `ticket_types` table, no column in that table should contain a comma-separated list of ride IDs or ride names.

**Validates: Requirements 4.3**

---

### Property 15: Cascade delete from rides to ticket_ride

*For any* ride that is deleted from the `rides` table, all rows in `ticket_ride` referencing that ride's id should also be deleted.

**Validates: Requirements 4.4**

---

## Error Handling

| Scenario | Handling |
|---|---|
| DB connection failure | `render_db_error_page()` in `config.php` (existing) |
| Booking not found on scanner | 404-style message, no DB change |
| Scan of USED ticket | "Ticket Already Used" message, HTTP 200, no DB write |
| Scan of CANCELLED/EXPIRED ticket | Rejection message with status label |
| Payment attempt on expired booking | Flash error "Booking expired", redirect to `tickets.php` |
| Email send failure | Log to `logs/email_failures.log`, continue execution |
| Invalid ticket_number format | Scanner returns validation error before DB query |
| Unauthenticated access to scanner | `require_admin()` redirects to `login.php` |
| Unauthenticated access to checkout step 1+ | Redirect to `login.php?next=...` |
| Ride deleted while ticket_ride references it | FK CASCADE handles automatically |

---

## Testing Strategy

### Unit Tests

Use PHPUnit. Focus on:

- `lib/mailer.php` — `send_cancellation_email()` with a mock `mail()` wrapper: verify subject, body fields, link presence/absence based on visit date.
- `cron/expire_bookings.php` — `expire_pending_bookings(PDO)`: verify correct bookings are selected and updated.
- `admin/scanner.php` scan logic — extracted as a pure function `process_scan(string $ticket_number, PDO $pdo): array`: verify state machine transitions.
- Ticket number generation — verify uniqueness and format.
- `ticket_ride` cascade — verify FK cascade on ride delete.

### Property-Based Tests

Use [**eris**](https://github.com/giorgiosironi/eris) (PHP property-based testing library).

Each property test runs a minimum of **100 iterations**.

Each test is tagged with a comment in the format:
`// Feature: amusement-park-ticketing-system, Property N: <property_text>`

**Property 1** — Rides page field completeness  
Generate random ride records, insert them, render the rides page output, assert all fields present for each ride.  
`// Feature: amusement-park-ticketing-system, Property 1: rides page displays all required fields`

**Property 3** — Ride allowance label  
Generate random `max_rides` values (null or positive int), call the label helper, assert correct string.  
`// Feature: amusement-park-ticketing-system, Property 3: ride allowance label correctness`

**Property 4** — Ticket generation invariants  
Generate random quantities 1–20, create a booking, call the ticket generation function, assert count, uniqueness, booking_id, and ACTIVE status.  
`// Feature: amusement-park-ticketing-system, Property 4: ticket generation invariants`

**Property 5** — Ticket scan state machine  
Generate random ticket states, call `process_scan()`, assert correct status transitions and return values.  
`// Feature: amusement-park-ticketing-system, Property 5: ticket scan state machine`

**Property 7** — Pending booking expiry  
Generate random pending bookings with `created_at` between 3 and 60 minutes ago, run `expire_pending_bookings()`, assert all are Cancelled.  
`// Feature: amusement-park-ticketing-system, Property 7: pending booking expiry transition`

**Property 8** — Expired booking payment rejection  
Generate random cancelled bookings, call the payment confirmation handler, assert rejection and unchanged status.  
`// Feature: amusement-park-ticketing-system, Property 8: expired booking payment rejection`

**Property 9** — Cancellation email content  
Generate random booking data with varying visit dates (past and future), call `send_cancellation_email()` with a captured-output mailer, assert subject, body fields, and link presence.  
`// Feature: amusement-park-ticketing-system, Property 9: cancellation email content`

**Property 10** — Email failure logging  
Generate random booking data, force `mail()` to return false, call `send_cancellation_email()`, assert log file contains booking reference and email.  
`// Feature: amusement-park-ticketing-system, Property 10: email failure logging`

**Property 11** — Reference popup content  
Generate random confirmed bookings with 1–10 tickets, render the confirmation page, assert all required fields and all ticket numbers are present in the HTML.  
`// Feature: amusement-park-ticketing-system, Property 11: reference popup content`

**Property 13** — Admin search round-trip  
Generate random booking references, insert bookings, search for each reference, assert exactly one result matching that reference.  
`// Feature: amusement-park-ticketing-system, Property 13: admin booking reference search round-trip`

**Property 14** — No CSV in ticket_types  
Generate random ticket type inserts/updates via the admin handler, query all ticket_types columns, assert no column value matches a CSV pattern of integers.  
`// Feature: amusement-park-ticketing-system, Property 14: normalized ticket_ride — no CSV in ticket_types`

**Property 15** — Cascade delete  
Generate random ride and ticket_ride associations, delete a ride, assert no orphan rows remain in ticket_ride.  
`// Feature: amusement-park-ticketing-system, Property 15: cascade delete from rides to ticket_ride`

### Example / Integration Tests

- `rides.php` returns HTTP 200 without a session (Requirement 1.1).
- Ticket selection page shows exactly one ticket type named "One-Day Pass" (Requirement 2.1, 2.2).
- Admin ticket-type form does not contain "Child" or "Senior" category options (Requirement 2.3).
- `ticket_ride` table exists with correct columns and FK constraints (Requirement 4.2, 4.5).
- `admin/scanner.php` returns HTTP 302 to login for unauthenticated requests (Requirement 6.5).
- Expiry cron script is callable via CLI without fatal errors (Requirement 7.4).
- Booking confirmation page contains a visible `#ref-popup` element (Requirement 9.1).
- Admin bookings page renders booking reference inside a `<code>` element (Requirement 10.3).
