-- ============================================================================
-- Tirage — KDP Studio : schéma MySQL
-- Import : phpMyAdmin > votre base > Importer > ce fichier
-- (ou laissez public/setup.php créer les tables automatiquement)
-- ============================================================================

SET NAMES utf8mb4;

-- Réglages éditables depuis l'interface (écran « Connecteurs » : clés API…)
CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(64) NOT NULL,
    value      TEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Utilisateurs (mono-utilisateur par défaut, la table reste extensible)
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100) NOT NULL DEFAULT '',
    created_at    DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jetons d'API (utilisés par le userscript de remplissage KDP)
CREATE TABLE IF NOT EXISTS api_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    token        CHAR(64) NOT NULL,
    label        VARCHAR(100) NOT NULL DEFAULT 'Userscript KDP',
    created_at   DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_token (token),
    KEY idx_tokens_user (user_id),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projets de livre (un projet = un livre, avancement par étapes 1..7)
CREATE TABLE IF NOT EXISTS projects (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    title          VARCHAR(255) NOT NULL DEFAULT 'Nouveau livre',
    step           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    mode           ENUM('describe','trends') NOT NULL DEFAULT 'describe',
    idea           TEXT NULL,
    theme_id       INT UNSIGNED NULL,
    concept_id     INT UNSIGNED NULL,
    pages          SMALLINT UNSIGNED NOT NULL DEFAULT 180,
    photos         TINYINT(1) NOT NULL DEFAULT 0,
    photos_per     TINYINT UNSIGNED NOT NULL DEFAULT 2,
    photo_style    ENUM('nb','couleur','schemas') NOT NULL DEFAULT 'nb',
    tone           VARCHAR(50) NOT NULL DEFAULT 'Pratique et direct',
    trim_format    VARCHAR(8) NOT NULL DEFAULT '6x9',
    toc_json       MEDIUMTEXT NULL,
    writing_status ENUM('idle','running','paused','done') NOT NULL DEFAULT 'idle',
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_projects_user (user_id),
    CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Étape 1 : thématiques proposées (analyse d'idée ou tendances Amazon)
CREATE TABLE IF NOT EXISTS themes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id   INT UNSIGNED NOT NULL,
    source       ENUM('analysis','trends') NOT NULL,
    position     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    name         VARCHAR(190) NOT NULL,
    category     VARCHAR(190) NOT NULL DEFAULT '',
    score        VARCHAR(8) NOT NULL DEFAULT '',
    why          TEXT NULL,
    demand       TINYINT UNSIGNED NOT NULL DEFAULT 50,
    competition  VARCHAR(30) NOT NULL DEFAULT 'Moyenne',
    price_median VARCHAR(20) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_themes_project (project_id),
    CONSTRAINT fk_themes_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Étape 2 : 8 concepts de livres pour le thème choisi
CREATE TABLE IF NOT EXISTS concepts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id  INT UNSIGNED NOT NULL,
    position    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    title       VARCHAR(255) NOT NULL,
    short_title VARCHAR(120) NOT NULL DEFAULT '',
    hook        VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    badge       VARCHAR(60) NOT NULL DEFAULT '',
    competition VARCHAR(30) NOT NULL DEFAULT 'Moyenne',
    price       VARCHAR(20) NOT NULL DEFAULT '',
    pages_est   SMALLINT UNSIGNED NOT NULL DEFAULT 180,
    PRIMARY KEY (id),
    KEY idx_concepts_project (project_id),
    CONSTRAINT fk_concepts_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Étape 3 validée : chapitres réels du livre
CREATE TABLE IF NOT EXISTS chapters (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id   INT UNSIGNED NOT NULL,
    num          TINYINT UNSIGNED NOT NULL,
    title        VARCHAR(255) NOT NULL,
    target_words INT UNSIGNED NOT NULL DEFAULT 0,
    status       ENUM('wait','writing','done') NOT NULL DEFAULT 'wait',
    PRIMARY KEY (id),
    UNIQUE KEY uq_chapters (project_id, num),
    CONSTRAINT fk_chapters_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sections de chapitre = unité de rédaction et point de contrôle (reprise)
CREATE TABLE IF NOT EXISTS sections (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chapter_id INT UNSIGNED NOT NULL,
    num        TINYINT UNSIGNED NOT NULL,
    title      VARCHAR(255) NOT NULL,
    status     ENUM('wait','writing','done') NOT NULL DEFAULT 'wait',
    content    MEDIUMTEXT NULL,
    words      INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sections (chapter_id, num),
    CONSTRAINT fk_sections_chapter FOREIGN KEY (chapter_id) REFERENCES chapters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal en direct de la rédaction (étape 5)
CREATE TABLE IF NOT EXISTS journal (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    level      ENUM('ok','dim','warn','err') NOT NULL DEFAULT 'ok',
    message    VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_journal_project (project_id, id),
    CONSTRAINT fk_journal_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emplacements visuels réservés (photos & illustrations)
CREATE TABLE IF NOT EXISTS images (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id  INT UNSIGNED NOT NULL,
    chapter_num TINYINT UNSIGNED NOT NULL,
    slot        TINYINT UNSIGNED NOT NULL,
    caption     VARCHAR(400) NOT NULL DEFAULT '',
    spec        VARCHAR(200) NOT NULL DEFAULT '',
    filename    VARCHAR(255) NULL,
    width       INT UNSIGNED NULL,
    height      INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_images (project_id, chapter_num, slot),
    CONSTRAINT fk_images_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Étape 4 : couverture (1ère + 4ème), gabarit flat design + contenus
CREATE TABLE IF NOT EXISTS covers (
    project_id  INT UNSIGNED NOT NULL,
    template    VARCHAR(100) NOT NULL DEFAULT 'editorial',
    palette     TEXT NULL,          -- JSON : {"c1":"#1B2A4A","c2":"#C4571F","c3":"#F4EFE4","c4":"#1A1A17"} + layout/motif
    texts       MEDIUMTEXT NULL,    -- JSON : title, subtitle, tagline, author, back_text, bio, illus_prompt
    layout_json MEDIUMTEXT NULL,    -- JSON : éléments de l'éditeur de couverture
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (project_id),
    CONSTRAINT fk_covers_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Veille marché Amazon (tableau de bord Canopy — relevés manuels au clic)
CREATE TABLE IF NOT EXISTS watch_terms (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    term       VARCHAR(120) NOT NULL,
    position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    snapshot   MEDIUMTEXT NULL,
    updated_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_watch_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watch_history (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    watch_id   INT UNSIGNED NOT NULL,
    snapshot   MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_history_watch (watch_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Métadonnées de publication Amazon KDP (remplissage auto du formulaire)
CREATE TABLE IF NOT EXISTS kdp_meta (
    project_id       INT UNSIGNED NOT NULL,
    subtitle         VARCHAR(255) NOT NULL DEFAULT '',
    author_first     VARCHAR(100) NOT NULL DEFAULT '',
    author_last      VARCHAR(100) NOT NULL DEFAULT '',
    description_html MEDIUMTEXT NULL,
    keywords         TEXT NULL,    -- JSON : 7 chaînes max
    categories       TEXT NULL,    -- JSON : 3 chaînes max
    price            DECIMAL(6,2) NULL,
    isbn             VARCHAR(20) NOT NULL DEFAULT '',
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (project_id),
    CONSTRAINT fk_kdp_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
