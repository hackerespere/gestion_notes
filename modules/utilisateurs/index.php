<?php
// ========================================
// COMPTES UTILISATEURS : liste (administrateurs uniquement)
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_ADMIN);

$database = new Database();
$db = $database->getConnection();

$filtre_role = $_GET['role'] ?? '';
$recherche = trim($_GET['q'] ?? '');

$sql = "SELECT u.id, u.nom, u.prenom, u.email, u.role, u.status, u.last_login, u.id_eleve,
               e.nom AS eleve_nom, e.prenom AS eleve_prenom
        FROM users u
        LEFT JOIN eleve e ON e.id_eleve = u.id_eleve
        WHERE 1=1";
$params = [];
if ($filtre_role !== '') {
    $sql .= " AND u.role = :role";
    $params[':role'] = $filtre_role;
}
if ($recherche !== '') {
    $sql .= " AND (u.nom LIKE :q OR u.prenom LIKE :q OR u.email LIKE :q)";
    $params[':q'] = '%' . $recherche . '%';
}
$sql .= " ORDER BY u.role, u.nom, u.prenom";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roles = [
    'admin'      => 'Administrateur',
    'directeur'  => 'Directeur',
    'enseignant' => 'Enseignant',
    'eleve'      => 'Élève',
    'parent'     => 'Parent',
    'user'       => 'Utilisateur',
];

require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi bi-person-gear"></i></span>
                Comptes utilisateurs
            </h1>
            <p class="page-subtitle text-muted">Créez les comptes de connexion et attribuez les rôles</p>
        </div>
        <a href="ajouter.php" class="btn btn-dark">
            <i class="bi bi-person-plus"></i> Nouveau compte
        </a>
    </div>
</div>

<!-- Filtres -->
<form method="get" class="row g-2 mb-3">
    <div class="col-md-5">
        <input type="search" name="q" class="form-control" placeholder="Nom, prénom ou email"
               value="<?php echo htmlspecialchars($recherche); ?>">
    </div>
    <div class="col-md-4">
        <select name="role" class="form-select">
            <option value="">Tous les rôles</option>
            <?php foreach ($roles as $code => $libelle): ?>
                <option value="<?php echo $code; ?>" <?php echo $filtre_role === $code ? 'selected' : ''; ?>><?php echo $libelle; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrer</button>
        <a href="index.php" class="btn btn-outline-secondary">Réinitialiser</a>
    </div>
</form>

<div class="card voir-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>Rôle</th>
                    <th>Élève lié</th>
                    <th>Statut</th>
                    <th>Dernière connexion</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucun compte trouvé.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): $moi = (int) $u['id'] === (int) $_SESSION['user_id']; ?>
                    <tr class="<?php echo $u['status'] !== 'active' ? 'text-muted' : ''; ?>">
                        <td>
                            <?php echo htmlspecialchars($u['nom'] . ' ' . $u['prenom']); ?>
                            <?php if ($moi): ?><span class="badge bg-secondary ms-1">vous</span><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><?php echo $roles[$u['role']] ?? htmlspecialchars($u['role']); ?></td>
                        <td>
                            <?php if ($u['id_eleve']): ?>
                                <a href="../eleves/voir.php?id=<?php echo (int) $u['id_eleve']; ?>">
                                    <?php echo htmlspecialchars($u['eleve_nom'] . ' ' . $u['eleve_prenom']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $u['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $u['status'] === 'active' ? 'Actif' : 'Désactivé'; ?>
                            </span>
                        </td>
                        <td><?php echo $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : '<span class="text-muted">Jamais</span>'; ?></td>
                        <td class="text-end">
                            <a href="ajouter.php?id=<?php echo (int) $u['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if (!$moi): ?>
                                <form method="post" action="statut.php" class="d-inline m-0"
                                      onsubmit="return confirm('<?php echo $u['status'] === 'active' ? 'Désactiver ce compte ? La personne ne pourra plus se connecter.' : 'Réactiver ce compte ?'; ?>')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                                    <?php if ($u['status'] === 'active'): ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Désactiver">
                                            <i class="bi bi-person-slash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Réactiver">
                                            <i class="bi bi-person-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
