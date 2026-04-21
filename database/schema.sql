-- ============================================================
--  ProClasse – Schéma MariaDB
--  Compatible extraction Pronote (TSV, 113+ colonnes)
-- ============================================================

CREATE DATABASE IF NOT EXISTS proclasse
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE proclasse;

-- ------------------------------------------------------------
-- Utilisateur applicatif
-- ------------------------------------------------------------
-- CREATE USER IF NOT EXISTS 'proclasse_user'@'localhost' IDENTIFIED BY 'CHANGE_ME';
-- GRANT ALL PRIVILEGES ON proclasse.* TO 'proclasse_user'@'localhost';
-- FLUSH PRIVILEGES;

-- ------------------------------------------------------------
-- Salles de classe
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    `rows`     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `cols`     TINYINT UNSIGNED NOT NULL DEFAULT 6,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Sièges (places actives dans une salle)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seats (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    room_id     INT NOT NULL,
    row_index   TINYINT UNSIGNED NOT NULL,
    col_index   TINYINT UNSIGNED NOT NULL,
    label       VARCHAR(20) DEFAULT NULL,
    UNIQUE KEY uq_seat (room_id, row_index, col_index),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Classes (groupes d'élèves)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    year       VARCHAR(9)   DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
,
    UNIQUE KEY uq_class_name (name)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Élèves — colonnes essentielles + champs Pronote utiles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    class_id            INT NOT NULL,

    -- Identité
    last_name           VARCHAR(100) NOT NULL,
    first_name          VARCHAR(100) NOT NULL,
    first_name_usage    VARCHAR(100) DEFAULT NULL,   -- "Prénom d'usage"
    first_name_2        VARCHAR(100) DEFAULT NULL,   -- "Prénom 2"
    first_name_3        VARCHAR(100) DEFAULT NULL,   -- "Prénom 3"
    gender              CHAR(1)      DEFAULT NULL,   -- 'M' / 'F'
    birthdate           DATE         DEFAULT NULL,   -- "Né(e) le"
    birthplace          VARCHAR(100) DEFAULT NULL,   -- "Né(e) à"
    nationality         VARCHAR(100) DEFAULT NULL,   -- "Nationalité"
    birth_country       VARCHAR(100) DEFAULT NULL,   -- "Pays de naissance"
    is_major            TINYINT(1)   DEFAULT 0,      -- "Majeur"

    -- Identifiants
    pronote_id          VARCHAR(50)  DEFAULT NULL,   -- "Numéro national" (INE)

    -- Contact
    email               VARCHAR(150) DEFAULT NULL,   -- "Adresse E-mail"
    phone               VARCHAR(30)  DEFAULT NULL,   -- "Tél. (SMS)"

    -- Scolarité
    class_name          VARCHAR(50)  DEFAULT NULL,   -- "Classe" (ex: 3B, TG2)
    level               VARCHAR(50)  DEFAULT NULL,   -- "Niveau" (ex: 3EME, TERMINALE)
    formation           VARCHAR(100) DEFAULT NULL,   -- "Formation" (ex: TERMINALE GENERALE)
    regime              VARCHAR(100) DEFAULT NULL,   -- "Régime" (ex: EXTERNE LIBRE)
    head_teacher        VARCHAR(150) DEFAULT NULL,   -- "Professeur principal"
    school_start        DATE         DEFAULT NULL,   -- "Date début scolarité"
    school_end          DATE         DEFAULT NULL,   -- "Date fin scolarité"
    is_repeating        TINYINT(1)   DEFAULT 0,      -- "Redoublant"
    support_project     VARCHAR(255) DEFAULT NULL,   -- "Projet d'accompagnement" (PAI, PPS, CP…)
    allergies           VARCHAR(255) DEFAULT NULL,   -- "Allergies"

    -- Options / groupes (stockés en texte brut)
    groups              TEXT         DEFAULT NULL,   -- "Groupes"
    options             TEXT         DEFAULT NULL,   -- "Toutes les options"

    -- Métadonnées import
    imported_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_pronote (pronote_id)   -- évite les doublons sur INE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Données Pronote brutes (toutes les colonnes, pour ne rien perdre)
-- Permet de stocker les colonnes qui apparaissent/disparaissent
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_pronote_data (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    field_name  VARCHAR(100) NOT NULL,   -- nom de la colonne Pronote
    field_value TEXT         DEFAULT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_field (student_id, field_name),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Plans de salle
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seating_plans (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    class_id   INT NOT NULL,
    room_id    INT NOT NULL,
    name       VARCHAR(100) NOT NULL DEFAULT 'Plan par défaut',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plan (class_id, room_id, name),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id)  REFERENCES rooms(id)   ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Affectations : élève → siège dans un plan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seating_assignments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    plan_id    INT NOT NULL,
    seat_id    INT NOT NULL,
    student_id INT NOT NULL,
    UNIQUE KEY uq_assign_seat    (plan_id, seat_id),
    UNIQUE KEY uq_assign_student (plan_id, student_id),
    FOREIGN KEY (plan_id)    REFERENCES seating_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (seat_id)    REFERENCES seats(id)         ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)      ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Séances
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    plan_id    INT NOT NULL,
    `date`     DATE NOT NULL,
    subject    VARCHAR(100) DEFAULT NULL,
    notes      TEXT         DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES seating_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Observations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS observations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    tag        VARCHAR(50) NOT NULL,
    note       TEXT        DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tags prédéfinis
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(50) NOT NULL UNIQUE,
    color      VARCHAR(20) NOT NULL DEFAULT '#01696f',
    icon       VARCHAR(10) DEFAULT NULL,
    sort_order TINYINT     DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO tags (label, color, icon, sort_order) VALUES
  ('Participe',      '#437a22', '✋', 1),
  ('Bavarde',        '#a13544', '💬', 2),
  ('Distrait',       '#da7101', '👀', 3),
  ('Excellent',      '#006494', '⭐', 4),
  ('En difficulté',  '#964219', '🆘', 5),
  ('Absent',         '#7a7974', '❌', 6),
  ('Félicitations',  '#d19900', '🏅', 7),
  ('Avertissement',  '#a12c7b', '⚠️',  8)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

CREATE TABLE IF NOT EXISTS tags (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(50)  NOT NULL,
    color      VARCHAR(20)  NOT NULL DEFAULT '#888888',
    icon       VARCHAR(10)  DEFAULT '',
    sort_order TINYINT      NOT NULL DEFAULT 99,
    UNIQUE KEY uq_tag_label (label)
) ENGINE=InnoDB;
-- ============================================================
-- Migration : ajout de time_start et time_end dans sessions
-- À exécuter UNE SEULE FOIS sur la base proclasse
-- ============================================================

USE proclasse;

ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS time_start TIME NULL AFTER `date`,
    ADD COLUMN IF NOT EXISTS time_end   TIME NULL AFTER time_start;

-- Index pour accélérer la déduplication lors de l'import ICS
CREATE INDEX IF NOT EXISTS idx_sessions_plan_date_time
    ON sessions (plan_id, `date`, time_start);






CREATE TABLE IF NOT EXISTS `groups` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_name (class_id, name),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS group_students (
    group_id INT NOT NULL,
    student_id INT NOT NULL,
    PRIMARY KEY (group_id, student_id),
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;    
