CREATE TABLE pages
(
    page    TEXT NOT NULL PRIMARY KEY,
    lastmod INT  NOT NULL
);

CREATE TABLE acks
(
    page TEXT NOT NULL REFERENCES pages (page) ON DELETE CASCADE,
    user TEXT NOT NULL,
    ack  INT  NOT NULL,
    PRIMARY KEY (page, user)
);

CREATE TABLE assignments
(
    page     TEXT NOT NULL REFERENCES pages (page) ON DELETE CASCADE PRIMARY KEY,
    assignee TEXT NOT NULL
);
