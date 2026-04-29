-- Migration v6: resume token for abandoned payment email links
ALTER TABLE bookings
  ADD COLUMN resume_token VARCHAR(64) NULL AFTER paymongo_qr_code_id,
  ADD COLUMN resume_expires DATETIME NULL AFTER resume_token;
