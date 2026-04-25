-- ============================================================
--  ProClasse – Migration 001 : Auth + Logs
--  À exécuter UNE SEULE FOIS : mysql -u proclasse_user -p proclasse < 001_auth_logs.sql
-- ============================================================

USE proclasse;

-- ------------------------------------------------------------
-- Utilisateurs de l'application
-- ------------------------------------------------------------
-- Rôles :
--   admin     → accès total, gestion des users, purge logs
--   sub_admin → accès défini par user_permissions
--   user      → accès lecture + actions de base (séances, observations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL,
    email           VARCHAR(150) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,               -- bcrypt via password_hash()
    role            ENUM('admin','sub_admin','user') NOT NULL DEFAULT 'user',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at   TIMESTAMP    NULL DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email    (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Permissions granulaires (uniquement pour role = 'sub_admin')
-- Un admin a TOUT par défaut — pas de ligne en BDD nécessaire.
-- Un user n'a rien d'admin — pas de ligne non plus.
-- Seul sub_admin a des lignes ici.
-- ------------------------------------------------------------
-- Permissions disponibles :
--   import.students   → import TSV Pronote
--   import.photos     → import PDF trombinoscope
--   import.ics        → import emploi du temps ICS
--   classes.manage    → créer / modifier / supprimer classes
--   rooms.manage      → créer / modifier / supprimer salles
--   tags.manage       → créer / modifier / supprimer tags
--   admin.logs        → consulter les logs (lecture seule)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_permissions (
    user_id     INT          NOT NULL,
    permission  VARCHAR(50)  NOT NULL,
    PRIMARY KEY (user_id, permission),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tokens de réinitialisation de mot de passe
-- (optionnel si SMTP configuré dans config/app.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    token       VARCHAR(64)  NOT NULL,                   -- hash SHA-256 du token brut
    expires_at  TIMESTAMP    NOT NULL,                   -- durée de vie : 1 heure
    used_at     TIMESTAMP    NULL DEFAULT NULL,          -- NULL = pas encore utilisé
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Logs applicatifs
-- ------------------------------------------------------------
-- Niveaux :
--   info      → action normale (import OK, login OK, création séance…)
--   warning   → anomalie non bloquante (photo non extraite, ligne skippée…)
--   error     → erreur récupérée (exception catchée, fichier manquant…)
--   critical  → erreur grave non récupérée (500, BDD down…)
--
-- Catégories prévues :
--   auth      → login, logout, tentative échouée
--   import    → import Pronote, ICS, photos
--   class     → création/suppression classe, reset
--   session   → création/suppression séance
--   room      → création/suppression salle
--   tag       → création/suppression tag
--   admin     → gestion users, purge logs
--   system    → erreurs 500, exceptions non catchées
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_logs (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NULL DEFAULT NULL,          -- NULL si action système
    level       ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
    category    VARCHAR(50)  NOT NULL,
    action      VARCHAR(100) NOT NULL,
    details     JSON         NULL DEFAULT NULL,          -- contexte libre (nb lignes, erreurs…)
    ip          VARCHAR(45)  NULL DEFAULT NULL,          -- IPv4 ou IPv6
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_level    (level),
    KEY idx_logs_category (category),
    KEY idx_logs_created  (created_at),
    KEY idx_logs_user     (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Index de nettoyage automatique (purge manuelle via admin)
-- Aucun AUTO_DELETE en BDD — la purge est une action admin explicite.
-- ------------------------------------------------------------
