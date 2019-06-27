PRAGMA foreign_keys=OFF;

CREATE TEMPORARY TABLE acks_temp
(
    page TEXT NOT NULL REFERENCES pages (page) ON DELETE CASCADE,
    user TEXT NOT NULL,
    ack  INT  NOT NULL,
    PRIMARY KEY (page, user)
);

INSERT INTO acks_temp (page,user,ack) SELECT page,user,ack FROM acks;

DROP TABLE acks;

CREATE TABLE acks
(
    page TEXT NOT NULL REFERENCES pages (page),
    user TEXT NOT NULL,
    ack  INT  NOT NULL,
    PRIMARY KEY (page, user, ack)
);

INSERT INTO acks (page,user,ack) SELECT page,user,ack FROM acks_temp;

DROP TABLE acks_temp;

PRAGMA foreign_keys=ON;
