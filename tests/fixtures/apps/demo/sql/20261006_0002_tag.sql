CREATE TABLE IF NOT EXISTS tbl_demo_tag (
  id VARCHAR(26) NOT NULL,
  note_id VARCHAR(26) NOT NULL,
  label VARCHAR(40) NOT NULL,
  PRIMARY KEY (id),
  KEY demo_tag_note (note_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
