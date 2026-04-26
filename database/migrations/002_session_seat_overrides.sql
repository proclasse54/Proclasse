-- Migration 002 : surcharges de placement par séance
-- Permet de modifier les places pour une séance spécifique
-- sans toucher au plan de référence (seating_assignments).
--
-- scope=session → écrit ici uniquement
-- scope=plan    → écrit dans seating_assignments + purge les overrides futurs
--
-- student_id NULL = siège explicitement vide pour cette séance (élève absent/retiré)

CREATE TABLE IF NOT EXISTS session_seat_overrides (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    seat_id    INT NOT NULL,
    student_id INT          DEFAULT NULL,  -- NULL = siège vide pour cette séance
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE  KEY uq_session_seat (session_id, seat_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (seat_id)    REFERENCES seats(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
