<?php
// ========================================
// ENSEIGNANTS : liste avec matières enseignées, notes saisies et compte de connexion
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_PEDAGOGIE);

$db = (new Database())->getConnection();

$enseignants = $db->query("SELECT ens.id_enseignant, ens.nom, ens.prenom, ens.grade,
                                  (SELECT COUNT(*) FROM saisir s WHERE s.id_enseignant = ens.id_enseignant) AS nb_notes,
                                  u.id AS id_user, u.email, u.status
                           FROM enseignant ens
                           LEFT JOIN users u ON u.id_enseignant = ens.id_enseignant
                           ORDER BY ens.nom, ens.prenom")->fetchAll(PDO::FETCH_ASSOC);

// Matières de chaque enseignant
$matieres_par_enseignant = [];
$rows = $db->query("SELECT en.id_enseignant, m.nom_matiere
                    FROM enseigner en JOIN matiere m ON m.id_matiere = en.id_matiere
                    ORDER BY m.nom_matiere")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $matieres_par_enseignant[$row['id_enseignant']][] = $row['nom_matiere'];
}

$gestion = hasRole(ROLES_GESTION);

require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi bi-person-badge"></i></span>
                Enseignants
            </h1>
            <p class="page-subtitle text-muted"><?php echo count($enseignants); ?> enseignant(s) · matières enseignées et notes saisies</p>
        </div>
        <?php if ($gestion): ?>
        <a href="ajouter.php" class="btn btn-dark"><i class="bi bi-plus-circle"></i> Ajouter un enseignant</a>
        <?php endif; ?>
    </div>
</div>

<div class="card voir-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Enseignant</th>
                    <th>Grade</th>
                    <th>Matières enseignées</th>
                    <th>Notes saisies</th>
                    <?php if (hasRole(ROLES_ADMIN)): ?><th>Compte</th><?php endif; ?>
                    <?php if ($gestion): ?><th class="text-end">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($enseignants)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucun enseignant enregistré.</td></tr>
                <?php endif; ?>
                <?php foreach ($enseignants as $ens): $id = (int) $ens['id_enseignant']; ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($ens['nom'] . ' ' . $ens['prenom']); ?></td>
                        <td><?php echo $ens['grade'] ? htmlspecialchars($ens['grade']) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php if (!empty($matieres_par_enseignant[$id])): ?>
                                <?php foreach ($matieres_par_enseignant[$id] as $nom_matiere): ?>
                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($nom_matiere); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-warning small"><i class="bi bi-exclamation-triangle"></i> Aucune matière : saisie impossible</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) $ens['nb_notes']; ?></td>
                        <?php if (hasRole(ROLES_ADMIN)): ?>
                        <td>
                            <?php if ($ens['id_user']): ?>
                                <a href="../utilisateurs/ajouter.php?id=<?php echo (int) $ens['id_user']; ?>"><?php echo htmlspecialchars($ens['email']); ?></a>
                                <?php if ($ens['status'] !== 'active'): ?><span class="badge bg-secondary">désactivé</span><?php endif; ?>
                            <?php else: ?>
                                <a href="../utilisateurs/ajouter.php?enseignant=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-person-plus"></i> Créer un compte
                                </a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($gestion): ?>
                        <td class="text-end">
                            <a href="ajouter.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="post" action="supprimer.php" class="d-inline m-0"
                                  onsubmit="return confirm('Supprimer cet enseignant ? Son compte de connexion sera aussi supprimé.')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
