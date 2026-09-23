-- 2026-09-23: profile pictures (ajax/upload_avatar.php)
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) NULL DEFAULT NULL AFTER position;
