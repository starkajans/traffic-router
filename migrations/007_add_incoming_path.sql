-- Optional custom incoming path per campaign. NULL = only reachable at /go/{slug}.
-- If set (e.g. "/", "/promo", "/lp"), the router will also fire this campaign
-- when a visitor hits that path directly.
ALTER TABLE campaigns
    ADD COLUMN incoming_path VARCHAR(255) NULL AFTER slug,
    ADD UNIQUE INDEX idx_incoming_path (incoming_path);
