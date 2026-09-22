ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'draft';
ALTER TABLE users ADD COLUMN submitted_at INTEGER;
ALTER TABLE users ADD COLUMN approved_at INTEGER;
ALTER TABLE users ADD COLUMN revision INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN edit_field TEXT NOT NULL DEFAULT '';
-- Old accounts must complete the new registration; never silently approve them.
UPDATE users SET subscribed=0,completed=0,step='email' WHERE completed=1;
CREATE TABLE contacts (
    user_id INTEGER PRIMARY KEY REFERENCES users(id),
    phone TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL COLLATE NOCASE UNIQUE
);
CREATE TABLE staff_sessions (actor INTEGER PRIMARY KEY, payload TEXT NOT NULL);
CREATE TABLE drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL,
    text TEXT NOT NULL, subject TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft',
    version INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL,
    broadcast_id INTEGER REFERENCES broadcasts(id)
);
ALTER TABLE broadcasts ADD COLUMN subject TEXT NOT NULL DEFAULT '';
ALTER TABLE broadcasts ADD COLUMN approved_by INTEGER;
ALTER TABLE translations ADD COLUMN subject TEXT;
ALTER TABLE jobs ADD COLUMN channel TEXT NOT NULL DEFAULT 'telegram';
ALTER TABLE jobs ADD COLUMN report_included INTEGER NOT NULL DEFAULT 0;
UPDATE jobs SET report_included=initial;
DROP INDEX one_delivery;
CREATE UNIQUE INDEX one_delivery ON jobs(broadcast_id,user_id,channel) WHERE kind='delivery';
CREATE UNIQUE INDEX one_subject_translation ON jobs(broadcast_id,language) WHERE kind='translate_subject';
CREATE TABLE runtime_state (name TEXT PRIMARY KEY, value INTEGER NOT NULL);
