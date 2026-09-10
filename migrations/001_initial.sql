CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    language TEXT NOT NULL DEFAULT 'RU',
    step TEXT NOT NULL DEFAULT 'language',
    name TEXT NOT NULL DEFAULT '',
    company TEXT NOT NULL DEFAULT '',
    country TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    subscribed INTEGER NOT NULL DEFAULT 0 CHECK (subscribed IN (0, 1)),
    completed INTEGER NOT NULL DEFAULT 0 CHECK (completed IN (0, 1)),
    choosing_language INTEGER NOT NULL DEFAULT 1 CHECK (choosing_language IN (0, 1))
);
CREATE TABLE processed_updates (id INTEGER PRIMARY KEY);
CREATE TABLE broadcasts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'preparing',
    reported INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE TABLE translations (
    broadcast_id INTEGER NOT NULL REFERENCES broadcasts(id),
    language TEXT NOT NULL,
    text TEXT NOT NULL,
    PRIMARY KEY (broadcast_id, language)
);
CREATE TABLE jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    broadcast_id INTEGER REFERENCES broadcasts(id),
    user_id INTEGER,
    language TEXT,
    payload TEXT NOT NULL DEFAULT '{}',
    initial INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_at INTEGER NOT NULL DEFAULT 0,
    part INTEGER NOT NULL DEFAULT 0,
    error TEXT
);
CREATE UNIQUE INDEX one_translation ON jobs(broadcast_id, language) WHERE kind = 'translate';
CREATE UNIQUE INDEX one_delivery ON jobs(broadcast_id, user_id) WHERE kind = 'delivery';
CREATE INDEX due_jobs ON jobs(status, next_at, id);
CREATE INDEX broadcast_jobs ON jobs(broadcast_id, kind, initial, status);
