<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/bulletin.php';

requireRole(ROLES_PEDAGOGIE);

$database = new Database();
$db = $database->getConnection();

$id_classe = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$id_trimestre = isset($_GET['trimestre']) ? (int) $_GET['trimestre'] : 0;

// Récupérer la classe (avant toute sortie HTML, pour pouvoir rediriger)
$stmt = $db->prepare("SELECT * FROM classe WHERE id_classe = :id");
$stmt->execute([':id' => $id_classe]);
$classe = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$classe) {
    setFlash('warning', 'Classe introuvable.');
    header('Location: index.php');
    exit;
}

$trimestres = $db->query("SELECT id_trimestre, libelle FROM trimestre ORDER BY id_trimestre")->fetchAll(PDO::FETCH_ASSOC);

// Élèves de la classe
$stmt = $db->prepare("SELECT id_eleve, nom, prenom, sexe, date_naiss
                      FROM eleve WHERE id_classe = :id ORDER BY nom, prenom");
$stmt->execute([':id' => $id_classe]);
$eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Moyenne générale de chaque élève (calcul commun au bulletin), filtrée par trimestre si choisi
$moyennes = moyennesClasse($db, $id_classe, $id_trimestre);

// Nombre de notes de chaque élève
$sql = "SELECT o.id_eleve, COUNT(*) AS nb
        FROM eleve e
        JOIN obtenir o ON o.id_eleve = e.id_eleve
        JOIN durant d  ON d.id_note = o.id_note
        WHERE e.id_classe = :id"
     . ($id_trimestre > 0 ? " AND d.id_trimestre = :tri" : "")
     . " GROUP BY o.id_eleve";
$stmt = $db->prepare($sql);
$stmt->bindValue(':id', $id_classe, PDO::PARAM_INT);
if ($id_trimestre > 0) {
    $stmt->bindValue(':tri', $id_trimestre, PDO::PARAM_INT);
}
$stmt->execute();
$nb_notes = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

// Statistiques de la classe
$nb_eleves = count($eleves);
$nb_filles = count(array_filter($eleves, fn($e) => $e['sexe'] === 'F'));
$moyenne_classe = count($moyennes) > 0 ? array_sum($moyennes) / count($moyennes) : null;
$nb_admis = count(array_filter($moyennes, fn($m) => $m >= 10));

// Rang des élèves notés (meilleure moyenne = 1)
$classement = $moyennes;
arsort($classement);
$rangs = array_flip(array_keys($classement));

require_once '../../includes/header.php';
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi bi-building"></i></span>
                <?php echo htmlspecialchars($classe['nom_classe']); ?>
            </h1>
            <p class="page-subtitle text-muted">
                Niveau <?php echo htmlspecialchars($classe['niveau']); ?> · Année <?php echo htmlspecialchars($classe['anne_scolaire']); ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (hasRole(ROLES_GESTION)): ?>
            <a href="../eleves/ajouter.php" class="btn btn-dark">
                <i class="bi bi-person-plus"></i> Ajouter un élève
            </a>
            <a href="modifier.php?id=<?php echo (int) $classe['id_classe']; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>
</div>

<!-- Statistiques -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card voir-card stat-card"><span class="text-muted">Élèves</span><strong><?php echo $nb_eleves; ?></strong></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card voir-card stat-card"><span class="text-muted">Filles / Garçons</span><strong><?php echo $nb_filles; ?> / <?php echo $nb_eleves - $nb_filles; ?></strong></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card voir-card stat-card"><span class="text-muted">Moyenne de la classe</span>
            <strong><?php echo $moyenne_classe !== null ? number_format($moyenne_classe, 2) . '/20' : '—'; ?></strong>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card voir-card stat-card"><span class="text-muted">Moyenne ≥ 10</span>
            <strong><?php echo count($moyennes) > 0 ? $nb_admis . ' / ' . count($moyennes) : '—'; ?></strong>
        </div>
    </div>
</div>

<?php if (!empty($classe['description'])): ?>
    <div class="card voir-card mb-4">
        <div class="card-body"><?php echo nl2br(htmlspecialchars($classe['description'])); ?></div>
    </div>
<?php endif; ?>

<!-- Liste des élèves -->
<div class="card voir-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-people"></i> Élèves de la classe</span>
        <form method="get" class="d-flex gap-2 m-0">
            <input type="hidden" name="id" value="<?php echo (int) $classe['id_classe']; ?>">
            <select name="trimestre" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">Tous les trimestres</option>
                <?php foreach ($trimestres as $t): ?>
                    <option value="<?php echo (int) $t['id_trimestre']; ?>" <?php echo $id_trimestre == $t['id_trimestre'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($t['libelle']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (empty($eleves)): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-people fs-1 d-block mb-2"></i>
            Aucun élève dans cette classe.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Élève</th>
                        <th>Sexe</th>
                        <th>Notes</th>
                        <th>Moyenne</th>
                        <th>Rang</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eleves as $eleve): $id = $eleve['id_eleve']; ?>
                        <tr>
                            <td>
                                <a href="../eleves/voir.php?id=<?php echo (int) $id; ?>">
                                    <?php echo htmlspecialchars($eleve['nom'] . ' ' . $eleve['prenom']); ?>
                                </a>
                            </td>
                            <td><?php echo $eleve['sexe'] === 'F' ? 'F' : 'M'; ?></td>
                            <td><?php echo $nb_notes[$id] ?? 0; ?></td>
                            <td>
                                <?php if (isset($moyennes[$id])): ?>
                                    <span class="fw-semibold <?php echo $moyennes[$id] >= 10 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($moyennes[$id], 2); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo isset($rangs[$id]) ? ($rangs[$id] + 1) . ' / ' . count($moyennes) : '—'; ?></td>
                            <td class="text-end">
                                <a href="../eleves/voir.php?id=<?php echo (int) $id; ?>" class="btn btn-sm btn-outline-secondary" title="Voir">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="../notes/saisir.php?eleve_id=<?php echo (int) $id; ?>" class="btn btn-sm btn-outline-secondary" title="Saisir une note">
                                    <i class="bi bi-plus-circle"></i>
                                </a>
                                <?php if ($id_trimestre > 0 && isset($moyennes[$id])): ?>
                                    <a href="../bulletins/imprimer.php?eleve_id=<?php echo (int) $id; ?>&trimestre_id=<?php echo $id_trimestre; ?>"
                                       class="btn btn-sm btn-outline-secondary" title="Imprimer le bulletin" target="_blank">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
