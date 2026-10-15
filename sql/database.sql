-- Création de la base de données
CREATE DATABASE IF NOT EXISTS gestion_notes;
USE gestion_notes;

-- Table CLASSE
CREATE TABLE classe (
    id_classe INT PRIMARY KEY AUTO_INCREMENT,
    nom_classe VARCHAR(50) NOT NULL,
    niveau VARCHAR(20) NOT NULL,
    anne_scolaire VARCHAR(20) NOT NULL,
    description TEXT NULL
);

-- Table ELEVE
CREATE TABLE eleve (
    id_eleve INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    date_naiss DATE NOT NULL,
    sexe ENUM('M', 'F') NOT NULL,
    adresse VARCHAR(200),
    id_classe INT
);

-- Table MATIERE
CREATE TABLE matiere (
    id_matiere INT PRIMARY KEY AUTO_INCREMENT,
    nom_matiere VARCHAR(100) NOT NULL,
    coef DECIMAL(3,1) NOT NULL DEFAULT 1.0
);

-- Table ENSEIGNANT
CREATE TABLE enseignant (
    id_enseignant INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    grade VARCHAR(50)
);

-- Table TRIMESTRE
CREATE TABLE trimestre (
    id_trimestre INT PRIMARY KEY AUTO_INCREMENT,
    libelle VARCHAR(20) NOT NULL,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL
);

-- Table NOTE
CREATE TABLE note (
    id_note INT PRIMARY KEY AUTO_INCREMENT,
    valeur DECIMAL(4,2) NOT NULL,
    type_note VARCHAR(20) NOT NULL,
    date_note DATE NOT NULL
);

-- Table ENSEIGNER (relation entre enseignant et matiere)
CREATE TABLE enseigner (
    id_enseignant INT,
    id_matiere INT,
    PRIMARY KEY (id_enseignant, id_matiere)
);

-- Table PORTER_SUR (relation entre note et matiere)
CREATE TABLE porter_sur (
    id_note INT,
    id_matiere INT,
    PRIMARY KEY (id_note, id_matiere)
);

-- Table OBTENIR (relation entre eleve et note)
CREATE TABLE obtenir (
    id_eleve INT,
    id_note INT,
    PRIMARY KEY (id_eleve, id_note)
);

-- Table DURANT (relation entre trimestre et note)
CREATE TABLE durant (
    id_trimestre INT,
    id_note INT,
    PRIMARY KEY (id_trimestre, id_note)
);

-- Table SAISIR (relation entre enseignant et note)
CREATE TABLE saisir (
    id_note INT,
    id_enseignant INT,
    PRIMARY KEY (id_note, id_enseignant)

);

-- Table USERS (comptes de connexion, utilisée par login.php)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'directeur', 'enseignant', 'parent', 'user') NOT NULL DEFAULT 'user',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insertion de données de test
INSERT INTO trimestre (libelle, date_debut, date_fin) VALUES 
('1er Trimestre', '2025-09-01', '2025-12-15'),
('2ème Trimestre', '2026-01-05', '2026-04-10'),
('3ème Trimestre', '2026-04-15', '2026-06-30');

INSERT INTO classe (nom_classe, niveau, anne_scolaire) VALUES 
('6ème A', '6ème', '2025-2026'),
('5ème B', '5ème', '2025-2026'),
('4ème C', '4ème', '2025-2026');

INSERT INTO matiere (nom_matiere, coef) VALUES 
('Mathématiques', 4),
('Français', 4),
('Anglais', 2),
('SVT', 2),
('Histoire-Géographie', 2),
('Physique-Chimie', 3);

INSERT INTO enseignant (nom, prenom, grade) VALUES 
('Dupont', 'Jean', 'Professeur Principal'),
('Martin', 'Sophie', 'Professeur'),
('Bernard', 'Pierre', 'Professeur');