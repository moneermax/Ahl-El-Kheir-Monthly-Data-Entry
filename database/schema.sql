-- Ahl El Kheir - Monthly Data Entry Tool
-- Install manually into a dedicated database. This file is never executed at runtime.
-- Compatible with MariaDB 10.4+ / MySQL 5.7+.

CREATE DATABASE IF NOT EXISTS ahl_el_kheir_monthly_data
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ahl_el_kheir_monthly_data;

CREATE TABLE IF NOT EXISTS mde_sponsors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sponsor_name VARCHAR(255) NOT NULL,
    file_number VARCHAR(100) NULL,
    sponsor_type ENUM('individual','tree') NOT NULL DEFAULT 'individual',
    phone VARCHAR(50) NULL,
    address VARCHAR(500) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_sponsors_name (sponsor_name),
    KEY idx_mde_sponsors_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mde_mothers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mother_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_mothers_name (mother_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mde_orphans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mother_id BIGINT UNSIGNED NOT NULL,
    orphan_name VARCHAR(255) NOT NULL,
    date_of_birth DATE NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_orphans_mother (mother_id),
    KEY idx_mde_orphans_name (orphan_name),
    KEY idx_mde_orphans_active (is_active),
    CONSTRAINT fk_mde_orphans_mother FOREIGN KEY (mother_id)
      REFERENCES mde_mothers(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A sponsor/orphan relationship is its own record. This permits a sponsor to
-- support many orphans and permits an orphan to have more than one sponsor.
CREATE TABLE IF NOT EXISTS mde_sponsorships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sponsor_id BIGINT UNSIGNED NOT NULL,
    orphan_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('active','ended','paused') NOT NULL DEFAULT 'active',
    reference_no VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_sponsorships_sponsor (sponsor_id),
    KEY idx_mde_sponsorships_orphan (orphan_id),
    KEY idx_mde_sponsorships_status_dates (status, start_date, end_date),
    KEY idx_mde_sponsorships_reference (reference_no),
    UNIQUE KEY uq_mde_sponsorship_identity (id, sponsor_id, orphan_id),
    CONSTRAINT fk_mde_sponsorships_sponsor FOREIGN KEY (sponsor_id)
      REFERENCES mde_sponsors(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mde_sponsorships_orphan FOREIGN KEY (orphan_id)
      REFERENCES mde_orphans(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per sponsor/orphan allocation per reporting month. Amounts are stored as integer
-- SDG units to avoid floating-point rounding errors.
CREATE TABLE IF NOT EXISTS mde_monthly_sponsorship_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sponsorship_id BIGINT UNSIGNED NOT NULL,
    sponsor_id BIGINT UNSIGNED NOT NULL,
    orphan_id BIGINT UNSIGNED NOT NULL,
    report_month DATE NOT NULL COMMENT 'Always the first day of the reporting month',
    entry_date DATE NOT NULL,
    sponsorship_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    services_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    entered_by VARCHAR(190) NULL,
    supervisor_name VARCHAR(190) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_monthly_sponsorship_month (report_month),
    KEY idx_mde_monthly_sponsorship_sponsor (sponsorship_id),
    UNIQUE KEY uq_mde_monthly_sponsor_orphan_month (sponsor_id, orphan_id, report_month),
    KEY idx_mde_monthly_sponsorship_entry_date (entry_date),
    CONSTRAINT fk_mde_monthly_sponsorship_identity
      FOREIGN KEY (sponsorship_id, sponsor_id, orphan_id)
      REFERENCES mde_sponsorships(id, sponsor_id, orphan_id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_mde_report_month_first_day CHECK (DAYOFMONTH(report_month) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mde_monthly_other_donations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sponsor_id BIGINT UNSIGNED NULL,
    sponsorship_id BIGINT UNSIGNED NULL,
    report_month DATE NOT NULL COMMENT 'Always the first day of the reporting month',
    donation_date DATE NOT NULL,
    donation_type VARCHAR(190) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    entered_by VARCHAR(190) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_other_donations_month (report_month),
    KEY idx_mde_other_donations_sponsor (sponsor_id),
    KEY idx_mde_other_donations_sponsorship (sponsorship_id),
    CONSTRAINT fk_mde_other_donations_sponsor FOREIGN KEY (sponsor_id)
      REFERENCES mde_sponsors(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mde_other_donations_sponsorship FOREIGN KEY (sponsorship_id)
      REFERENCES mde_sponsorships(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_mde_donation_month_first_day CHECK (DAYOFMONTH(report_month) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A departure is recorded at sponsor level because the source workflow tracks
-- one sponsor's overall departure and aggregate orphan/amount snapshots.
-- This never deletes sponsorships or their historical monthly entries.
CREATE TABLE IF NOT EXISTS mde_sponsor_departures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sponsor_id BIGINT UNSIGNED NOT NULL,
    sponsor_name_snapshot VARCHAR(255) NULL,
    file_number_snapshot VARCHAR(100) NULL,
    departure_date DATE NOT NULL,
    last_paid_month DATE NULL,
    orphan_count_snapshot SMALLINT UNSIGNED NULL,
    sponsorship_amount_snapshot BIGINT UNSIGNED NOT NULL DEFAULT 0,
    services_amount_snapshot BIGINT UNSIGNED NULL DEFAULT NULL,
    entered_by VARCHAR(190) NULL,
    supervisor_name VARCHAR(190) NULL,
    reason VARCHAR(500) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_departures_sponsor (sponsor_id),
    KEY idx_mde_departures_date (departure_date),
    CONSTRAINT fk_mde_departures_sponsor FOREIGN KEY (sponsor_id)
      REFERENCES mde_sponsors(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores before/after snapshots for corrections made through the data-management page.
CREATE TABLE IF NOT EXISTS mde_change_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type ENUM('sponsor','mother','orphan','monthly_entry','sponsorship','donation','departure') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL DEFAULT 'update',
    before_data LONGTEXT NOT NULL,
    after_data LONGTEXT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_change_log_entity (entity_type, entity_id),
    KEY idx_mde_change_log_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent operator and supervisor choices; role membership is independent.
CREATE TABLE IF NOT EXISTS mde_personnel_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role ENUM('operator','supervisor') NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mde_personnel_role_name (role, normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keeps a small, explicit record of schema installation/version for this tool.
CREATE TABLE IF NOT EXISTS mde_schema_version (
    version INT UNSIGNED NOT NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mde_schema_version (version) VALUES (1), (2), (3), (4), (5), (6), (7), (8), (9), (10), (11);
