<?php
// ========================================
// CALCULS DU BULLETIN : moyennes pondérées et rang
// Un seul endroit pour ces calculs, utilisé par les bulletins (affichage et impression),
// la fiche classe et les outils de l'assistant IA : tous affichent donc les mêmes chiffres.
// ========================================

// Moyenne générale de chaque élève noté d'une classe : [id_eleve => moyenne], meilleure d'abord.
// Moyenne par matière, puis moyenne des matières pondérée par les coefficients.
// $id_trimestre = 0 : toute l'année.
function moyennesClasse($db, $id_classe, $id_trimestre = 0) {
    $sql = "SELECT o.id_eleve, n.valeur, m.nom_matiere, m.coef
            FROM eleve e
            JOIN obtenir o     ON o.id_eleve = e.id_eleve
            JOIN note n        ON n.id_note = o.id_note
            JOIN porter_sur ps ON ps.id_note = n.id_note
            JOIN matiere m     ON m.id_matiere = ps.id_matiere
            JOIN durant d      ON d.id_note = n.id_note
            WHERE e.id_classe = :id"
         . ($id_trimestre > 0 ? " AND d.id_trimestre = :tri" : "");
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $id_classe, PDO::PARAM_INT);
    if ($id_trimestre > 0) {
        $stmt->bindValue(':tri', $id_trimestre, PDO::PARAM_INT);
    }
    $stmt->execute();

    $notes_par_eleve = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notes_par_eleve[$row['id_eleve']][$row['nom_matiere']]['coef'] = $row['coef'];
        $notes_par_eleve[$row['id_eleve']][$row['nom_matiere']]['valeurs'][] = $row['valeur'];
    }

    $moyennes = [];
    foreach ($notes_par_eleve as $id_eleve => $matieres) {
        $somme = 0;
        $total_coef = 0;
        foreach ($matieres as $matiere) {
            $somme += (array_sum($matiere['valeurs']) / count($matiere['valeurs'])) * $matiere['coef'];
            $total_coef += $matiere['coef'];
        }
        if ($total_coef > 0) {
            $moyennes[$id_eleve] = $somme / $total_coef;
        }
    }
    arsort($moyennes);
    return $moyennes;
}

// Bulletin d'un élève pour un trimestre : fiche, notes, matières (total, count, coef, notes),
// moyenne générale, rang dans la classe et nombre d'élèves classés
function generateBulletin($db, $id_eleve, $id_trimestre) {
    $stmt = $db->prepare("SELECT e.*, c.nom_classe, c.niveau
                          FROM eleve e
                          JOIN classe c ON e.id_classe = c.id_classe
                          WHERE e.id_eleve = :id");
    $stmt->execute([':id' => $id_eleve]);
    $eleve = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT n.*, m.nom_matiere, m.coef
                          FROM note n
                          JOIN obtenir o ON n.id_note = o.id_note
                          JOIN porter_sur ps ON n.id_note = ps.id_note
                          JOIN matiere m ON ps.id_matiere = m.id_matiere
                          JOIN durant d ON n.id_note = d.id_note
                          WHERE o.id_eleve = :id_eleve AND d.id_trimestre = :id_trimestre
                          ORDER BY m.nom_matiere");
    $stmt->execute([':id_eleve' => $id_eleve, ':id_trimestre' => $id_trimestre]);
    $notes = $stmt->fetchAll();

    $matieres = [];
    foreach ($notes as $note) {
        $matiere = $note['nom_matiere'];
        if (!isset($matieres[$matiere])) {
            $matieres[$matiere] = ['total' => 0, 'count' => 0, 'coef' => $note['coef'], 'notes' => []];
        }
        $matieres[$matiere]['total'] += $note['valeur'];
        $matieres[$matiere]['count']++;
        $matieres[$matiere]['notes'][] = $note['valeur'];
    }

    $moyenne_generale = 0;
    $total_coef = 0;
    foreach ($matieres as $data) {
        $moyenne_generale += ($data['total'] / $data['count']) * $data['coef'];
        $total_coef += $data['coef'];
    }
    if ($total_coef > 0) {
        $moyenne_generale /= $total_coef;
    }

    // Rang : position de l'élève parmi les moyennes pondérées de sa classe pour ce trimestre
    $classement = $eleve ? array_keys(moyennesClasse($db, $eleve['id_classe'], $id_trimestre)) : [];
    $position = array_search((int) $id_eleve, array_map('intval', $classement), true);

    return [
        'eleve' => $eleve,
        'notes' => $notes,
        'matieres' => $matieres,
        'moyenne_generale' => $moyenne_generale,
        'rang' => $position === false ? null : $position + 1,
        'total_eleves_classe' => count($classement),
    ];
}
