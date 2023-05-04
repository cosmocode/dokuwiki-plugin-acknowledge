
CREATE TABLE assignments_temp
(
    page     TEXT NOT NULL REFERENCES pages (page) ON DELETE CASCADE PRIMARY KEY,
    pageassignees TEXT NOT NULL DEFAULT '',
    autoassignees TEXT NOT NULL DEFAULT ''
);

INSERT INTO assignments_temp (page, pageassignees) SELECT page, assignee FROM assignments;

DROP TABLE assignments;
ALTER TABLE assignments_temp RENAME TO assignments;

CREATE TABLE assignments_patterns (
    pattern TEXT NOT NULL PRIMARY KEY ,
    assignees TEXT NOT NULL
);
