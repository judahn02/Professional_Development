-- MySQL Workbench Forward Engineering

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------
-- Schema beta_2
-- -----------------------------------------------------
DROP SCHEMA IF EXISTS `beta_2` ;

-- -----------------------------------------------------
-- Schema beta_2
-- -----------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `beta_2` DEFAULT CHARACTER SET utf8 ;
USE `beta_2` ;

-- -----------------------------------------------------
-- Table `beta_2`.`person`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`person` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`person` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(45) NULL,
  `last_name` VARCHAR(45) NULL,
  `email` VARCHAR(254) NULL,
  `wp_id` INT NULL,
  `attendee` TINYINT NOT NULL COMMENT '0 = No, 1 = Yes',
  `presenter` TINYINT NOT NULL COMMENT '0 = No, 1 = Yes',
  `phone_number` VARCHAR(20) NULL,
  PRIMARY KEY (`id`),
  INDEX `wp_id_INDEX` (`wp_id` ASC) VISIBLE,
  INDEX `attendee_INDEX` (`attendee` ASC) VISIBLE,
  INDEX `presenter` (`presenter` ASC) VISIBLE)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `beta_2`.`administrative_service`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`administrative_service` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`administrative_service` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `member_id` INT UNSIGNED NOT NULL,
  `serving_type` ENUM('Board', 'Committie') NOT NULL,
  `ceu_weight` DECIMAL(4,3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC) VISIBLE,
  UNIQUE INDEX `duplicate_entry_UNIQUE` (`start_date` ASC, `end_date` ASC, `member_id` ASC) VISIBLE,
  INDEX `id_INDEX` (`id` ASC) VISIBLE,
  INDEX `fk_admin_ser_member_id_idx` (`member_id` ASC) VISIBLE,
  CONSTRAINT `fk_admin_ser_member_id`
    FOREIGN KEY (`member_id`)
    REFERENCES `beta_2`.`person` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `beta_2`.`session_type`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`session_type` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`session_type` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC) VISIBLE,
  UNIQUE INDEX `name_UNIQUE` (`name` ASC) VISIBLE,
  INDEX `name_INDEX` (`name` ASC) VISIBLE)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `beta_2`.`event_type`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`event_type` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`event_type` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC) VISIBLE,
  UNIQUE INDEX `name_UNIQUE` (`name` ASC) VISIBLE,
  INDEX `name_INDEX` (`name` ASC) VISIBLE)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `beta_2`.`ceu_type`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`ceu_type` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`ceu_type` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `name_UNIQUE` (`name` ASC) VISIBLE,
  UNIQUE INDEX `id_UNIQUE` (`id` ASC) VISIBLE,
  INDEX `name_INDEX` (`name` ASC) VISIBLE)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `beta_2`.`sessions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`sessions` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(256) NOT NULL,
  `date` DATE NOT NULL,
  `length` INT NOT NULL COMMENT 'Length of Session',
  `parent_event` VARCHAR(256) NULL DEFAULT NULL COMMENT 'Optional, this is for if the session is under a confrece. ',
  `session_type_id` INT UNSIGNED NULL,
  `ceu_type_id` INT UNSIGNED NULL DEFAULT NULL,
  `event_type_id` INT UNSIGNED NULL,
  `ceu_weight` DECIMAL(4,3) GENERATED ALWAYS AS (if(`ceu_type_id` is null,0,`length` / 60.0 * 0.1)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC) VISIBLE,
  UNIQUE INDEX `title_date_UNIQUE` (`title` ASC, `date` ASC) VISIBLE,
  INDEX `title_INDEX` (`title` ASC) VISIBLE,
  INDEX `specific_event_INDEX` (`parent_event` ASC) VISIBLE,
  INDEX `length_INDEX` (`length` ASC) VISIBLE,
  INDEX `date_INDEX` (`date` ASC) VISIBLE,
  INDEX `fk_sessions_session_type_idx` (`session_type_id` ASC) VISIBLE,
  INDEX `fk_sessions_event_type_idx` (`event_type_id` ASC) VISIBLE,
  INDEX `fk_sessions_ceu_type_idx` (`ceu_type_id` ASC) VISIBLE,
  CONSTRAINT `fk_sessions_session_type`
    FOREIGN KEY (`session_type_id`)
    REFERENCES `beta_2`.`session_type` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_sessions_event_type`
    FOREIGN KEY (`event_type_id`)
    REFERENCES `beta_2`.`event_type` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_sessions_ceu_type`
    FOREIGN KEY (`ceu_type_id`)
    REFERENCES `beta_2`.`ceu_type` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB
COMMENT = 'It would best be called session but it some databases use session for non table pourposes. That is why its sessions';


-- -----------------------------------------------------
-- Table `beta_2`.`attending`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`attending` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`attending` (
  `person_id` INT UNSIGNED NOT NULL,
  `sessions_id` INT UNSIGNED NOT NULL,
  `certification_status` ENUM('Certified', 'Master', 'None') NULL DEFAULT NULL COMMENT '\'Certified\', \'Master\', \'None\'\\\\n',
  PRIMARY KEY (`person_id`, `sessions_id`),
  INDEX `fk_member_id_idx` (`person_id` ASC) VISIBLE,
  INDEX `fk_attending_sessions_id_idx` (`sessions_id` ASC) VISIBLE,
  CONSTRAINT `fk_attending_member_id`
    FOREIGN KEY (`person_id`)
    REFERENCES `beta_2`.`person` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_attending_sessions_id`
    FOREIGN KEY (`sessions_id`)
    REFERENCES `beta_2`.`sessions` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `beta_2`.`presenting`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`presenting` ;

CREATE TABLE IF NOT EXISTS `beta_2`.`presenting` (
  `person_id` INT UNSIGNED NOT NULL,
  `sessions_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`person_id`, `sessions_id`),
  INDEX `fk_presenter_id_idx` (`person_id` ASC) VISIBLE,
  INDEX `fk_presenting_sessions_id_idx` (`sessions_id` ASC) VISIBLE,
  CONSTRAINT `fk_presenting_presenter_id`
    FOREIGN KEY (`person_id`)
    REFERENCES `beta_2`.`person` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_presenting_sessions_id`
    FOREIGN KEY (`sessions_id`)
    REFERENCES `beta_2`.`sessions` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;

USE `beta_2` ;

-- -----------------------------------------------------
-- Placeholder table for view `beta_2`.`GET_presenters_table`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `beta_2`.`GET_presenters_table` (`idPresentor` INT, `name` INT, `email` INT, `phone_number` INT, `session_count` INT);

-- -----------------------------------------------------
-- procedure GET_presenter_administrative_service
-- -----------------------------------------------------

USE `beta_2`;
DROP procedure IF EXISTS `beta_2`.`GET_presenter_administrative_service`;

DELIMITER $$
USE `beta_2`$$
CREATE DEFINER=`asltauser`@`%` PROCEDURE `GET_presenter_administrative_service`(
    IN p_members_id INT
)
BEGIN
    SELECT 
        t.start_date,
        t.end_date,
        t.serving_type,
        t.ceu_weight
    FROM beta_2.administrative_service AS t
    WHERE t.member_id = p_members_id;
END$$

DELIMITER ;

-- -----------------------------------------------------
-- procedure GET_presenter_sessions
-- -----------------------------------------------------

USE `beta_2`;
DROP procedure IF EXISTS `beta_2`.`GET_presenter_sessions`;

DELIMITER $$
USE `beta_2`$$
CREATE DEFINER=`asltauser`@`%` PROCEDURE `GET_presenter_sessions`(
    IN p_idPresentor INT
)
BEGIN
    SELECT
        s2.id            AS session_id,
        s2.title         AS session_title,
        s2.`date`        AS session_date,
        s2.parent_event AS session_parent_event
    FROM beta_2.person AS p
    LEFT JOIN beta_2.presenting AS p2
        ON p.id = p2.person_id
    LEFT JOIN beta_2.sessions AS s2
        ON p2.sessions_id = s2.id
    WHERE p.id = p_idPresentor;
END$$

DELIMITER ;

-- -----------------------------------------------------
-- View `beta_2`.`GET_presenters_table`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `beta_2`.`GET_presenters_table`;
DROP VIEW IF EXISTS `beta_2`.`GET_presenters_table` ;
USE `beta_2`;
CREATE  OR REPLACE 
    ALGORITHM = UNDEFINED 
    DEFINER = `asltauser`@`%` 
    SQL SECURITY DEFINER
VIEW `beta_2`.`GET_presenters_table` AS
    SELECT 
        `p`.`id` AS `idPresentor`,
        CONCAT(`p`.`first_name`, ' ', `p`.`last_name`) AS `name`,
        `p`.`email` AS `email`,
        `p`.`phone_number` AS `phone_number`,
        COUNT(`p2`.`sessions_id`) AS `session_count`
    FROM
        (`beta_2`.`person` `p`
        LEFT JOIN `beta_2`.`presenting` `p2` ON (`p`.`id` = `p2`.`person_id`))
	WHERE `p`.`presenter` = 1
    GROUP BY `p`.`id`;

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
-- begin attached script 'script'
-- for inserting data to beta
USE `beta_2`;

-- Drop if it already exists
SET @sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'beta_2'
      AND TABLE_NAME   = 'sessions'
      AND COLUMN_NAME  = 'ceu_weight'
  ),
  'ALTER TABLE `beta_2`.`sessions` DROP COLUMN `ceu_weight`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Re-add as virtual generated
ALTER TABLE `beta_2`.`sessions`
  ADD COLUMN `ceu_weight` DECIMAL(5,3)
    GENERATED ALWAYS AS (
      CASE
        WHEN `ceu_type_id` IS NULL THEN 0
        ELSE (`length` / 60.0) * 0.1
      END
    ) VIRTUAL;


-- end attached script 'script'
