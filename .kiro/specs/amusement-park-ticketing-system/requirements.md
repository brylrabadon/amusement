# Requirements Document

## Introduction

This document defines the requirements for the AmusePark Ticketing System — a PHP-based web application that allows customers to browse rides and purchase tickets without requiring login until checkout. The system supports a single ticket type (One-Day Pass), normalized ride-to-ticket relationships, a staff QR scanner for marking tickets as used, automated booking expiry with email notifications, and a post-booking reference number popup with full booking details visible in the admin dashboard.

---

## Glossary

- **System**: The AmusePark web application (PHP + MySQL).
- **Customer**: A registered or guest user browsing rides and purchasing tickets.
- **Staff**: An authenticated admin user who scans QR codes at park entry.
- **One-Day Pass**: The single available ticket type, valid for one park visit day for any age group.
- **Booking**: A reservation record created when a Customer initiates checkout, containing one or more individual Tickets.
- **Ticket**: A unique, scannable entry record generated per quantity unit within a Booking.
- **Booking_Reference**: A unique alphanumeric identifier assigned to each Booking (e.g., `AP-XXXXXX-XXXXXX`).
- **Ticket_Number**: A unique identifier assigned to each individual Ticket within a Booking.
- **Ride**: An attraction available in the park, stored as a normalized database record.
- **Ride_Allowance**: The set of rides included with a given ticket package, defined via a mapping table.
- **Pending_Booking**: A Booking with `payment_status = 'Pending'` that has not yet been paid.
- **Scanner**: The staff-facing QR scan interface used to mark Tickets as `USED`.
- **Cancellation_Email**: An automated email sent to the Customer when a Pending_Booking expires.
- **Continue_Booking_Link**: A URL included in the Cancellation_Email that allows the Customer to restart the booking flow, valid only while the booking window is still open.
- **Reference_Popup**: A modal dialog shown immediately after a successful booking, displaying full booking and ride details.

---

## Requirements

### Requirement 1: Rides Landing Page (Browse Without Login)

**User Story:** As a Customer, I want to browse available rides without logging in, so that I can explore the park's attractions before deciding to purchase.

#### Acceptance Criteria

1. THE System SHALL display the rides landing page (`rides.php`) as the default entry point for unauthenticated visitors.
2. WHEN a Customer visits the rides landing page, THE System SHALL display all rides with their name, description, category, status, duration, minimum height, and image.
3. WHEN a Customer clicks "Add to Cart" or "Buy Tickets" on a ride, THE System SHALL redirect the Customer to the checkout flow without requiring login at that point.
4. THE System SHALL defer the login/registration requirement until the Customer reaches the payment/checkout step.

---

### Requirement 2: One-Day Pass — Single Ticket Type

**User Story:** As a Customer, I want a single, straightforward ticket option, so that I can purchase entry without confusion over multiple pass types.

#### Acceptance Criteria

1. THE System SHALL offer exactly one ticket type named "One-Day Pass" for purchase by all Customers regardless of age.
2. THE System SHALL NOT display or offer a "Child Pass", "Senior Pass", or "Single Pass" ticket type.
3. WHEN the admin creates or edits ticket types, THE System SHALL enforce that the category options do not include "Child" or "Senior" as selectable values.
4. THE System SHALL display the One-Day Pass with its price, description, and associated Ride_Allowance on the ticket selection page.

---

### Requirement 3: Ride Selection Per Ticket Package

**User Story:** As a Customer, I want to see which rides are included with my ticket, so that I can make an informed purchase decision.

#### Acceptance Criteria

1. WHEN a Customer views a ticket type, THE System SHALL display a checkbox list of rides included in that ticket's package.
2. THE System SHALL display a "Ride Allowance" section showing the total number of rides included with the selected ticket.
3. WHEN a ticket type has no ride limit, THE System SHALL display "Unlimited rides" in the Ride_Allowance section.
4. WHEN a ticket type has a specific ride count, THE System SHALL display the exact number (e.g., "5 rides included") in the Ride_Allowance section.

---

### Requirement 4: Normalized Ride-to-Ticket Database Structure

**User Story:** As a developer, I want rides stored in a normalized relational structure, so that ride data is consistent and free of duplication.

#### Acceptance Criteria

1. THE System SHALL store all rides in a dedicated `rides` table with a unique primary key per ride.
2. THE System SHALL store ticket-to-ride relationships in a separate `ticket_ride` mapping table (columns: `ticket_type_id`, `ride_id`), with no ride data duplicated in the `ticket_types` table.
3. THE System SHALL NOT store ride names or ride IDs as comma-separated values in any column of the `ticket_types` table.
4. WHEN a ride is removed from the `rides` table, THE System SHALL cascade-delete or nullify the corresponding rows in the `ticket_ride` mapping table.
5. THE System SHALL use foreign key constraints between `ticket_ride.ticket_type_id → ticket_types.id` and `ticket_ride.ride_id → rides.id`.

---

### Requirement 5: Per-Entry Ticket Generation

**User Story:** As a Customer, I want a unique ticket generated for each entry I purchase, so that each person in my group has their own scannable QR code.

#### Acceptance Criteria

1. WHEN a Booking is confirmed with a quantity of N, THE System SHALL generate exactly N individual Ticket records, each with a unique Ticket_Number.
2. THE System SHALL associate each Ticket record with its parent Booking via a `booking_id` foreign key.
3. THE System SHALL set the initial status of each generated Ticket to `ACTIVE`.
4. THE System SHALL display all generated Ticket QR codes to the Customer on the booking confirmation screen.

---

### Requirement 6: Staff QR Scanner — Mark Ticket as Used

**User Story:** As a Staff member, I want to scan a customer's QR code at the park entrance, so that I can validate and mark the ticket as used.

#### Acceptance Criteria

1. WHEN Staff scan a valid Ticket QR code, THE System SHALL update that Ticket's status to `USED`.
2. WHEN Staff scan a Ticket with status `USED`, THE System SHALL display a "Ticket Already Used" error message and SHALL NOT update the Ticket status again.
3. WHEN Staff scan a Ticket with status `CANCELLED` or `EXPIRED`, THE System SHALL display an appropriate rejection message.
4. WHEN Staff scan a valid Ticket, THE System SHALL display the Customer's name, Booking_Reference, visit date, and included rides.
5. THE Scanner SHALL only be accessible to authenticated Staff (admin role) users.

---

### Requirement 7: Pending Booking Expiry (3-Minute Limit)

**User Story:** As a system operator, I want pending bookings to expire automatically after 3 minutes, so that unpaid reservations do not block inventory indefinitely.

#### Acceptance Criteria

1. WHEN a Booking is created with `payment_status = 'Pending'`, THE System SHALL record the creation timestamp.
2. WHEN 3 minutes have elapsed since a Pending_Booking was created and payment has not been completed, THE System SHALL automatically update the Booking's `payment_status` to `'Cancelled'` and `status` to `'Cancelled'`.
3. WHEN a Customer attempts to complete payment on an expired Pending_Booking, THE System SHALL reject the payment and display a "Booking expired" message.
4. THE System SHALL check for expired Pending_Bookings on each page load of the checkout flow and via a scheduled background process (e.g., a PHP script callable by a cron job).

---

### Requirement 8: Cancellation Email Notification

**User Story:** As a Customer, I want to receive an email when my booking is cancelled, so that I am informed and can rebook if needed.

#### Acceptance Criteria

1. WHEN a Booking's status is changed to `'Cancelled'` due to expiry, THE System SHALL send a Cancellation_Email to the Customer's registered email address.
2. THE Cancellation_Email SHALL have the subject line "Ticket Cancellation Notice".
3. THE Cancellation_Email SHALL include: the Ticket_Number(s), Customer full name, Customer email, Booking_Reference, visit date, ticket type name, quantity, and total amount.
4. WHEN the booking window for the same visit date is still open, THE Cancellation_Email SHALL include a Continue_Booking_Link that pre-fills the ticket selection for the Customer.
5. WHEN the booking window for the visit date has passed, THE Cancellation_Email SHALL include a confirmation message stating the booking has been permanently cancelled with no Continue_Booking_Link.
6. IF the email delivery fails, THEN THE System SHALL log the failure with the Booking_Reference and Customer email for manual follow-up.

---

### Requirement 9: Post-Booking Reference Number Popup

**User Story:** As a Customer, I want to see a popup with my booking reference and full details immediately after completing payment, so that I have a clear record of my purchase.

#### Acceptance Criteria

1. WHEN a Booking is confirmed (payment_status set to `'Paid'`), THE System SHALL display a Reference_Popup modal on the confirmation screen.
2. THE Reference_Popup SHALL include: Booking_Reference, Customer full name, Customer email, Customer phone, ticket type name, quantity, visit date, payment date and time, total amount paid, and the list of rides included in the ticket.
3. THE Reference_Popup SHALL display each individual Ticket_Number generated for the Booking.
4. THE Reference_Popup SHALL remain visible until the Customer explicitly dismisses it.
5. WHEN the Customer dismisses the Reference_Popup, THE System SHALL keep the booking summary visible on the confirmation page.

---

### Requirement 10: Booking Reference Visibility in Admin Dashboard

**User Story:** As an admin, I want to see booking reference numbers prominently in the dashboard, so that I can quickly look up and manage customer bookings.

#### Acceptance Criteria

1. THE Admin_Dashboard SHALL display a searchable list of all Bookings including the Booking_Reference, Customer name, ticket type, visit date, payment status, and total amount.
2. WHEN an admin searches by Booking_Reference, THE System SHALL filter and display the matching Booking record.
3. THE Admin_Dashboard SHALL display the Booking_Reference in a visually distinct style (e.g., monospace font, highlighted color) to differentiate it from other fields.
4. WHEN an admin views a Booking detail, THE System SHALL display all individual Ticket_Numbers associated with that Booking.
