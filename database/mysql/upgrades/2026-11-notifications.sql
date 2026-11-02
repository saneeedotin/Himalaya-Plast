-- Upgrade for databases loaded before per-person notifications and Web Push (run once, as the account that loaded 01-09):
--   1. run this file
--   2. re-run 06_accounts_alerts.sql, 07_read_procedures.sql and 08_triggers.sql (procedures, the fn_notif_visible
--      function and the event triggers)
-- Notifications are now read per person, can be limited to people whose role fits, and can be pushed to browsers.
USE hpos;

ALTER TABLE notifications
  ADD COLUMN audience   VARCHAR(160) NULL AFTER action_url,
  ADD COLUMN push_state TINYINT      NOT NULL DEFAULT 0 AFTER audience,
  ADD KEY ix_notif_push (push_state, created_at);
UPDATE notifications SET push_state = 1;   -- nothing that already exists should be pushed now

CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id CHAR(36)    NOT NULL,
  user_id         CHAR(36)    NOT NULL,
  read_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (notification_id, user_id),
  KEY ix_nr_user (user_id),
  CONSTRAINT fk_nr_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_nr_user  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id            CHAR(36)     NOT NULL DEFAULT (UUID()),
  user_id       CHAR(36)     NOT NULL,
  endpoint      VARCHAR(600) NOT NULL,
  endpoint_hash CHAR(64)     NOT NULL,
  p256dh        VARCHAR(255) NOT NULL,
  auth          VARCHAR(255) NOT NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_ok_at    DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY ix_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS job_runs (
  job_name VARCHAR(60) NOT NULL,
  last_run DATETIME(3) NOT NULL,
  PRIMARY KEY (job_name)
) ENGINE=InnoDB;

-- an old global "read" becomes read for everyone who exists now
INSERT IGNORE INTO notification_reads (notification_id, user_id)
SELECT n.id, u.id FROM notifications n JOIN users u WHERE n.status = 'READ';
UPDATE notifications SET status = 'UNREAD' WHERE status = 'READ';
