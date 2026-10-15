<?php
require_once '../../config/session.php';
require_once '../../config/database.php';

checkAuth();

$database = new Database();
$db = $database->getConnection();

$id_eleve = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Récupérer l'élève (avant toute sortie HTML, pour pouvoir rediriger)
$stmt = $db->prepare("SELECT e.*, c.nom_classe, c.niveau
                      FROM eleve e
                      LEFT JOIN classe c ON e.id_classe = c.id_classe
                      WHERE e.id_eleve = :id");
$stmt->execute([':id' => $id_eleve]);
$eleve = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eleve) {
    setFlash('warning', 'Élève introuvable.');
    header('Location: index.php');
    exit;
}

// Notes de l'élève avec matière et trimestre
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
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Regrouper par trimestre puis par matière
$trimestres = [];
foreach ($notes as $note) {
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

// Moyennes : par matière, puis générale pondérée par les coefficients (même calcul que le bulletin)
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

$age = date_diff(date_create($eleve['date_naiss']), date_create('today'))->y;

require_once '../../includes/header.php';
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi bi-person-vcard"></i></span>
                <?php echo htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']); ?>
            </h1>
            <p class="page-subtitle text-muted">Fiche de l'élève #<?php echo (int) $eleve['id_eleve']; ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="../notes/saisir.php?eleve_id=<?php echo (int) $eleve['id_eleve']; ?>" class="btn btn-dark">
                <i class="bi bi-plus-circle"></i> Saisir une note
            </a>
            <a href="modifier.php?id=<?php echo (int) $eleve['id_eleve']; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Informations -->
    <div class="col-lg-4">
        <div class="card voir-card">
            <div class="card-header"><i class="bi bi-person-circle"></i> Informations</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Date de naissance</span>
                    <span><?php echo date('d/m/Y', strtotime($eleve['date_naiss'])); ?> (<?php echo $age; ?> ans)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Sexe</span>
                    <span><?php echo $eleve['sexe'] === 'F' ? 'Féminin' : 'Masculin'; ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Classe</span>
                    <span>
                        <?php if ($eleve['id_classe'] && $eleve['nom_classe']): ?>
                            <a href="../classes/voir.php?id=<?php echo (int) $eleve['id_classe']; ?>">
                                <?php echo htmlspecialchars($eleve['nom_classe']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Non affecté</span>
                        <?php endif; ?>
                    </span>
                </li>
                <li class="list-group-item">
                    <span class="text-muted d-block">Adresse</span>
                    <span><?php echo $eleve['adresse'] ? nl2br(htmlspecialchars($eleve['adresse'])) : '<span class="text-muted">Non renseignée</span>'; ?></span>
                </li>
            </ul>
        </div>

        <div class="card voir-card mt-3">
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
                            <a href="../bulletins/imprimer.php?eleve_id=<?php echo (int) $eleve['id_eleve']; ?>&trimestre_id=<?php echo (int) $id_trimestre; ?>"
                               class="btn btn-sm btn-outline-secondary ms-2" title="Imprimer le bulletin" target="_blank">
                                <i class="bi bi-printer"></i>
                            </a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Notes par trimestre -->
    <div class="col-lg-8">
        <?php if (empty($trimestres)): ?>
            <div class="card voir-card">
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                    Aucune note pour cet élève.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($trimestres as $trimestre): ?>
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
        <?php endforeach; ?>
    </div>
</div>

<style>
.page-header { margin-bottom: 32px; }
.page-title { font-size: 1.65rem; font-weight: 700; display: flex; align-items: center; gap: 12px; margin: 0; }
.title-icon { width: 44px; height: 44px; border-radius: 12px; background: #111827; color: #fff;
              display: inline-flex; align-items: center; justify-content: center; font-size: 1.25rem; }
.page-subtitle { margin: 6px 0 0; }
.voir-card { border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; }
.voir-card .card-header { background: #f9fafb; font-weight: 600; }
.note-badge { font-size: .85rem; margin: 2px; padding: 5px 8px; }
.note-ok { background: #dcfce7; color: #166534; }
.note-ko { background: #fee2e2; color: #991b1b; }
</style>

<?php require_once '../../includes/footer.php'; ?>
