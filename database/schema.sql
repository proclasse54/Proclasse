-- ============================================================
--  ProClasse – Schéma MariaDB
--  Source de vérité — synchronisé avec la BDD de production
--  Dernière mise à jour : 2026-04-29
-- ============================================================

CREATE DATABASE IF NOT EXISTS proclasse
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE proclasse;

-- ------------------------------------------------------------
-- Utilisateurs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL,
    email          VARCHAR(150) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('admin','sub_admin','user') NOT NULL DEFAULT 'user',
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at  TIMESTAMP NULL DEFAULT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email    (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id    INT         NOT NULL,
    permission VARCHAR(50) NOT NULL,
    PRIMARY KEY (user_id, permission),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP   NOT NULL,
    used_at    TIMESTAMP   NULL DEFAULT NULL,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Logs applicatifs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_logs (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT  NULL DEFAULT NULL,
    level      ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
    category   VARCHAR(50)  NOT NULL,
    action     VARCHAR(100) NOT NULL,
    details    LONGTEXT     DEFAULT NULL CHECK (JSON_VALID(details)),
    ip         VARCHAR(45)  DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_level    (level),
    KEY idx_logs_category (category),
    KEY idx_logs_created  (created_at),
    KEY idx_logs_user     (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Salles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    `rows`     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `cols`     TINYINT UNSIGNED NOT NULL DEFAULT 6,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seats (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    room_id   INT NOT NULL,
    row_index TINYINT UNSIGNED NOT NULL,
    col_index TINYINT UNSIGNED NOT NULL,
    label     VARCHAR(20) DEFAULT NULL,
    UNIQUE KEY uq_seat (room_id, row_index, col_index),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Paramètres de recadrage photo
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_crop_settings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    scope      ENUM('default','class','student') NOT NULL DEFAULT 'default',
    scope_id   INT NULL,
    crop_x     FLOAT NOT NULL DEFAULT 0.15,
    crop_y     FLOAT NOT NULL DEFAULT 0.05,
    crop_w     FLOAT NOT NULL DEFAULT 0.70,
    crop_h     FLOAT NOT NULL DEFAULT 0.55,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_scope (scope, scope_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO photo_crop_settings (scope, scope_id) VALUES ('default', NULL);

-- ------------------------------------------------------------
-- Classes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    year       VARCHAR(9)   DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Élèves
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    class_id         INT NOT NULL,
    last_name        VARCHAR(100) NOT NULL,
    first_name       VARCHAR(100) NOT NULL,
    first_name_usage VARCHAR(100) DEFAULT NULL,
    first_name_2     VARCHAR(100) DEFAULT NULL,
    first_name_3     VARCHAR(100) DEFAULT NULL,
    gender           CHAR(1)      DEFAULT NULL,
    birthdate        DATE         DEFAULT NULL,
    birthplace       VARCHAR(100) DEFAULT NULL,
    nationality      VARCHAR(100) DEFAULT NULL,
    birth_country    VARCHAR(100) DEFAULT NULL,
    is_major         TINYINT(1)   DEFAULT 0,
    pronote_id       VARCHAR(50)  DEFAULT NULL,
    email            VARCHAR(150) DEFAULT NULL,
    phone            VARCHAR(30)  DEFAULT NULL,
    class_name       VARCHAR(50)  DEFAULT NULL,
    level            VARCHAR(50)  DEFAULT NULL,
    formation        VARCHAR(100) DEFAULT NULL,
    regime           VARCHAR(100) DEFAULT NULL,
    head_teacher     VARCHAR(150) DEFAULT NULL,
    school_start     DATE         DEFAULT NULL,
    school_end       DATE         DEFAULT NULL,
    is_repeating     TINYINT(1)   DEFAULT 0,
    support_project  VARCHAR(255) DEFAULT NULL,
    allergies        VARCHAR(255) DEFAULT NULL,
    `groups`         TEXT         DEFAULT NULL,
    options          TEXT         DEFAULT NULL,
    imported_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pronote (pronote_id),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_pronote_data (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    field_name  VARCHAR(100) NOT NULL,
    field_value TEXT         DEFAULT NULL,
    imported_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_field (student_id, field_name),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Groupes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `groups` (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    class_id   INT NULL DEFAULT NULL,
    name       VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_name (class_id, name),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS group_students (
    group_id   INT NOT NULL,
    student_id INT NOT NULL,
    PRIMARY KEY (group_id, student_id),
    FOREIGN KEY (group_id)   REFERENCES `groups`(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Plans de salle
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seating_plans (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    class_id   INT NOT NULL,
    group_id   INT NULL DEFAULT NULL,
    room_id    INT NOT NULL,
    name       VARCHAR(100) NOT NULL DEFAULT 'Plan par défaut',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plan (class_id, room_id, name),
    KEY room_id  (room_id),
    KEY group_id (group_id),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id)  REFERENCES rooms(id)   ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seating_assignments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    plan_id    INT NOT NULL,
    seat_id    INT NOT NULL,
    student_id INT NOT NULL,
    UNIQUE KEY uq_assign_seat    (plan_id, seat_id),
    UNIQUE KEY uq_assign_student (plan_id, student_id),
    KEY seat_id    (seat_id),
    KEY student_id (student_id),
    FOREIGN KEY (plan_id)    REFERENCES seating_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (seat_id)    REFERENCES seats(id)         ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)      ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Séances
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    plan_id        INT NULL DEFAULT NULL,
    multi_classes  VARCHAR(255) NULL COMMENT 'Libéllé brut des classes pour les séances multi-classes sans plan',
    `date`         DATE NOT NULL,
    time_start     TIME NULL DEFAULT NULL,
    time_end       TIME NULL DEFAULT NULL,
    subject        VARCHAR(100) DEFAULT NULL,
    notes          TEXT         DEFAULT NULL,
    created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sessions_plan_date_time (plan_id, `date`, time_start),
    FOREIGN KEY (plan_id) REFERENCES seating_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Snapshot de placement par séance
-- Créé automatiquement à la création de la séance (copie du plan)
-- Mis à jour lors d'un déplacement d'élève (scope session ou forward)
CREATE TABLE IF NOT EXISTS session_seats (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    seat_id    INT NOT NULL,
    student_id INT NULL DEFAULT NULL,
    UNIQUE KEY uq_session_seat (session_id, seat_id),
    KEY seat_id    (seat_id),
    KEY student_id (student_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id)  ON DELETE CASCADE,
    FOREIGN KEY (seat_id)    REFERENCES seats(id)      ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)   ON DELETE SET NULL
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
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY session_id (session_id),
    KEY student_id (student_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tags
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(50) NOT NULL,
    color      VARCHAR(20) NOT NULL DEFAULT '#01696f',
    icon       VARCHAR(10) DEFAULT NULL,
    sort_order TINYINT     DEFAULT 0,
    UNIQUE KEY label (label)
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
