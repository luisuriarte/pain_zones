-- ============================================================================
-- Form: pain_zones ("Pain Zones")
-- Path: interface/forms/pain_zones/table.sql
--
-- Pain zone map over SVG figures (adult male/female, anterior/posterior view).
-- Each mark stores its relative position, anatomical zone, 0-10 intensity and clinical data.
--
-- Contents:
--   1. form_pain_zones          Form header (one row per saved form)
--   2. form_pain_zones_marks    Pain marks (one row per mark, child of the header)
--   3. form_pain_zones_history  Audit trail of mark inserts, updates and deletes
--   4. list_options             List pain_zones_level: title and levels 0-10 with colors
--   5. list_options             List pain_zones_quality: title and clinical pain qualities
--
-- Zone id convention: region_subzone_side (example: thigh_anterior_left)
-- Zone ids are in English and match the ids in the SVG files.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. form_pain_zones
-- Form header. Follows the standard pattern of native forms
-- (id, date, pid, user, groupname, authorized, activity) and adds the source,
-- the review status and the text summary generated from the marks.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `form_pain_zones` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT COMMENT 'Form identifier (form_id in the forms table)',
  `date` DATETIME DEFAULT NULL COMMENT 'Date and time the form was created',
  `pid` BIGINT(20) NOT NULL DEFAULT 0 COMMENT 'Patient id (patient_data.pid)',
  `user` VARCHAR(255) DEFAULT NULL COMMENT 'User who saved the form',
  `groupname` VARCHAR(255) DEFAULT NULL COMMENT 'User group (native form convention)',
  `authorized` TINYINT(4) DEFAULT NULL COMMENT 'Authorized: 1 yes, 0 no (native form convention)',
  `activity` TINYINT(4) NOT NULL DEFAULT 1 COMMENT 'Active: 1 current, 0 deleted',
  `source` VARCHAR(16) NOT NULL DEFAULT 'clinic' COMMENT 'Entry source: clinic (clinician) or portal (patient from the express portal)',
  `review_status` VARCHAR(16) NOT NULL DEFAULT 'confirmed' COMMENT 'Review status: pending_review (entered by the patient, not yet reviewed) or confirmed (reviewed by the clinician)',
  `general_notes` TEXT COMMENT 'General form notes, not tied to a specific mark',
  `summary_text` MEDIUMTEXT COMMENT 'Text summary generated from the marks (feeds the context of the SOAP AI module)',
  PRIMARY KEY (`id`),
  KEY `pid_date` (`pid`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Pain zones: form header, one row per saved form';


-- ----------------------------------------------------------------------------
-- 2. form_pain_zones_marks
-- One row per pain mark. Stores the exact position (x, y as a percentage of the
-- figure viewBox), the anatomical zone clicked and the clinical data.
-- The mark color is not stored: it is derived from intensity against the
-- list_options list pain_zones_level (option_id = level_N, colors in notes).
-- Deleting marks when a form is deleted is handled by code (no foreign keys).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `form_pain_zones_marks` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT COMMENT 'Mark identifier',
  `form_id` BIGINT(20) NOT NULL COMMENT 'Id of the form the mark belongs to (form_pain_zones.id)',
  `pid` BIGINT(20) NOT NULL COMMENT 'Patient id (repeated to support per-patient progression and heat map queries)',
  `figure` VARCHAR(32) NOT NULL COMMENT 'Figure used: adult_male or adult_female',
  `body_view` VARCHAR(16) NOT NULL COMMENT 'Figure view: anterior or posterior',
  `zone_id` VARCHAR(64) NOT NULL COMMENT 'Anatomical zone id from the SVG, format region_subzone_side (example: knee_patella_left)',
  `laterality` VARCHAR(16) NOT NULL DEFAULT 'midline' COMMENT 'Laterality: left, right or midline (derived from the zone_id suffix)',
  `x_pct` DECIMAL(6,3) NOT NULL COMMENT 'Horizontal position of the mark, percentage 0-100 of the figure viewBox width',
  `y_pct` DECIMAL(6,3) NOT NULL COMMENT 'Vertical position of the mark, percentage 0-100 of the figure viewBox height',
  `intensity` TINYINT UNSIGNED NOT NULL COMMENT 'Pain intensity, numeric scale 0-10 (0 no pain, 10 worst pain possible)',
  `pain_quality` VARCHAR(64) DEFAULT NULL COMMENT 'Pain quality (stabbing, burning, pressing, etc.), single value',
  `radiation` VARCHAR(255) DEFAULT NULL COMMENT 'Radiation: where the pain spreads to',
  `onset` VARCHAR(100) DEFAULT NULL COMMENT 'Pain onset (date or free text, example: 3 days ago)',
  `duration` VARCHAR(100) DEFAULT NULL COMMENT 'Pain duration (free text, example: 2 hours, constant)',
  `aggravating` VARCHAR(255) DEFAULT NULL COMMENT 'Factors that aggravate the pain',
  `relieving` VARCHAR(255) DEFAULT NULL COMMENT 'Factors that relieve the pain',
  `notes` TEXT COMMENT 'Free-text notes for the mark',
  `activity` TINYINT(4) NOT NULL DEFAULT 1 COMMENT 'Active: 1 current, 0 deleted (soft delete)',
  `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time the mark was created',
  `date_modified` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Date and time of the last modification',
  PRIMARY KEY (`id`),
  KEY `form_id` (`form_id`),
  KEY `pid_zone` (`pid`, `zone_id`),
  CONSTRAINT `chk_pz_marks_intensity` CHECK (`intensity` BETWEEN 0 AND 10),
  CONSTRAINT `chk_pz_marks_x` CHECK (`x_pct` BETWEEN 0 AND 100),
  CONSTRAINT `chk_pz_marks_y` CHECK (`y_pct` BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Pain zones: one row per pain mark on the figure';


-- ----------------------------------------------------------------------------
-- 3. form_pain_zones_history
-- Audit trail of changes to marks. Each insert, update or delete records a row
-- with the state of the mark in JSON format.
-- Progression between visits does not use this table: it is built by comparing
-- the forms from each of the patient's encounters.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `form_pain_zones_history` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT COMMENT 'Audit record identifier',
  `mark_id` BIGINT(20) NOT NULL COMMENT 'Id of the affected mark (form_pain_zones_marks.id)',
  `form_id` BIGINT(20) NOT NULL COMMENT 'Id of the mark form (form_pain_zones.id)',
  `pid` BIGINT(20) NOT NULL COMMENT 'Patient id',
  `action` VARCHAR(8) NOT NULL COMMENT 'Action performed: insert, update or delete',
  `changed_by` VARCHAR(255) DEFAULT NULL COMMENT 'User who made the change',
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time of the change',
  `snapshot` MEDIUMTEXT COMMENT 'Full state of the mark in JSON at the time of the change',
  PRIMARY KEY (`id`),
  KEY `mark_id` (`mark_id`),
  KEY `pid_changed` (`pid`, `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Pain zones: audit trail of mark inserts, updates and deletes';


-- ----------------------------------------------------------------------------
-- 4. list_options: list pain_zones_level
-- 4.a List title row (list_id = lists), using the next free seq.
-- 4.b Levels 0-10. notes = mark colors in the format
--     stroke:#RRGGBB,fill:#RRGGBB (parsed by splitting on the comma).
--     mapping = clinical band: none (0), mild (1-3), moderate (4-6), severe (7-10).
--     option_value = numeric intensity of the level.
-- INSERT IGNORE prevents duplicates if the script runs more than once.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `list_options`
  (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'lists', 'pain_zones_level', 'Pain Zones Level', COALESCE(MAX(`seq`), 0) + 1, 0, 0, '', '', '', 1
FROM `list_options` WHERE `list_id` = 'lists';

INSERT IGNORE INTO `list_options`
  (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
VALUES
  ('pain_zones_level', 'level_0',  'Level 0',  0,  1, 0,  'none',     'stroke:#848484,fill:#BDBDBD', '', 1),
  ('pain_zones_level', 'level_1',  'Level 1',  1,  0, 1,  'mild',     'stroke:#357A38,fill:#4CAF50', '', 1),
  ('pain_zones_level', 'level_2',  'Level 2',  2,  0, 2,  'mild',     'stroke:#618834,fill:#8BC34A', '', 1),
  ('pain_zones_level', 'level_3',  'Level 3',  3,  0, 3,  'mild',     'stroke:#8F9A28,fill:#CDDC39', '', 1),
  ('pain_zones_level', 'level_4',  'Level 4',  4,  0, 4,  'moderate', 'stroke:#B2A429,fill:#FFEB3B', '', 1),
  ('pain_zones_level', 'level_5',  'Level 5',  5,  0, 5,  'moderate', 'stroke:#B28705,fill:#FFC107', '', 1),
  ('pain_zones_level', 'level_6',  'Level 6',  6,  0, 6,  'moderate', 'stroke:#B26A00,fill:#FF9800', '', 1),
  ('pain_zones_level', 'level_7',  'Level 7',  7,  0, 7,  'severe',   'stroke:#B23D18,fill:#FF5722', '', 1),
  ('pain_zones_level', 'level_8',  'Level 8',  8,  0, 8,  'severe',   'stroke:#AB2F26,fill:#F44336', '', 1),
  ('pain_zones_level', 'level_9',  'Level 9',  9,  0, 9,  'severe',   'stroke:#942121,fill:#D32F2F', '', 1),
  ('pain_zones_level', 'level_10', 'Level 10', 10, 0, 10, 'severe',   'stroke:#801414,fill:#B71C1C', '', 1);


-- ----------------------------------------------------------------------------
-- 5. list_options: list pain_zones_quality
-- 5.a List title row (list_id = lists), using the next free seq.
-- 5.b Clinical qualities of pain (Sharp, Dull, Burning, etc.).
--     Can be customized and managed via OpenEMR Administration -> Lists.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `list_options`
  (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'lists', 'pain_zones_quality', 'Pain Zones Quality', COALESCE(MAX(`seq`), 0) + 1, 0, 0, '', '', '', 1
FROM `list_options` WHERE `list_id` = 'lists';

-- Quality levels. codes = SNOMED CT concept id (format SNOMED-CT:<id>).
-- Rows with an empty codes field have no verified SNOMED CT concept yet.
INSERT IGNORE INTO `list_options`
  (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
VALUES
  ('pain_zones_quality', 'sharp',     'Sharp',     10,  0, 0, '', '', 'SNOMED-CT:8708008',   1),
  ('pain_zones_quality', 'dull',      'Dull',      20,  0, 0, '', '', 'SNOMED-CT:83644001',  1),
  ('pain_zones_quality', 'burning',   'Burning',   30,  0, 0, '', '', 'SNOMED-CT:36349006',  1),
  ('pain_zones_quality', 'throbbing', 'Throbbing', 40,  0, 0, '', '', 'SNOMED-CT:29695002',  1),
  ('pain_zones_quality', 'stabbing',  'Stabbing',  50,  0, 0, '', '', 'SNOMED-CT:55145008',  1),
  ('pain_zones_quality', 'cramping',  'Cramping',  60,  0, 0, '', '', 'SNOMED-CT:279093005', 1),
  ('pain_zones_quality', 'pressing',  'Pressing',  70,  0, 0, '', '', 'SNOMED-CT:162505000', 1),
  ('pain_zones_quality', 'shooting',  'Shooting',  80,  0, 0, '', '', 'SNOMED-CT:49575005',  1),
  ('pain_zones_quality', 'aching',    'Aching',    90,  0, 0, '', '', 'SNOMED-CT:27635008',  1),
  ('pain_zones_quality', 'electric',  'Electric',  100, 0, 0, '', '', 'SNOMED-CT:723316007', 1),
  ('pain_zones_quality', 'tingling',  'Tingling',  110, 0, 0, '', '', 'SNOMED-CT:786837007', 1),
  ('pain_zones_quality', 'heavy',     'Heavy',     120, 0, 0, '', '', 'SNOMED-CT:279095003', 1),
  ('pain_zones_quality', 'other',     'Other',     130, 0, 0, '', '', '',                    1);
