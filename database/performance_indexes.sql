ALTER TABLE `mailtracking`
  ADD COLUMN `department_key` VARCHAR(20) NOT NULL DEFAULT '';

ALTER TABLE `archive`
  ADD COLUMN `department_key` VARCHAR(20) NOT NULL DEFAULT '';

UPDATE `mailtracking`
SET `department_key` = CASE
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-PRLS%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'PRLS-%' THEN 'prls'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%AFD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'AFD-%' THEN 'afd'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-PHSD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'PHSD-%' THEN 'phsd'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-ELUPD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'ELUPD-%' THEN 'elupd'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%ORD-AMAC%' OR UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-ORD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'ORD-%' THEN 'ord'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HOA CDD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'HOA-%' THEN 'hoa'
  ELSE 'emes'
END
WHERE COALESCE(`department_key`, '') = '';

UPDATE `archive`
SET `department_key` = CASE
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-PRLS%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'PRLS-%' THEN 'prls'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%AFD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'AFD-%' THEN 'afd'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-PHSD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'PHSD-%' THEN 'phsd'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-ELUPD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'ELUPD-%' THEN 'elupd'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%ORD-AMAC%' OR UPPER(COALESCE(`Sender Details`, '')) LIKE '%HREDRD-ORD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'ORD-%' THEN 'ord'
  WHEN UPPER(COALESCE(`Sender Details`, '')) LIKE '%HOA CDD%' OR UPPER(COALESCE(`Notice/Order Code`, '')) LIKE 'HOA-%' THEN 'hoa'
  ELSE 'emes'
END
WHERE COALESCE(`department_key`, '') = '';

ALTER TABLE `mailtracking`
  ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `mailtracking`
  ADD INDEX `idx_mailtracking_department_key` (`department_key`),
  ADD INDEX `idx_mailtracking_tracking_no` (`Tracking No.`),
  ADD INDEX `idx_mailtracking_transmittal_id` (`Transmittal ID`),
  ADD INDEX `idx_mailtracking_status` (`Status`),
  ADD INDEX `idx_mailtracking_date_released` (`Date released to AFD`),
  ADD INDEX `idx_mailtracking_updated_at` (`updated_at`),
  ADD INDEX `idx_mailtracking_sender_id` (`Sender Details`, `id`),
  ADD INDEX `idx_mailtracking_transmittal_parcel` (`Transmittal ID`, `Parcel No.`);

ALTER TABLE `archive`
  ADD INDEX `idx_archive_department_key` (`department_key`),
  ADD INDEX `idx_archive_tracking_no` (`Tracking No.`),
  ADD INDEX `idx_archive_transmittal_id` (`Transmittal ID`),
  ADD INDEX `idx_archive_status` (`Status`);
