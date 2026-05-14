-- Run on existing installs (already in schema.sql for fresh installs)
ALTER TABLE hits
    ADD COLUMN ad_platform VARCHAR(20) NULL AFTER bot_category,
    ADD INDEX idx_ad_platform (ad_platform);
