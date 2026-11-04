-- Version 4: associate each other donation with the exact sponsor/orphan sponsorship.
-- Apply once to ahl_el_kheir_monthly_data only. Existing sponsor-level donations remain valid.
ALTER TABLE mde_monthly_other_donations
    ADD COLUMN sponsorship_id BIGINT UNSIGNED NULL AFTER sponsor_id,
    ADD KEY idx_mde_other_donations_sponsorship (sponsorship_id),
    ADD CONSTRAINT fk_mde_other_donations_sponsorship
        FOREIGN KEY (sponsorship_id)
        REFERENCES mde_sponsorships(id)
        ON UPDATE CASCADE ON DELETE RESTRICT;

INSERT IGNORE INTO mde_schema_version (version) VALUES (4);
