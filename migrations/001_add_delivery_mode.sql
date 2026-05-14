-- Run on existing installs (already in schema.sql for fresh installs)
ALTER TABLE tree_nodes
    ADD COLUMN delivery_mode VARCHAR(20) NOT NULL DEFAULT 'redirect' AFTER redirect_url;
