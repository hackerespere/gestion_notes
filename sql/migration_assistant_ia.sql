-- Mise à jour d'une base existante pour l'assistant IA (tables déjà incluses dans database.sql)
USE gestion_notes;

-- Table AI_LOGS (journal de l'assistant IA : supervision, quotas, coûts, avis)
-- Le texte des questions et des réponses n'est pas conservé (minimisation des données)
CREATE TABLE IF NOT EXISTS ai_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_user INT NOT NULL,
    role VARCHAR(20) NOT NULL,
    statut ENUM('ok', 'cache', 'secours', 'erreur', 'limite') NOT NULL,
    fournisseur VARCHAR(30) NULL,
    modele VARCHAR(100) NULL,
    echecs VARCHAR(255) NULL,          -- fournisseurs en échec avant la réponse (ex. "groq:timeout")
    outils VARCHAR(255) NULL,
    latence_ms INT NOT NULL DEFAULT 0,
    prompt_tokens INT NOT NULL DEFAULT 0,
    completion_tokens INT NOT NULL DEFAULT 0,
    cout DECIMAL(10,6) NOT NULL DEFAULT 0,   -- en dollars, d'après les tarifs du .env
    avis TINYINT NULL,                 -- 1 utile, -1 pas utile
    signale TINYINT NOT NULL DEFAULT 0,      -- réponse signalée pour relecture humaine
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (id_user, created_at),
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE
);

-- Table AI_CACHE (réponses récentes, par utilisateur : resservies si la même question revient,
-- ou en secours si tous les fournisseurs sont indisponibles)
CREATE TABLE IF NOT EXISTS ai_cache (
    cle CHAR(64) PRIMARY KEY,
    id_user INT NOT NULL,
    reponse TEXT NOT NULL,
    fournisseur VARCHAR(30) NOT NULL,
    modele VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE
);

