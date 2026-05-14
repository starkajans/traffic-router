-- Run on existing installs (already in schema.sql for fresh installs)
ALTER TABLE hits
    ADD COLUMN bot_category VARCHAR(20) NULL AFTER bot_name,
    ADD INDEX idx_bot_category (bot_category);
