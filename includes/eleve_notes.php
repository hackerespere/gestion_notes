<?php
// ========================================
// NOTES D'UN ÉLÈVE : lecture et affichage
// Utilisé par la fiche élève (modules/eleves/voir.php)
// et par l'espace élève (modules/espace_eleve/index.php).
// ========================================

// Informations de l'élève avec sa classe (false si introuvable)
function getEleve($db, $id_eleve) {
    $stmt = $db->prepare("SELECT e.*, c.nom_classe, c.niveau
                          FROM eleve e
                          LEFT JOIN classe c ON e.id_classe = c.id_classe
                          WHERE e.id_eleve = :id");
    $stmt->execute([':id' => $id_eleve]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Notes regroupées par trimestre puis par matière, avec les moyennes
// (par matière, puis générale pondérée par les coefficients : même calcul que le bulletin)
function getNotesParTrimestre($db, $id_eleve) {
    $stmt = $db->prepare("SELECT n.id_note, n.valeur, n.type_note, n.date_note,
                                 m.nom_matiere, m.coef, t.id_trimestre, t.libelle
                          FROM obtenir o
                          JOIN note n        ON n.id_note = o.id_note
                          JOIN porter_sur ps ON ps.id_note = n.id_note
                          JOIN matiere m     ON m.id_matiere = ps.id_matiere
                          JOIN durant d      ON d.id_note = n.id_note
                          JOIN trimestre t   ON t.id_trimestre = d.id_trimestre
                          WHERE o.id_eleve = :id
                          ORDER BY t.id_trimestre, m.nom_matiere, n.date_note");
    $stmt->execute([':id' => $id_eleve]);

    $trimestres = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
        $t = $note['id_trimestre'];
        $m = $note['nom_matiere'];
        if (!isset($trimestres[$t])) {
            $trimestres[$t] = ['libelle' => $note['libelle'], 'matieres' => []];
        }
        if (!isset($trimestres[$t]['matieres'][$m])) {
            $trimestres[$t]['matieres'][$m] = ['coef' => $note['coef'], 'notes' => []];
        }
        $trimestres[$t]['matieres'][$m]['notes'][] = $note;
    }

    foreach ($trimestres as $t => $trimestre) {
        $somme = 0;
        $total_coef = 0;
        foreach ($trimestre['matieres'] as $m => $matiere) {
            $valeurs = array_column($matiere['notes'], 'valeur');
            $moy = array_sum($valeurs) / count($valeurs);
            $trimestres[$t]['matieres'][$m]['moyenne'] = $moy;
            $somme += $moy * $matiere['coef'];
            $total_coef += $matiere['coef'];
        }
        $trimestres[$t]['moyenne'] = $total_coef > 0 ? $somme / $total_coef : null;
    }

    return $trimestres;
}

// Carte « Moyennes générales » avec un lien d'impression du bulletin par trimestre
function renderMoyennesCard($trimestres, $id_eleve, $bulletin_url) {
    ?>
    <div class="card voir-card">
        <div class="card-header"><i class="bi bi-graph-up"></i> Moyennes générales</div>
        <ul class="list-group list-group-flush">
            <?php if (empty($trimestres)): ?>
                <li class="list-group-item text-muted">Aucune note enregistrée</li>
            <?php endif; ?>
            <?php foreach ($trimestres as $id_trimestre => $trimestre): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><?php echo htmlspecialchars($trimestre['libelle']); ?></span>
                    <span>
                        <span class="badge <?php echo $trimestre['moyenne'] >= 10 ? 'bg-success' : 'bg-danger'; ?>">
                            <?php echo number_format($trimestre['moyenne'], 2); ?>/20
                        </span>
                        <a href="<?php echo htmlspecialchars($bulletin_url); ?>?eleve_id=<?php echo (int) $id_eleve; ?>&trimestre_id=<?php echo (int) $id_trimestre; ?>"
                           class="btn btn-sm btn-outline-secondary ms-2" title="Imprimer le bulletin" target="_blank">
                            <i class="bi bi-printer"></i>
                        </a>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

// Une carte par trimestre : notes et moyenne de chaque matière
function renderNotesTrimestres($trimestres, $message_vide) {
    if (empty($trimestres)): ?>
        <div class="card voir-card">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                <?php echo htmlspecialchars($message_vide); ?>
            </div>
        </div>
    <?php endif;

    foreach ($trimestres as $trimestre): ?>
        <div class="card voir-card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($trimestre['libelle']); ?></span>
                <strong>Moyenne : <?php echo number_format($trimestre['moyenne'], 2); ?>/20</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Matière</th>
                            <th>Coef</th>
                            <th>Notes</th>
                            <th class="text-end">Moyenne</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trimestre['matieres'] as $nom_matiere => $matiere): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($nom_matiere); ?></td>
                                <td><?php echo htmlspecialchars($matiere['coef']); ?></td>
                                <td>
                                    <?php foreach ($matiere['notes'] as $note): ?>
                                        <span class="badge note-badge <?php echo $note['valeur'] >= 10 ? 'note-ok' : 'note-ko'; ?>"
                                              title="<?php echo htmlspecialchars($note['type_note']) . ' — ' . date('d/m/Y', strtotime($note['date_note'])); ?>">
                                            <?php echo htmlspecialchars($note['valeur']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-end fw-semibold <?php echo $matiere['moyenne'] >= 10 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format($matiere['moyenne'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach;
}
