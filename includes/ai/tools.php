<?php
// ========================================
// OUTILS DE L'ASSISTANT
// Le modèle ne touche jamais à la base : il demande l'exécution d'un outil, et c'est l'outil
// qui décide quelles données renvoyer, d'après la SESSION (rôle, id_eleve, enfants du parent,
// matières de l'enseignant). Un identifiant proposé par le modèle est toujours revérifié ici.
// Les calculs (moyennes, rangs) sont faits en PHP, avec les mêmes fonctions que les pages.
// Les droits sont ceux de l'application (cf. config/session.php) : l'assistant n'en donne pas plus.
// ========================================
require_once __DIR__ . '/../eleve_notes.php';
require_once __DIR__ . '/../bulletin.php';

// Outils disponibles pour le rôle $role : [nom => ['definition' => schéma JSON, 'run' => fonction]]
// Un outil peut renvoyer une clé '_action' : une action proposée à l'utilisateur (jamais exécutée par l'IA).
function assistantTools($db, $role) {
    $tools = [];
    $outil = function ($name, $description, array $properties, array $required, callable $run) use (&$tools) {
        $parameters = ['type' => 'object', 'properties' => $properties ?: new stdClass()];
        if ($required) {
            $parameters['required'] = $required;
        }
        $tools[$name] = [
            'definition' => ['name' => $name, 'description' => $description, 'parameters' => $parameters],
            'run' => $run,
        ];
    };
    $p_trimestre = ['type' => 'integer', 'description' => '1, 2 ou 3 (trimestre). Omettre pour toute l\'année.'];

    // ---------- Tous les rôles ----------
    $outil('aide_application',
        "Explique comment utiliser l'application Gestion des Notes (pages, actions possibles pour l'utilisateur connecté).",
        ['sujet' => ['type' => 'string', 'enum' => array_keys(aideSujets($role)), 'description' => 'Sujet de l\'aide']],
        ['sujet'],
        function (array $a) use ($role) {
            $sujets = aideSujets($role);
            $sujet = (string) ($a['sujet'] ?? '');
            return isset($sujets[$sujet]) ? ['sujet' => $sujet, 'aide' => $sujets[$sujet]]
                                          : ['erreur' => 'Sujet inconnu', 'sujets_disponibles' => array_keys($sujets)];
        });

    // ---------- Élève : uniquement SA fiche (identifiant de la session) ----------
    if ($role === ROLE_ELEVE && !empty($_SESSION['id_eleve'])) {
        $id_eleve = (int) $_SESSION['id_eleve'];

        $outil('mes_notes',
            "Notes de l'élève connecté, avec moyennes par matière et moyenne générale pondérée (déjà calculées : ne pas recalculer).",
            ['id_trimestre' => $p_trimestre], [],
            function (array $a) use ($db, $id_eleve) {
                return ['trimestres' => notesEleve($db, $id_eleve, $a['id_trimestre'] ?? null)];
            });

        $outil('mon_bulletin',
            "Bulletin de l'élève connecté pour un trimestre : moyenne générale, rang dans la classe, moyenne de chaque matière.",
            ['id_trimestre' => $p_trimestre], ['id_trimestre'],
            function (array $a) use ($db, $id_eleve) {
                return bulletinResume($db, $id_eleve, (int) ($a['id_trimestre'] ?? 0));
            });
    }

    // ---------- Parent : uniquement SES enfants (table parent_eleve) ----------
    if ($role === ROLE_PARENT) {
        $enfants = parentEleves($db);
        $refus = ['erreur' => "Cet élève ne fait pas partie de vos enfants : accès refusé."];
        $p_enfant = ['type' => 'integer', 'description' => 'id_eleve renvoyé par mes_enfants'];

        $outil('mes_enfants', "Liste des enfants rattachés au compte du parent connecté (id_eleve, prénom, classe).",
            [], [],
            function () use ($enfants) {
                return ['enfants' => array_values(array_map(function ($e) {
                    return ['id_eleve' => (int) $e['id_eleve'], 'prenom' => $e['prenom'], 'nom' => $e['nom'], 'classe' => $e['nom_classe']];
                }, $enfants))];
            });

        $outil('notes_enfant', "Notes et moyennes d'un enfant du parent connecté (moyennes déjà calculées).",
            ['id_eleve' => $p_enfant, 'id_trimestre' => $p_trimestre], ['id_eleve'],
            function (array $a) use ($db, $enfants, $refus) {
                $id = (int) ($a['id_eleve'] ?? 0);
                if (!isset($enfants[$id])) return $refus;
                return ['eleve' => $enfants[$id]['prenom'], 'trimestres' => notesEleve($db, $id, $a['id_trimestre'] ?? null)];
            });

        $outil('bulletin_enfant', "Bulletin d'un enfant du parent connecté pour un trimestre : moyenne générale, rang, moyennes par matière.",
            ['id_eleve' => $p_enfant, 'id_trimestre' => $p_trimestre], ['id_eleve', 'id_trimestre'],
            function (array $a) use ($db, $enfants, $refus) {
                $id = (int) ($a['id_eleve'] ?? 0);
                if (!isset($enfants[$id])) return $refus;
                return bulletinResume($db, $id, (int) ($a['id_trimestre'] ?? 0));
            });
    }

    // ---------- Équipe pédagogique (admin, directeur, enseignant) : mêmes données que les pages ----------
    if (in_array($role, ROLES_PEDAGOGIE, true)) {
        $p_classe = ['type' => 'integer', 'description' => 'id_classe renvoyé par liste_classes'];

        $outil('liste_classes', "Classes de l'établissement (id_classe, nom, niveau, nombre d'élèves).", [], [],
            function () use ($db) {
                return ['classes' => $db->query("SELECT c.id_classe, c.nom_classe, c.niveau, COUNT(e.id_eleve) AS nb_eleves
                                                 FROM classe c LEFT JOIN eleve e ON e.id_classe = c.id_classe
                                                 GROUP BY c.id_classe ORDER BY c.nom_classe")->fetchAll(PDO::FETCH_ASSOC)];
            });

        $outil('liste_matieres', "Matières (id_matiere, nom, coefficient)" . ($role === ROLE_ENSEIGNANT ? ", en indiquant celles de l'enseignant connecté." : "."),
            [], [],
            function () use ($db) {
                $mes = mesMatieres($db);
                return ['matieres' => array_map(function ($m) use ($mes) {
                    $m = ['id_matiere' => (int) $m['id_matiere'], 'matiere' => $m['nom_matiere'], 'coef' => (float) $m['coef']];
                    if (hasRole(ROLE_ENSEIGNANT)) $m['enseignee_par_moi'] = isset($mes[$m['id_matiere']]);
                    return $m;
                }, $db->query("SELECT id_matiere, nom_matiere, coef FROM matiere ORDER BY nom_matiere")->fetchAll(PDO::FETCH_ASSOC))];
            });

        $outil('rechercher_eleve', "Rechercher un élève par nom ou prénom (10 résultats au plus) pour obtenir son id_eleve.",
            ['texte' => ['type' => 'string', 'description' => 'Nom, prénom, ou les deux']], ['texte'],
            function (array $a) use ($db) {
                $texte = trim(mb_substr((string) ($a['texte'] ?? ''), 0, 50));
                if ($texte === '') return ['erreur' => 'Texte de recherche vide'];
                $stmt = $db->prepare("SELECT e.id_eleve, e.prenom, e.nom, c.nom_classe AS classe
                                      FROM eleve e LEFT JOIN classe c ON c.id_classe = e.id_classe
                                      WHERE e.nom LIKE :t OR e.prenom LIKE :t OR CONCAT(e.prenom, ' ', e.nom) LIKE :t
                                         OR CONCAT(e.nom, ' ', e.prenom) LIKE :t
                                      ORDER BY e.nom, e.prenom LIMIT 10");
                $stmt->execute([':t' => '%' . addcslashes($texte, '%_\\') . '%']);
                return ['eleves' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            });

        $outil('notes_eleve', "Notes et moyennes d'un élève (moyennes déjà calculées).",
            ['id_eleve' => ['type' => 'integer'], 'id_trimestre' => $p_trimestre], ['id_eleve'],
            function (array $a) use ($db) {
                $eleve = getEleve($db, (int) ($a['id_eleve'] ?? 0));
                if (!$eleve) return ['erreur' => 'Élève introuvable'];
                return ['eleve' => $eleve['prenom'] . ' ' . $eleve['nom'], 'classe' => $eleve['nom_classe'],
                        'trimestres' => notesEleve($db, (int) $eleve['id_eleve'], $a['id_trimestre'] ?? null)];
            });

        $outil('bulletin_eleve', "Bulletin d'un élève pour un trimestre : moyenne générale, rang dans la classe, moyennes par matière.",
            ['id_eleve' => ['type' => 'integer'], 'id_trimestre' => $p_trimestre], ['id_eleve', 'id_trimestre'],
            function (array $a) use ($db) {
                return bulletinResume($db, (int) ($a['id_eleve'] ?? 0), (int) ($a['id_trimestre'] ?? 0));
            });

        $outil('stats_classe', "Statistiques d'une classe : moyenne de la classe, plus haute et plus basse moyenne, nombre d'élèves au-dessus de 10, moyenne de la classe dans chaque matière.",
            ['id_classe' => $p_classe, 'id_trimestre' => $p_trimestre], ['id_classe'],
            function (array $a) use ($db) {
                return statsClasse($db, (int) ($a['id_classe'] ?? 0), (int) ($a['id_trimestre'] ?? 0));
            });

        $outil('classement_classe', "Classement des élèves d'une classe par moyenne générale pondérée (rang 1 = meilleure moyenne).",
            ['id_classe' => $p_classe, 'id_trimestre' => $p_trimestre], ['id_classe'],
            function (array $a) use ($db) {
                $id_classe = (int) ($a['id_classe'] ?? 0);
                $noms = nomsElevesClasse($db, $id_classe);
                if (!$noms) return ['erreur' => 'Classe introuvable ou sans élèves'];
                $rang = 0;
                $classement = [];
                foreach (moyennesClasse($db, $id_classe, (int) ($a['id_trimestre'] ?? 0)) as $id => $moy) {
                    $classement[] = ['rang' => ++$rang, 'eleve' => $noms[$id], 'moyenne' => round($moy, 2)];
                }
                return ['classement' => $classement];
            });

        $outil('eleves_en_difficulte', "Élèves d'une classe dont la moyenne (générale, ou dans une matière si id_matiere est donné) est inférieure au seuil.",
            ['id_classe' => $p_classe, 'id_trimestre' => $p_trimestre,
             'seuil' => ['type' => 'number', 'description' => 'Seuil sur 20 (10 par défaut)'],
             'id_matiere' => ['type' => 'integer', 'description' => 'id_matiere renvoyé par liste_matieres (facultatif)']],
            ['id_classe'],
            function (array $a) use ($db) {
                return elevesEnDifficulte($db, (int) ($a['id_classe'] ?? 0), (int) ($a['id_trimestre'] ?? 0),
                                          (float) ($a['seuil'] ?? 10), (int) ($a['id_matiere'] ?? 0));
            });

        // Action sensible : l'assistant PROPOSE, l'utilisateur vérifie et valide dans le formulaire existant
        $outil('proposer_saisie_note',
            "Préparer la saisie d'une note. La note n'est PAS enregistrée : l'utilisateur reçoit un bouton qui ouvre le formulaire "
          . "de saisie pré-rempli, qu'il doit vérifier et valider lui-même.",
            ['id_eleve' => ['type' => 'integer'], 'id_matiere' => ['type' => 'integer'], 'id_trimestre' => $p_trimestre,
             'valeur' => ['type' => 'number', 'description' => 'Note sur 20'],
             'type_note' => ['type' => 'string', 'enum' => ['Contrôle', 'Devoir', 'Composition', 'TP', 'Interrogation']]],
            ['id_eleve', 'id_matiere', 'id_trimestre', 'valeur'],
            function (array $a) use ($db) {
                return proposerSaisieNote($db, $a);
            });
    }

    // ---------- Direction (admin, directeur) : vue d'ensemble de l'établissement ----------
    if (in_array($role, ROLES_GESTION, true)) {
        $outil('stats_globales', "Vue d'ensemble de l'établissement : nombre total d'élèves et de classes ; pour chaque classe, nombre d'élèves, moyenne et taux d'élèves au-dessus de 10 ; nombre total de notes saisies.",
            ['id_trimestre' => $p_trimestre], [],
            function (array $a) use ($db) {
                return statsGlobales($db, (int) ($a['id_trimestre'] ?? 0));
            });

        $outil('matieres_sans_notes', "Pour un trimestre, les matières qui n'ont encore aucune note dans chaque classe.",
            ['id_trimestre' => $p_trimestre], ['id_trimestre'],
            function (array $a) use ($db) {
                return matieresSansNotes($db, (int) ($a['id_trimestre'] ?? 0));
            });
    }

    return $tools;
}

// ========================================
// FONCTIONS DES OUTILS
// ========================================

// Notes d'un élève, minimisées, éventuellement limitées à un trimestre
function notesEleve($db, $id_eleve, $id_trimestre = null) {
    $trimestres = getNotesParTrimestre($db, $id_eleve);
    if ($id_trimestre !== null && $id_trimestre !== '') {
        $t = (int) $id_trimestre;
        $trimestres = isset($trimestres[$t]) ? [$t => $trimestres[$t]] : [];
    }
    return minimiserNotes($trimestres);
}

// Ne transmettre au modèle que le nécessaire (minimisation des données) : moyennes arrondies,
// aucune donnée personnelle (date de naissance, adresse)
function minimiserNotes($trimestres) {
    $resultat = [];
    foreach ($trimestres as $id => $trimestre) {
        $matieres = [];
        foreach ($trimestre['matieres'] as $nom => $matiere) {
            $matieres[] = [
                'matiere' => $nom,
                'coef'    => (float) $matiere['coef'],
                'moyenne' => round($matiere['moyenne'], 2),
                'notes'   => array_map(function ($n) {
                    return ['valeur' => (float) $n['valeur'], 'type' => $n['type_note'], 'date' => $n['date_note']];
                }, $matiere['notes']),
            ];
        }
        $resultat[] = [
            'id_trimestre'     => (int) $id,
            'trimestre'        => $trimestre['libelle'],
            'moyenne_generale' => $trimestre['moyenne'] !== null ? round($trimestre['moyenne'], 2) : null,
            'matieres'         => $matieres,
        ];
    }
    return $resultat;
}

// Résumé du bulletin (même calcul que modules/bulletins), sans données personnelles
function bulletinResume($db, $id_eleve, $id_trimestre) {
    if ($id_trimestre < 1) return ['erreur' => 'id_trimestre obligatoire (1, 2 ou 3)'];
    $b = generateBulletin($db, $id_eleve, $id_trimestre);
    if (!$b['eleve']) return ['erreur' => 'Élève introuvable'];
    if (!$b['matieres']) return ['eleve' => $b['eleve']['prenom'], 'trimestre' => $id_trimestre, 'info' => 'Aucune note pour ce trimestre'];

    $matieres = [];
    foreach ($b['matieres'] as $nom => $m) {
        $matieres[] = ['matiere' => $nom, 'coef' => (float) $m['coef'], 'moyenne' => round($m['total'] / $m['count'], 2), 'nb_notes' => $m['count']];
    }
    return [
        'eleve'            => $b['eleve']['prenom'] . ' ' . $b['eleve']['nom'],
        'classe'           => $b['eleve']['nom_classe'],
        'trimestre'        => $id_trimestre,
        'moyenne_generale' => round($b['moyenne_generale'], 2),
        'rang'             => $b['rang'],
        'nb_eleves_classes' => $b['total_eleves_classe'],
        'matieres'         => $matieres,
    ];
}

// Matières affectées à l'enseignant connecté : [id_matiere => nom] (vide pour les autres rôles)
function mesMatieres($db) {
    if (!hasRole(ROLE_ENSEIGNANT) || empty($_SESSION['id_enseignant'])) return [];
    $stmt = $db->prepare("SELECT m.id_matiere, m.nom_matiere FROM enseigner en JOIN matiere m ON m.id_matiere = en.id_matiere
                          WHERE en.id_enseignant = :ens");
    $stmt->execute([':ens' => (int) $_SESSION['id_enseignant']]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Prénom et nom des élèves d'une classe : [id_eleve => "Prénom Nom"]
function nomsElevesClasse($db, $id_classe) {
    $stmt = $db->prepare("SELECT id_eleve, CONCAT(prenom, ' ', nom) FROM eleve WHERE id_classe = :id");
    $stmt->execute([':id' => $id_classe]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Moyenne de chaque élève de la classe dans chaque matière : [id_matiere => ['matiere', 'moyennes' => [id_eleve => moy]]]
function moyennesMatieresClasse($db, $id_classe, $id_trimestre) {
    $sql = "SELECT o.id_eleve, m.id_matiere, m.nom_matiere, AVG(n.valeur) AS moyenne
            FROM eleve e
            JOIN obtenir o     ON o.id_eleve = e.id_eleve
            JOIN note n        ON n.id_note = o.id_note
            JOIN porter_sur ps ON ps.id_note = n.id_note
            JOIN matiere m     ON m.id_matiere = ps.id_matiere
            JOIN durant d      ON d.id_note = n.id_note
            WHERE e.id_classe = :id" . ($id_trimestre > 0 ? " AND d.id_trimestre = :tri" : "") . "
            GROUP BY o.id_eleve, m.id_matiere";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $id_classe, PDO::PARAM_INT);
    if ($id_trimestre > 0) $stmt->bindValue(':tri', $id_trimestre, PDO::PARAM_INT);
    $stmt->execute();
    $resultat = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resultat[$row['id_matiere']]['matiere'] = $row['nom_matiere'];
        $resultat[$row['id_matiere']]['moyennes'][$row['id_eleve']] = (float) $row['moyenne'];
    }
    return $resultat;
}

function statsClasse($db, $id_classe, $id_trimestre) {
    $stmt = $db->prepare("SELECT nom_classe, niveau FROM classe WHERE id_classe = :id");
    $stmt->execute([':id' => $id_classe]);
    $classe = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$classe) return ['erreur' => 'Classe introuvable'];

    $moyennes = moyennesClasse($db, $id_classe, $id_trimestre);
    $par_matiere = [];
    foreach (moyennesMatieresClasse($db, $id_classe, $id_trimestre) as $m) {
        $par_matiere[] = ['matiere' => $m['matiere'], 'moyenne_classe' => round(array_sum($m['moyennes']) / count($m['moyennes']), 2),
                          'nb_eleves_sous_10' => count(array_filter($m['moyennes'], fn($v) => $v < 10))];
    }
    return [
        'classe'            => $classe['nom_classe'],
        'periode'           => $id_trimestre > 0 ? "trimestre $id_trimestre" : 'année',
        'nb_eleves'         => count(nomsElevesClasse($db, $id_classe)),
        'nb_eleves_notes'   => count($moyennes),
        'moyenne_classe'    => $moyennes ? round(array_sum($moyennes) / count($moyennes), 2) : null,
        'meilleure_moyenne' => $moyennes ? round(max($moyennes), 2) : null,
        'plus_basse_moyenne' => $moyennes ? round(min($moyennes), 2) : null,
        'nb_au_dessus_de_10' => count(array_filter($moyennes, fn($v) => $v >= 10)),
        'matieres'          => $par_matiere,
    ];
}

function elevesEnDifficulte($db, $id_classe, $id_trimestre, $seuil, $id_matiere) {
    $noms = nomsElevesClasse($db, $id_classe);
    if (!$noms) return ['erreur' => 'Classe introuvable ou sans élèves'];
    $seuil = max(0, min(20, $seuil));

    if ($id_matiere > 0) {
        $matieres = moyennesMatieresClasse($db, $id_classe, $id_trimestre);
        if (!isset($matieres[$id_matiere])) return ['info' => 'Aucune note dans cette matière pour cette classe'];
        $moyennes = $matieres[$id_matiere]['moyennes'];
        $critere = 'moyenne en ' . $matieres[$id_matiere]['matiere'];
    } else {
        $moyennes = moyennesClasse($db, $id_classe, $id_trimestre);
        $critere = 'moyenne générale';
    }
    asort($moyennes);
    $eleves = [];
    foreach ($moyennes as $id => $moy) {
        if ($moy < $seuil) $eleves[] = ['eleve' => $noms[$id], 'moyenne' => round($moy, 2)];
    }
    return ['critere' => $critere, 'seuil' => $seuil, 'nb' => count($eleves), 'eleves' => $eleves];
}

function proposerSaisieNote($db, array $a) {
    $valeur = $a['valeur'] ?? null;
    if (!is_numeric($valeur) || $valeur < 0 || $valeur > 20) return ['erreur' => 'La note doit être comprise entre 0 et 20'];
    $eleve = getEleve($db, (int) ($a['id_eleve'] ?? 0));
    if (!$eleve) return ['erreur' => 'Élève introuvable'];
    $stmt = $db->prepare("SELECT nom_matiere FROM matiere WHERE id_matiere = :id");
    $stmt->execute([':id' => (int) ($a['id_matiere'] ?? 0)]);
    $matiere = $stmt->fetchColumn();
    if (!$matiere) return ['erreur' => 'Matière introuvable'];
    if (hasRole(ROLE_ENSEIGNANT) && !isset(mesMatieres($db)[(int) $a['id_matiere']])) {
        return ['erreur' => "Vous n'enseignez pas cette matière : saisie impossible."];
    }
    $stmt = $db->prepare("SELECT libelle FROM trimestre WHERE id_trimestre = :id");
    $stmt->execute([':id' => (int) ($a['id_trimestre'] ?? 0)]);
    $trimestre = $stmt->fetchColumn();
    if (!$trimestre) return ['erreur' => 'Trimestre introuvable'];
    $type = in_array($a['type_note'] ?? '', ['Contrôle', 'Devoir', 'Composition', 'TP', 'Interrogation'], true) ? $a['type_note'] : 'Contrôle';

    $libelle = sprintf('Saisir %s/20 (%s) en %s pour %s %s — %s', rtrim(rtrim(number_format((float) $valeur, 2, '.', ''), '0'), '.'),
                       $type, $matiere, $eleve['prenom'], $eleve['nom'], $trimestre);
    return [
        'statut' => "Proposition préparée, NON enregistrée : l'utilisateur doit cliquer sur le bouton, vérifier le formulaire et le valider.",
        'proposition' => $libelle,
        '_action' => [
            'type'    => 'saisie_note',
            'libelle' => $libelle,
            'url'     => 'modules/notes/saisir.php?' . http_build_query([
                'eleve_id' => (int) $eleve['id_eleve'], 'matiere_id' => (int) $a['id_matiere'],
                'trimestre_id' => (int) $a['id_trimestre'], 'valeur' => (float) $valeur, 'type_note' => $type]),
        ],
    ];
}

function statsGlobales($db, $id_trimestre) {
    $classes = [];
    foreach ($db->query("SELECT id_classe, nom_classe FROM classe ORDER BY nom_classe")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $moyennes = moyennesClasse($db, (int) $c['id_classe'], $id_trimestre);
        $classes[] = [
            'classe' => $c['nom_classe'],
            'nb_eleves' => count(nomsElevesClasse($db, (int) $c['id_classe'])),
            'moyenne_classe' => $moyennes ? round(array_sum($moyennes) / count($moyennes), 2) : null,
            'taux_au_dessus_de_10' => $moyennes ? round(100 * count(array_filter($moyennes, fn($v) => $v >= 10)) / count($moyennes)) . ' %' : null,
        ];
    }
    $sql = "SELECT COUNT(*) FROM durant" . ($id_trimestre > 0 ? " WHERE id_trimestre = " . (int) $id_trimestre : "");
    // Totaux calculés ici : un petit modèle additionne mal, ou confond avec nb_notes
    return ['periode' => $id_trimestre > 0 ? "trimestre $id_trimestre" : 'année',
            'nb_eleves_total' => array_sum(array_column($classes, 'nb_eleves')), 'nb_classes' => count($classes),
            'nb_notes' => (int) $db->query($sql)->fetchColumn(), 'classes' => $classes];
}

function matieresSansNotes($db, $id_trimestre) {
    if ($id_trimestre < 1) return ['erreur' => 'id_trimestre obligatoire'];
    $stmt = $db->prepare("SELECT c.nom_classe, m.nom_matiere
                          FROM classe c CROSS JOIN matiere m
                          WHERE NOT EXISTS (
                              SELECT 1 FROM eleve e
                              JOIN obtenir o     ON o.id_eleve = e.id_eleve
                              JOIN porter_sur ps ON ps.id_note = o.id_note
                              JOIN durant d      ON d.id_note = o.id_note
                              WHERE e.id_classe = c.id_classe AND ps.id_matiere = m.id_matiere AND d.id_trimestre = :tri)
                          ORDER BY c.nom_classe, m.nom_matiere");
    $stmt->execute([':tri' => $id_trimestre]);
    $resultat = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resultat[$row['nom_classe']][] = $row['nom_matiere'];
    }
    return ['trimestre' => $id_trimestre, 'matieres_sans_notes_par_classe' => $resultat ?: 'Toutes les matières ont des notes dans toutes les classes'];
}

// Textes d'aide, selon ce que le rôle a le droit de faire (cf. config/session.php)
function aideSujets($role) {
    $sujets = [
        'connexion' => "La session se ferme après quelques minutes d'inactivité ; un avertissement permet de la prolonger. "
                     . "Le mot de passe se change dans « Mon profil » (menu en haut à droite), en saisissant d'abord l'actuel.",
    ];

    if ($role === ROLE_ELEVE) {
        $sujets['notes'] = "La page « Mes notes » affiche vos notes par trimestre et par matière, avec vos moyennes.";
        $sujets['bulletin'] = "Dans « Mes notes », le bouton imprimante à côté de chaque trimestre ouvre votre bulletin.";
    } elseif ($role === ROLE_PARENT) {
        $sujets['notes'] = "La page « Mes enfants » affiche les notes et moyennes de chacun de vos enfants.";
        $sujets['bulletin'] = "Dans « Mes enfants », le bouton imprimante à côté de chaque trimestre ouvre le bulletin.";
    }

    if (in_array($role, ROLES_PEDAGOGIE, true)) {
        $sujets['saisie_notes'] = "Menu Notes > Saisir des notes : choisir l'élève, la matière, le trimestre, puis la valeur sur 20 et le type. "
                                . ($role === ROLE_ENSEIGNANT ? "Seules vos matières sont proposées, et la note est enregistrée à votre nom." : "");
        $sujets['modifier_supprimer_note'] = $role === ROLE_ENSEIGNANT
            ? "Menu Notes > Gestion des notes : boutons crayon (modifier la valeur, le type, la date ou le trimestre) et corbeille (supprimer), uniquement sur les notes que vous avez saisies."
            : "Menu Notes > Gestion des notes : la direction peut modifier (crayon) ou supprimer (corbeille) toutes les notes.";
        $sujets['bulletin'] = "Menu Bulletins : choisir la classe et le trimestre pour afficher et imprimer les bulletins.";
    }

    if (in_array($role, ROLES_GESTION, true)) {
        $sujets['gestion'] = "Menus Élèves, Classes, Matières et Enseignants : ajouter, modifier ou supprimer. "
                           . "Menu Matières > Trimestres : périodes de notation. Une classe qui contient des élèves, une matière ou un trimestre qui a des notes ne peuvent pas être supprimés.";
    }

    if (in_array($role, ROLES_ADMIN, true)) {
        $sujets['comptes'] = "Menu Comptes : créer un compte, choisir son rôle, le relier à une fiche élève ou enseignant, "
                           . "et le désactiver plutôt que le supprimer. Menu Supervision IA : suivi de l'assistant.";
    }

    return $sujets;
}
