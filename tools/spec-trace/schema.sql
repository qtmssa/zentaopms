CREATE TABLE IF NOT EXISTS zt_spectrace_freeze (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product INT UNSIGNED NOT NULL,
  epic VARBINARY(128) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  basis CHAR(64) CHARACTER SET ascii NOT NULL,
  author VARBINARY(128) NOT NULL,
  graph LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id), UNIQUE KEY revision_identity (product, epic, revision),
  KEY by_basis (product, epic, basis)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_object (
  product INT UNSIGNED NOT NULL,
  epic VARBINARY(128) NOT NULL,
  kind ENUM('source','item') NOT NULL,
  object_id VARBINARY(255) NOT NULL,
  version VARBINARY(128) NOT NULL,
  digest CHAR(64) CHARACTER SET ascii NOT NULL,
  PRIMARY KEY (product, epic, kind, object_id, version)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_member (
  freeze_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('source','item','feature') NOT NULL,
  object_id VARBINARY(255) NOT NULL,
  version VARBINARY(128) NOT NULL,
  PRIMARY KEY (freeze_id,kind,object_id),
  FOREIGN KEY (freeze_id) REFERENCES zt_spectrace_freeze(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_locator (
  freeze_id BIGINT UNSIGNED NOT NULL,
  item_id VARBINARY(255) NOT NULL,
  source_id VARBINARY(255) NOT NULL,
  source_version VARBINARY(128) NOT NULL,
  locator_digest CHAR(64) CHARACTER SET ascii NOT NULL,
  locator LONGTEXT CHARACTER SET utf8mb4 NOT NULL,
  matches_source TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (freeze_id,item_id,locator_digest), KEY by_source (freeze_id,source_id),
  FOREIGN KEY (freeze_id) REFERENCES zt_spectrace_freeze(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_consumer (
  freeze_id BIGINT UNSIGNED NOT NULL,
  item_id VARBINARY(255) NOT NULL,
  feature_id VARBINARY(255) NOT NULL,
  obligation_id VARBINARY(255) NOT NULL,
  matches_feature TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (freeze_id,item_id,feature_id,obligation_id), KEY by_feature (freeze_id,feature_id),
  FOREIGN KEY (freeze_id) REFERENCES zt_spectrace_freeze(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_decision (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  freeze_id BIGINT UNSIGNED NOT NULL,
  stage ENUM('epic','spec') NOT NULL,
  scope VARBINARY(255) NOT NULL,
  decision ENUM('accepted','changes_requested') NOT NULL,
  target_revision VARBINARY(128) NOT NULL,
  reviewer VARBINARY(128) NOT NULL,
  evidence LONGTEXT CHARACTER SET utf8mb4 NOT NULL,
  mechanical_report LONGTEXT CHARACTER SET utf8mb4 NULL,
  prior_decision BIGINT UNSIGNED NULL,
  epic_decision BIGINT UNSIGNED NULL,
  request_digest CHAR(64) CHARACTER SET ascii NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id), UNIQUE KEY retry (freeze_id,request_digest),
  KEY history (freeze_id,stage,scope,id),
  FOREIGN KEY (freeze_id) REFERENCES zt_spectrace_freeze(id),
  FOREIGN KEY (prior_decision) REFERENCES zt_spectrace_decision(id),
  FOREIGN KEY (epic_decision) REFERENCES zt_spectrace_decision(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_stream (
  product INT UNSIGNED NOT NULL,
  epic VARBINARY(128) NOT NULL,
  PRIMARY KEY (product,epic)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_candidate (
  freeze_id BIGINT UNSIGNED NOT NULL,
  scope VARBINARY(255) NOT NULL,
  target_revision CHAR(64) CHARACTER SET ascii NOT NULL,
  epic_decision BIGINT UNSIGNED NOT NULL,
  author VARBINARY(128) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (freeze_id,scope,target_revision,epic_decision),
  FOREIGN KEY (freeze_id) REFERENCES zt_spectrace_freeze(id),
  FOREIGN KEY (epic_decision) REFERENCES zt_spectrace_decision(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS zt_spectrace_projection (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  freeze_id BIGINT UNSIGNED NOT NULL,
  projection_revision INT UNSIGNED NOT NULL,
  digest CHAR(64) CHARACTER SET ascii NOT NULL,
  payload LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  publisher VARBINARY(128) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id), UNIQUE KEY by_freeze_revision (freeze_id,projection_revision),
  FOREIGN KEY (freeze_id) REFERENCES zt_spectrace_freeze(id)
) ENGINE=InnoDB;
