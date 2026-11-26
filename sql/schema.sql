-- Idempotent schema for txtgrep. Apply with: php bin/setup.php
-- Numeric collation makes 第2章 sort before 第10章.
CREATE COLLATION IF NOT EXISTS "txtgrep_natural" (provider = icu, locale = 'und-u-kn');

CREATE TABLE IF NOT EXISTS files (
    path       text PRIMARY KEY,          -- e.g. txt/三國演義/第001回 宴桃園豪傑三結義.txt
    size       bigint NOT NULL,
    mtime      bigint NOT NULL,
    inode      text NOT NULL DEFAULT '',
    hash       text,
    seen_run   text,                      -- indexer run token; stale rows are deleted
    indexed_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS files_inode_idx ON files (inode);
CREATE INDEX IF NOT EXISTS files_seen_run_idx ON files (seen_run);

-- Stable opaque key for reader links; additive and safe for existing databases.
ALTER TABLE files ADD COLUMN IF NOT EXISTS id bigint;
CREATE SEQUENCE IF NOT EXISTS files_id_seq;
ALTER TABLE files ALTER COLUMN id SET DEFAULT nextval('files_id_seq');
UPDATE files SET id = nextval('files_id_seq') WHERE id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS files_id_idx ON files (id);
ALTER TABLE files ALTER COLUMN id SET NOT NULL;

CREATE TABLE IF NOT EXISTS file_lines (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    file_path   text NOT NULL REFERENCES files(path) ON DELETE CASCADE,
    line_number integer NOT NULL,
    content     text NOT NULL,
    UNIQUE (file_path, line_number)
);

CREATE INDEX IF NOT EXISTS file_lines_natural_path_line_idx
    ON file_lines (file_path COLLATE "txtgrep_natural", line_number);

-- pg_trgm is intentionally not enabled by default. Measure with
-- EXPLAIN (ANALYZE, BUFFERS) before adding a GIN index.
