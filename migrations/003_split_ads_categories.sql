-- Reassign existing hits to the new platform-specific ad categories.
-- Safe to re-run.

UPDATE hits SET bot_category = 'ads_google'
    WHERE bot_category = 'ads';

UPDATE hits SET bot_category = 'ads_meta'
    WHERE bot_name IN ('facebookexternalhit', 'FacebookBot', 'Facebot');

UPDATE hits SET bot_category = 'ads_tiktok'
    WHERE bot_name IN ('TikTokSpider', 'TikTokBot');
