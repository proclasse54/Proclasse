-- ============================================================
--  Migration 003 — Années scolaires
--  À exécuter UNE SEULE FOIS sur la base proclasse
-- ============================================================

USE proclasse;

-- Table des années scolaires
CREATE TABLE IF NOT EXISTS school_years (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(20) NOT NULL UNIQUE,   -- ex : "2024-2025", "2025-2026"
    date_start DATE        NOT NULL,
    date_end   DATE        NOT NULL,
    is_current TINYINT(1)  NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_school_year_dates CHECK (date_end > date_start)
) ENGINE=InnoDB;

-- Index unicité sur is_current=1 (MariaDB 10.5+ via expression index)
-- Contourné par contrainte applicative dans le contrôleur.

-- Rattacher les classes à une année scolaire
ALTER TABLE classes
    ADD COLUMN IF NOT EXISTS school_year_id INT NULL
        COMMENT 'Année scolaire de cette classe (NULL = non archivée)'
        AFTER year,
    ADD CONSTRAINT fk_classes_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE SET NULL;

-- Rattacher les plans de salle à une année scolaire
-- (dénormalisé pour faciliter les requêtes sans jointure sur classes)
ALTER TABLE seating_plans
    ADD COLUMN IF NOT EXISTS school_year_id INT NULL
        COMMENT 'Copie de classes.school_year_id pour faciliter les filtres'
        AFTER name,
    ADD CONSTRAINT fk_plans_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE SET NULL;

-- Insérer l'année courante comme première entrée
INSERT IGNORE INTO school_years (label, date_start, date_end, is_current)
VALUES ('2025-2026', '2025-09-01', '2026-07-04', 1);
