# Implementation Plan: Amusement Park Ticketing System

## Overview

Incremental implementation of all ten requirements into the existing PHP + MySQL AmusePark codebase. Each task builds on the previous, ending with everything wired together. All code is plain PHP with PDO — no new frameworks.

## Tasks

- [x] 1. Database schema migration
  - Create `cron/` and `logs/` directories (empty `.gitkeep` files)
  - Run migration SQL: create `ticket_ride` table with FK constraints to `ticket_types` and `rides`
  - Run migration SQL: create `tickets` table with FK to `bookings`
  - Run migration SQL: `ALTER TABLE ticket_types DROP COLUMN IF EXISTS ride_ids`
  - Run migration SQL: update `ticket_types.category` ENUM to remove `Child`/`Senior`, migrate existing rows
  - Seed or ensure exactly one active "One-Day Pass" ticket type exists; deactivate/delete others
  - Add migration file `server/migrate_v2.sql` containing all the above statements
  - _Requirements: 2.1, 2.2, 4.1, 4.2, 4.3, 4.4, 4.5_

- [x] 2. Public rides landing page
  - [x] 2.1 Remove login guard from `rides.php`
    - Replace `require_login('customer')` with `current_user()` (optional auth)
    - Update nav to show Login/Register links when unauthenticated, Logout when authenticated
    - Ensure "Buy Tickets" button links to `tickets.php` without requiring login at that point
    - _Requirements: 1.1, 1.2, 1.3, 1.4_

  - [ ]* 2.2 Write property test for rides page field completeness
    - **Property 1: Rides page displays all required fields**
    - **Validates: Requirements 1.2**

- [x] 3. Admin ticket-types: remove Child/Senior, add ticket_ride management
  - [x] 3.1 Update `admin/ticket-types.php` category list and ride assignment UI
    - Remove `'Child'` and `'Senior'` from `$categories` array
    - Remove all `ride_ids` CSV column logic (`$hasRideIdsColumn`, `$rideIds`, related SQL)
    - Replace ride assignment with a multi-select checklist that reads/writes `ticket_ride` rows
    - On `create`: after INSERT into `ticket_types`, DELETE + INSERT rows in `ticket_ride` for checked ride IDs
    - On `update`: DELETE existing `ticket_ride` rows for this `ticket_type_id`, INSERT new checked ride IDs
    - On `delete`: FK CASCADE handles `ticket_ride` cleanup automatically
    - Update `ticket_ride_label()` helper to query `ticket_ride` count instead of reading `ride_ids`
    - _Requirements: 2.3, 3.1, 3.2, 3.3, 3.4, 4.2, 4.3, 4.5_

  - [ ]* 3.2 Write property test for no CSV in ticket_types
    - **Property 14: Normalized ticket_ride — no CSV in ticket_types**
    - **Validates: Requirements 4.3**

  - [ ]* 3.3 Write property test for cascade delete from rides to ticket_ride
    - **Property 15: Cascade delete from rides to ticket_ride**
    - **Validates: Requirements 4.4**

- [ ] 4. Checkpoint — Ensure schema migration runs cleanly and admin ticket-types UI works
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Booking flow updates (`tickets.php`)
  - [x] 5.1 Defer login to step 1 (details), allow unauthenticated step 0
    - Remove top-level `require_login('customer')` call
    - At step 0 (`action=select`): allow unauthenticated users through; store selection in session
    - At step 1 (`action=details` POST and GET display): call `require_login('customer')` or redirect to `login.php?next=tickets.php%3Fstep%3D1`
    - Update nav to show Login/Register when unauthenticated
    - _Requirements: 1.3, 1.4_

  - [x] 5.2 Show One-Day Pass with ride list from `ticket_ride`
    - On step 0, for each ticket type, query `ticket_ride` JOIN `rides` to get included ride names
    - Display ride list as a checklist below the ticket description
    - Display ride allowance label: "Unlimited rides" when `max_rides` IS NULL, else "N rides included"
    - _Requirements: 2.4, 3.1, 3.2, 3.3, 3.4_

  - [ ]* 5.3 Write property test for ride allowance label correctness
    - **Property 3: Ride allowance label correctness**
    - **Validates: Requirements 3.2, 3.4**

  - [x] 5.4 Per-entry ticket generation on `action=details`
    - After inserting the `bookings` row, loop `$qty` times and INSERT into `tickets` table
    - `ticket_number` format: `TK-<booking_reference>-<zero-padded seq, e.g. 001>`
    - Each row: `booking_id`, `ticket_number`, `status = 'ACTIVE'`
    - _Requirements: 5.1, 5.2, 5.3_

  - [ ]* 5.5 Write property test for ticket generation invariants
    - **Property 4: Ticket generation invariants**
    - **Validates: Requirements 5.1, 5.2, 5.3**

  - [x] 5.6 Expiry check on payment page load
    - Include `cron/expire_bookings.php` and call `expire_pending_bookings($pdo)` at the top of step 2 render
    - On `action=confirm_payment`: re-fetch booking; if `payment_status = 'Cancelled'`, flash "Booking expired" and redirect to `tickets.php`
    - _Requirements: 7.3, 7.4_

  - [ ]* 5.7 Write property test for expired booking payment rejection
    - **Property 8: Expired booking payment rejection**
    - **Validates: Requirements 7.3**

  - [x] 5.8 Reference popup on step 3 (confirmation)
    - After confirming payment, query all `tickets` rows for this booking
    - Query `ticket_ride` JOIN `rides` for the ticket type's included rides
    - Render `<div id="ref-popup">` modal (visible by default) containing: booking reference, customer name/email/phone, ticket type name, quantity, visit date, payment datetime (`updated_at`), total amount, ride list, all individual ticket numbers
    - Add "Got it" button that sets `document.getElementById('ref-popup').style.display='none'` via inline JS
    - Keep existing booking summary below the popup
    - Display each individual ticket's QR code (encode `ticket_number` in QR URL)
    - _Requirements: 5.4, 9.1, 9.2, 9.3, 9.4, 9.5_

  - [ ]* 5.9 Write property test for reference popup content
    - **Property 11: Reference popup content**
    - **Validates: Requirements 9.1, 9.2, 9.3**

- [ ] 6. Checkpoint — Ensure booking flow works end-to-end with ticket generation and popup
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Booking expiry cron script (`cron/expire_bookings.php`)
  - [x] 7.1 Create `cron/expire_bookings.php` with `expire_pending_bookings(PDO $pdo): int`
    - SELECT all bookings where `payment_status = 'Pending'` AND `created_at < NOW() - INTERVAL 3 MINUTE`
    - For each: UPDATE `payment_status = 'Cancelled'`, `status = 'Cancelled'`
    - For each: fetch associated `tickets` rows, call `send_cancellation_email($booking, $tickets, $pdo)`
    - Also UPDATE `tickets.status = 'CANCELLED'` for all tickets of the expired booking
    - Return count of expired bookings
    - When run as CLI (`php cron/expire_bookings.php`): call `expire_pending_bookings(db())` and echo result
    - _Requirements: 7.1, 7.2, 7.4_

  - [ ]* 7.2 Write property test for pending booking expiry transition
    - **Property 7: Pending booking expiry transition**
    - **Validates: Requirements 7.2**

- [x] 8. Cancellation email (`lib/mailer.php`)
  - [x] 8.1 Create `lib/mailer.php` with `send_cancellation_email(array $booking, array $tickets, PDO $pdo): bool`
    - Build subject: `"Ticket Cancellation Notice"`
    - Build body: ticket number(s), customer full name, customer email, booking reference, visit date, ticket type name, quantity, total amount
    - If `visit_date >= date('Y-m-d')`: append Continue_Booking_Link (`tickets.php?prefill=<booking_reference>`)
    - If `visit_date < date('Y-m-d')`: append permanent cancellation notice, no link
    - Call `mail($to, $subject, $body, $headers)`; on failure log to `logs/email_failures.log` with booking reference and customer email
    - Ensure `logs/` directory exists (create with `mkdir` if absent)
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6_

  - [ ]* 8.2 Write property test for cancellation email content
    - **Property 9: Cancellation email content**
    - **Validates: Requirements 8.1, 8.2, 8.3, 8.4**

  - [ ]* 8.3 Write property test for email failure logging
    - **Property 10: Email failure logging**
    - **Validates: Requirements 8.6**

- [x] 9. Staff QR scanner (`admin/scanner.php`)
  - [x] 9.1 Create `admin/scanner.php` with `process_scan(string $ticket_number, PDO $pdo): array`
    - `require_admin()` at top
    - `process_scan()`: validate `ticket_number` format; SELECT ticket JOIN booking JOIN user; apply state machine:
      - `ACTIVE` → UPDATE `status = 'USED'`, `scanned_at = NOW()`; return success with customer name, booking reference, visit date, ride list
      - `USED` → return error "Ticket Already Used", no DB write
      - `CANCELLED` / `EXPIRED` → return rejection with status label, no DB write
      - Not found → return "Ticket not found" error
    - Render HTML page with: manual text input form, GET-param scan handler, result display (success/error card)
    - Add "Scanner" link to admin nav in `admin/scanner.php` (nav is self-contained per page)
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

  - [ ]* 9.2 Write property test for ticket scan state machine
    - **Property 5: Ticket scan state machine**
    - **Validates: Requirements 6.1, 6.2, 6.3**

  - [ ]* 9.3 Write property test for scan response fields
    - **Property 6: Scan response contains required customer fields**
    - **Validates: Requirements 6.4**

- [x] 10. Admin bookings dashboard updates (`admin/bookings.php`)
  - [x] 10.1 Add ticket drill-down and ensure monospace reference styling
    - Wrap booking reference `<td>` content in `<code>` tag with blue monospace styling (already partially done; make consistent)
    - After each booking `<tr>`, add a collapsible `<tr class="ticket-detail-row">` containing all `tickets.ticket_number` values for that booking (fetched via JOIN or separate query)
    - Add "View Tickets" toggle button per row that shows/hides the detail row via inline JS
    - Ensure search by booking reference filters correctly (already implemented; verify it works with `<code>` wrapping)
    - Add "Scanner" quick-action link to the admin dashboard (`admin/admin-dashboard.php`) Quick Actions section
    - _Requirements: 10.1, 10.2, 10.3, 10.4_

  - [ ]* 10.2 Write property test for admin booking list completeness
    - **Property 12: Admin dashboard booking list completeness**
    - **Validates: Requirements 10.1**

  - [ ]* 10.3 Write property test for admin booking reference search round-trip
    - **Property 13: Admin booking reference search round-trip**
    - **Validates: Requirements 10.2**

- [ ] 11. Final checkpoint — Ensure all tests pass and all features are wired together
  - Ensure all tests pass, ask the user if questions arise.
  - Verify `rides.php` loads without login (Requirement 1.1)
  - Verify `tickets.php` step 0 loads without login, step 1 redirects to login if unauthenticated
  - Verify `admin/scanner.php` redirects to login for unauthenticated requests (Requirement 6.5)
  - Verify `php cron/expire_bookings.php` runs without fatal errors (Requirement 7.4)

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Property tests use [eris](https://github.com/giorgiosironi/eris) (PHP property-based testing library), minimum 100 iterations each
- Each property test file should include the comment: `// Feature: amusement-park-ticketing-system, Property N: <property_text>`
- All migration SQL belongs in `server/migrate_v2.sql`; run it manually against the `amusepark` database
- The `logs/` directory must be writable by the web server process for email failure logging
