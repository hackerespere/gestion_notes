<?php
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer les filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$classe_filter = isset($_GET['classe']) ? $_GET['classe'] : '';
$sexe_filter = isset($_GET['sexe']) ? $_GET['sexe'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'nom';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;

// Limites pour la pagination
$per_page_options = [10, 20, 50, 100];
if(!in_array($per_page, $per_page_options)) {
    $per_page = 20;
}

$offset = ($page - 1) * $per_page;

// Récupérer les classes pour le filtre
$classes = $db->query("SELECT id_classe, nom_classe FROM classe ORDER BY nom_classe")->fetchAll();

// Construction de la requête COUNT
$count_sql = "SELECT COUNT(*) as total 
              FROM eleve e 
              LEFT JOIN classe c ON e.id_classe = c.id_classe 
              WHERE 1=1";

$params = [];

if($search) {
    $count_sql .= " AND (e.nom LIKE :search OR e.prenom LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if($classe_filter) {
    $count_sql .= " AND e.id_classe = :classe";
    $params[':classe'] = $classe_filter;
}

if($sexe_filter) {
    $count_sql .= " AND e.sexe = :sexe";
    $params[':sexe'] = $sexe_filter;
}

// Exécuter la requête COUNT
$count_stmt = $db->prepare($count_sql);
foreach($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_eleves = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_eleves / $per_page);

// Construction de la requête principale avec LIMIT
$sql = "SELECT e.*, c.nom_classe, 
        (SELECT COUNT(*) FROM note n 
         JOIN obtenir o ON n.id_note = o.id_note 
         WHERE o.id_eleve = e.id_eleve) as total_notes,
        (SELECT AVG(n.valeur) FROM note n 
         JOIN obtenir o ON n.id_note = o.id_note 
         WHERE o.id_eleve = e.id_eleve) as moyenne_notes
        FROM eleve e 
        LEFT JOIN classe c ON e.id_classe = c.id_classe 
        WHERE 1=1";

if($search) {
    $sql .= " AND (e.nom LIKE :search OR e.prenom LIKE :search)";
}

if($classe_filter) {
    $sql .= " AND e.id_classe = :classe";
}

if($sexe_filter) {
    $sql .= " AND e.sexe = :sexe";
}

// Tri
$sortable_columns = ['nom', 'prenom', 'date_naiss', 'sexe', 'nom_classe', 'total_notes', 'moyenne_notes'];
if(in_array($sort_by, $sortable_columns)) {
    $sql .= " ORDER BY $sort_by $sort_order";
} else {
    $sql .= " ORDER BY e.nom, e.prenom";
}

$sql .= " LIMIT :offset, :per_page";

// Exécuter la requête principale
$stmt = $db->prepare($sql);
foreach($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques globales (non filtrées)
$stats_query = "SELECT COUNT(*) as total, 
                SUM(CASE WHEN sexe = 'M' THEN 1 ELSE 0 END) as garcons,
                SUM(CASE WHEN sexe = 'F' THEN 1 ELSE 0 END) as filles,
                COUNT(DISTINCT id_classe) as classes
                FROM eleve";
$stats_result = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

$total_garcons = $stats_result['garcons'] ?? 0;
$total_filles = $stats_result['filles'] ?? 0;
$total_classes = $stats_result['classes'] ?? 0;

// Statistiques des notes (non filtrées)
$notes_stats = $db->query("SELECT COUNT(*) as total_notes, AVG(valeur) as moyenne_globale FROM note")->fetch(PDO::FETCH_ASSOC);
$total_notes = $notes_stats['total_notes'] ?? 0;
$moyenne_globale = $notes_stats['moyenne_globale'] ? round($notes_stats['moyenne_globale'], 2) : 0;

// Statistiques par classe (non filtrées)
$stats_par_classe = [];
$classe_stats = $db->query("SELECT c.nom_classe, COUNT(e.id_eleve) as total 
                            FROM classe c 
                            LEFT JOIN eleve e ON c.id_classe = e.id_classe 
                            GROUP BY c.id_classe 
                            ORDER BY total DESC")->fetchAll();
foreach($classe_stats as $stat) {
    $stats_par_classe[$stat['nom_classe']] = $stat['total'];
}

// Fonction pour construire l'URL avec les paramètres
function buildUrl($page, $per_page = null) {
    $params = $_GET;
    $params['page'] = $page;
    if($per_page !== null) {
        $params['per_page'] = $per_page;
    }
    return '?' . http_build_query($params);
}
?>

<!-- En-tête de la page -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <i class="bi bi-people-fill"></i>
                </span>
                Liste des élèves
            </h1>
            <p class="page-subtitle text-muted">
                Gérez et consultez tous les élèves inscrits
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="ajouter.php" class="btn btn-dark">
                <i class="bi bi-plus-circle"></i> Ajouter un élève
            </a>
            <a href="ajouter.php?import=1" class="btn btn-outline-secondary">
                <i class="bi bi-upload"></i> Importer
            </a>
        </div>
    </div>
</div>

<!-- Statistiques avancées -->
<div class="row g-3 mb-4">
    <div class="col-md-2 col-6">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $stats_result['total'] ?? 0; ?></span>
                <span class="stat-label">Total élèves</span>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="stat-card">
            <div class="stat-icon text-primary">
                <i class="bi bi-gender-male"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_garcons; ?></span>
                <span class="stat-label">Garçons</span>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="stat-card">
            <div class="stat-icon text-danger">
                <i class="bi bi-gender-female"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_filles; ?></span>
                <span class="stat-label">Filles</span>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="stat-card">
            <div class="stat-icon text-warning">
                <i class="bi bi-building-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_classes; ?></span>
                <span class="stat-label">Classes</span>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="stat-card">
            <div class="stat-icon text-info">
                <i class="bi bi-pencil-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_notes; ?></span>
                <span class="stat-label">Total notes</span>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="stat-card">
            <div class="stat-icon text-success">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $moyenne_globale; ?></span>
                <span class="stat-label">Moyenne générale</span>
            </div>
        </div>
    </div>
</div>

<!-- Répartition par classe (mini-graphique) -->
<?php if(count($stats_par_classe) > 0): ?>
<div class="distribution-card mb-4">
    <div class="distribution-header">
        <span class="distribution-title">
            <i class="bi bi-pie-chart-fill"></i> Répartition par classe
        </span>
        <span class="distribution-badge"><?php echo count($stats_par_classe); ?> classes</span>
    </div>
    <div class="distribution-body">
        <div class="distribution-bars">
            <?php 
            $max_count = max($stats_par_classe);
            foreach($stats_par_classe as $classe => $count):
                $percentage = $max_count > 0 ? ($count / $max_count) * 100 : 0;
                $width = max(5, $percentage);
            ?>
            <div class="distribution-item">
                <span class="distribution-label"><?php echo htmlspecialchars($classe); ?></span>
                <div class="distribution-bar-track">
                    <div class="distribution-bar-fill" style="width: <?php echo $width; ?>%;">
                        <span class="distribution-count"><?php echo $count; ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filtres avancés -->
<div class="filter-card mb-4">
    <div class="filter-card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label">Rechercher</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Nom ou prénom..." value="<?php echo htmlspecialchars($search); ?>">
                    <?php if($search): ?>
                        <a href="index.php" class="input-group-text bg-transparent border-0 text-muted" 
                           style="margin-left: -30px; z-index: 5;">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-2">
                <label for="classe" class="form-label">Classe</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-building"></i></span>
                    <select class="form-select" id="classe" name="classe">
                        <option value="">Toutes</option>
                        <?php foreach($classes as $classe): ?>
                            <option value="<?php echo $classe['id_classe']; ?>" 
                                    <?php echo ($classe_filter == $classe['id_classe']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom_classe']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <label for="sexe" class="form-label">Sexe</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
                    <select class="form-select" id="sexe" name="sexe">
                        <option value="">Tous</option>
                        <option value="M" <?php echo ($sexe_filter == 'M') ? 'selected' : ''; ?>>Masculin</option>
                        <option value="F" <?php echo ($sexe_filter == 'F') ? 'selected' : ''; ?>>Féminin</option>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <label for="sort_by" class="form-label">Trier par</label>
                <select class="form-select" id="sort_by" name="sort_by">
                    <option value="nom" <?php echo ($sort_by == 'nom') ? 'selected' : ''; ?>>Nom</option>
                    <option value="prenom" <?php echo ($sort_by == 'prenom') ? 'selected' : ''; ?>>Prénom</option>
                    <option value="date_naiss" <?php echo ($sort_by == 'date_naiss') ? 'selected' : ''; ?>>Âge</option>
                    <option value="total_notes" <?php echo ($sort_by == 'total_notes') ? 'selected' : ''; ?>>Notes</option>
                    <option value="moyenne_notes" <?php echo ($sort_by == 'moyenne_notes') ? 'selected' : ''; ?>>Moyenne</option>
                </select>
            </div>
            <div class="col-md-1">
                <label for="sort_order" class="form-label">Ordre</label>
                <select class="form-select" id="sort_order" name="sort_order">
                    <option value="ASC" <?php echo ($sort_order == 'ASC') ? 'selected' : ''; ?>>↑</option>
                    <option value="DESC" <?php echo ($sort_order == 'DESC') ? 'selected' : ''; ?>>↓</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-dark w-100">
                    <i class="bi bi-funnel"></i> Filtrer
                </button>
            </div>
        </form>
        <?php if($search || $classe_filter || $sexe_filter): ?>
            <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                </a>
                <span class="text-muted small">
                    <i class="bi bi-info-circle"></i>
                    <?php echo $total_eleves; ?> résultat(s) trouvé(s)
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tableau des élèves -->
<div class="eleves-table-card">
    <div class="eleves-table-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="card-icon"><i class="bi bi-table"></i></span>
                <span class="card-title">Liste des élèves</span>
                <span class="eleves-count-badge"><?php echo $total_eleves; ?> élèves</span>
                <?php if($total_eleves > 0): ?>
                    <span class="eleves-count-badge text-muted">
                        Page <?php echo $page; ?> sur <?php echo $total_pages; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-1 align-items-center">
                <!-- Sélecteur de nombre d'éléments par page -->
                <div class="d-flex align-items-center me-2">
                    <span class="text-muted small me-1">Afficher :</span>
                    <select class="form-select form-select-sm" style="width: auto;" id="perPageSelect" onchange="changePerPage()">
                        <?php foreach($per_page_options as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo ($per_page == $option) ? 'selected' : ''; ?>>
                                <?php echo $option; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-outline-secondary btn-sm" onclick="toggleColumns()" title="Colonnes">
                    <i class="bi bi-layout-three-columns"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()" title="Imprimer">
                    <i class="bi bi-printer"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="exportToCSV()" title="Exporter CSV">
                    <i class="bi bi-download"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="exportToPDF()" title="Exporter PDF">
                    <i class="bi bi-file-pdf"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="eleves-table-body">
        <?php if(count($eleves) > 0): ?>
            <div class="table-responsive">
                <table class="table eleves-table" id="elevesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th class="col-nom">Nom</th>
                            <th class="col-prenom">Prénom</th>
                            <th class="col-date">Date naissance</th>
                            <th class="col-age">Âge</th>
                            <th class="col-sexe">Sexe</th>
                            <th class="col-classe">Classe</th>
                            <th class="col-notes">Notes</th>
                            <th class="col-moyenne">Moyenne</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($eleves as $index => $eleve): 
                            $age = date_diff(date_create($eleve['date_naiss']), date_create('today'))->y;
                            $moyenne = $eleve['moyenne_notes'] ? round($eleve['moyenne_notes'], 2) : '-';
                            $note_color = is_numeric($moyenne) ? ($moyenne >= 10 ? 'text-success' : 'text-danger') : 'text-muted';
                            $row_number = $offset + $index + 1;
                        ?>
                        <tr>
                            <td>
                                <span class="row-number"><?php echo $row_number; ?></span>
                            </td>
                            <td class="col-nom">
                                <strong><?php echo htmlspecialchars($eleve['nom']); ?></strong>
                            </td>
                            <td class="col-prenom"><?php echo htmlspecialchars($eleve['prenom']); ?></td>
                            <td class="col-date">
                                <i class="bi bi-calendar3 text-muted"></i>
                                <?php echo date('d/m/Y', strtotime($eleve['date_naiss'])); ?>
                            </td>
                            <td class="col-age">
                                <span class="age-badge"><?php echo $age; ?> ans</span>
                            </td>
                            <td class="col-sexe">
                                <span class="sexe-badge <?php echo $eleve['sexe'] == 'M' ? 'sexe-m' : 'sexe-f'; ?>">
                                    <i class="bi <?php echo $eleve['sexe'] == 'M' ? 'bi-gender-male' : 'bi-gender-female'; ?>"></i>
                                    <?php echo $eleve['sexe'] == 'M' ? 'M' : 'F'; ?>
                                </span>
                            </td>
                            <td class="col-classe">
                                <?php if($eleve['nom_classe']): ?>
                                    <span class="classe-badge">
                                        <i class="bi bi-building"></i>
                                        <?php echo htmlspecialchars($eleve['nom_classe']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">Non affecté</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-notes">
                                <span class="notes-count">
                                    <?php echo $eleve['total_notes'] ?? 0; ?>
                                </span>
                            </td>
                            <td class="col-moyenne">
                                <span class="moyenne-value <?php echo $note_color; ?>">
                                    <?php echo $moyenne; ?>
                                </span>
                            </td>
                            <td class="col-actions">
                                <div class="action-buttons">
                                    <a href="modifier.php?id=<?php echo $eleve['id_eleve']; ?>" 
                                       class="btn btn-sm btn-outline-secondary" title="Modifier">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="../notes/consulter.php?eleve_id=<?php echo $eleve['id_eleve']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="Voir les notes">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="../bulletins/imprimer.php?eleve_id=<?php echo $eleve['id_eleve']; ?>" 
                                       class="btn btn-sm btn-outline-success" title="Bulletin">
                                        <i class="bi bi-file-text"></i>
                                    </a>
                                    <a href="supprimer.php?id=<?php echo $eleve['id_eleve']; ?>" 
                                       class="btn btn-sm btn-outline-danger" title="Supprimer"
                                       onclick="return confirm('Confirmer la suppression de cet élève ?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        <span class="text-muted small">
                            Affichage de <?php echo $offset + 1; ?> à <?php echo min($offset + $per_page, $total_eleves); ?> 
                            sur <?php echo $total_eleves; ?> élèves
                        </span>
                    </div>
                    <nav aria-label="Pagination">
                        <ul class="pagination">
                            <!-- Première page -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl(1); ?>" title="Première page">
                                    <i class="bi bi-chevron-double-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page précédente -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl($page - 1); ?>" title="Page précédente">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            
                            <!-- Pages numérotées -->
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if($start_page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="' . buildUrl(1) . '">1</a></li>';
                                if($start_page > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                            }
                            
                            for($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildUrl($i); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if($end_page < $total_pages): ?>
                                <?php if($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?php echo buildUrl($total_pages); ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>
                            
                            <!-- Page suivante -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl($page + 1); ?>" title="Page suivante">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            
                            <!-- Dernière page -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildUrl($total_pages); ?>" title="Dernière page">
                                    <i class="bi bi-chevron-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-person-x"></i>
                <h6>Aucun élève trouvé</h6>
                <p class="text-muted">
                    <?php echo ($search || $classe_filter || $sexe_filter) ? 
                        'Aucun résultat pour les filtres sélectionnés' : 
                        'Commencez par ajouter un élève'; ?>
                </p>
                <?php if($search || $classe_filter || $sexe_filter): ?>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser les filtres
                    </a>
                <?php else: ?>
                    <a href="ajouter.php" class="btn btn-dark btn-sm">
                        <i class="bi bi-plus-circle"></i> Ajouter un élève
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Export modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Exporter</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-secondary" onclick="exportToCSV()">
                        <i class="bi bi-file-spreadsheet"></i> CSV
                    </button>
                    <button class="btn btn-outline-secondary" onclick="exportToPDF()">
                        <i class="bi bi-file-pdf"></i> PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ========================================
   PAGE DE LISTE DES ÉLÈVES - AVEC PAGINATION
   ======================================== */

/* Page Header */
.page-header {
    margin-bottom: 28px;
}

.page-title {
    font-size: 1.65rem;
    font-weight: 700;
    color: #1a202c;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title .title-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: #1a202c;
    color: white;
    border-radius: 12px;
    font-size: 1.2rem;
}

.page-subtitle {
    font-size: 0.95rem;
    color: #64748b;
    margin: 6px 0 0 56px;
}

/* Stat Cards */
.stat-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.2s ease;
}

.stat-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    transform: translateY(-2px);
}

.stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #64748b;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
    min-width: 0;
}

.stat-value {
    display: block;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1a202c;
    line-height: 1.2;
}

.stat-label {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
}

/* Distribution Card */
.distribution-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}

.distribution-header {
    padding: 12px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.distribution-title {
    font-weight: 600;
    font-size: 0.85rem;
    color: #1a202c;
}

.distribution-title i {
    margin-right: 6px;
    color: #64748b;
}

.distribution-badge {
    font-size: 0.65rem;
    padding: 2px 10px;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 50px;
}

.distribution-body {
    padding: 12px 18px;
}

.distribution-bars {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.distribution-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.distribution-label {
    font-size: 0.8rem;
    color: #475569;
    min-width: 100px;
    flex-shrink: 0;
}

.distribution-bar-track {
    flex: 1;
    height: 20px;
    background: #f1f5f9;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.distribution-bar-fill {
    height: 100%;
    background: #1a202c;
    border-radius: 4px;
    transition: width 0.8s ease;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 6px;
    min-width: 20px;
}

.distribution-count {
    font-size: 0.65rem;
    font-weight: 600;
    color: white;
}

/* Filter Card */
.filter-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px 24px;
}

.filter-card-body .input-group-text {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #94a3b8;
}

.filter-card-body .form-control,
.filter-card-body .form-select {
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
}

.filter-card-body .form-control:focus,
.filter-card-body .form-select:focus {
    border-color: #1a202c;
    box-shadow: 0 0 0 3px rgba(26, 32, 44, 0.08);
}

.filter-card-body .form-label {
    font-weight: 500;
    font-size: 0.85rem;
    color: #334155;
    margin-bottom: 6px;
}

/* Eleves Table Card */
.eleves-table-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.eleves-table-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.eleves-table-header .card-icon {
    font-size: 1rem;
    color: #64748b;
    margin-right: 8px;
}

.eleves-table-header .card-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #1a202c;
}

.eleves-count-badge {
    font-size: 0.7rem;
    font-weight: 600;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 12px;
    border-radius: 50px;
    margin-left: 10px;
}

.eleves-table-body {
    padding: 0;
}

/* Table */
.eleves-table {
    margin-bottom: 0;
}

.eleves-table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.eleves-table tbody td {
    padding: 10px 14px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}

.eleves-table tbody tr {
    transition: background-color 0.15s ease;
}

.eleves-table tbody tr:hover {
    background: #f8fafc;
}

.row-number {
    font-weight: 600;
    color: #94a3b8;
    font-size: 0.8rem;
}

/* Age Badge */
.age-badge {
    font-size: 0.75rem;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 10px;
    border-radius: 4px;
}

/* Sexe Badge */
.sexe-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 50px;
    font-size: 0.7rem;
    font-weight: 600;
}

.sexe-m {
    background: #dbeafe;
    color: #1e40af;
}

.sexe-f {
    background: #fce4ec;
    color: #9c1a2c;
}

/* Classe Badge */
.classe-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    background: #f1f5f9;
    color: #475569;
}

/* Notes Count */
.notes-count {
    font-weight: 600;
    color: #1a202c;
}

/* Moyenne Value */
.moyenne-value {
    font-weight: 700;
    font-size: 0.9rem;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 3px;
}

.action-buttons .btn {
    width: 30px;
    height: 30px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    font-size: 0.8rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state i {
    font-size: 3rem;
    color: #e2e8f0;
}

.empty-state h6 {
    margin: 16px 0 8px 0;
    color: #1a202c;
    font-weight: 600;
}

.empty-state p {
    font-size: 0.9rem;
    margin-bottom: 12px;
}

/* ========================================
   PAGINATION STYLES
   ======================================== */

.pagination-container {
    padding: 16px 20px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: #fafbfc;
    border-radius: 0 0 12px 12px;
}

.pagination-info {
    display: flex;
    align-items: center;
}

.pagination {
    margin: 0;
    gap: 2px;
}

.pagination .page-item .page-link {
    color: #475569;
    border: 1px solid #e2e8f0;
    padding: 6px 12px;
    font-size: 0.85rem;
    border-radius: 6px;
    transition: all 0.2s ease;
    background: white;
    min-width: 36px;
    text-align: center;
}

.pagination .page-item .page-link:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #1a202c;
}

.pagination .page-item.active .page-link {
    background: #1a202c;
    border-color: #1a202c;
    color: white;
}

.pagination .page-item.active .page-link:hover {
    background: #2d3748;
    border-color: #2d3748;
}

.pagination .page-item.disabled .page-link {
    color: #94a3b8;
    background: #f8fafc;
    cursor: not-allowed;
}

.pagination .page-item .page-link i {
    font-size: 0.8rem;
}

/* Responsive */
@media (max-width: 991.98px) {
    .page-title {
        font-size: 1.4rem;
    }
    
    .page-subtitle {
        margin-left: 0;
    }
}

@media (max-width: 767.98px) {
    .page-header .d-flex {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
    }
    
    .filter-card {
        padding: 16px;
    }
    
    .stat-card {
        padding: 10px 12px;
    }
    
    .stat-value {
        font-size: 1.1rem;
    }
    
    .eleves-table thead th {
        font-size: 0.6rem;
        padding: 6px 8px;
    }
    
    .eleves-table tbody td {
        font-size: 0.7rem;
        padding: 6px 8px;
    }
    
    .action-buttons .btn {
        width: 26px;
        height: 26px;
        font-size: 0.7rem;
    }
    
    .distribution-label {
        min-width: 60px;
        font-size: 0.7rem;
    }
    
    .pagination-container {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .pagination-info {
        justify-content: center;
        font-size: 0.8rem;
    }
    
    .pagination {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .pagination .page-item .page-link {
        padding: 4px 10px;
        font-size: 0.75rem;
        min-width: 32px;
    }
}

@media (max-width: 575.98px) {
    .stat-card {
        flex-direction: column;
        text-align: center;
        padding: 10px;
    }
    
    .stat-icon {
        margin: 0 auto;
    }
    
    .eleves-table thead th.col-date,
    .eleves-table thead th.col-age,
    .eleves-table tbody td.col-date,
    .eleves-table tbody td.col-age {
        display: none;
    }
    
    .pagination .page-item .page-link {
        padding: 4px 8px;
        font-size: 0.7rem;
        min-width: 28px;
    }
    
    .pagination .page-item:not(.active):not(.page-item:first-child):not(.page-item:last-child) {
        display: none;
    }
    
    .pagination .page-item.active {
        display: block;
    }
    
    .pagination .page-item:first-child,
    .pagination .page-item:last-child {
        display: block;
    }
}

/* Print styles */
@media print {
    .filter-card,
    .distribution-card,
    .page-header .btn,
    .eleves-table-header .btn,
    .pagination-container,
    .no-print {
        display: none !important;
    }
    
    .eleves-table-card {
        border: none !important;
    }
    
    .eleves-table thead th {
        background: #f5f5f5 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>

<script>
// Fonction pour changer le nombre d'éléments par page
function changePerPage() {
    const select = document.getElementById('perPageSelect');
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', select.value);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// Fonction pour exporter en CSV
function exportToCSV() {
    const table = document.querySelector('.eleves-table');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    // En-têtes
    const headers = ['#', 'Nom', 'Prénom', 'Date naissance', 'Âge', 'Sexe', 'Classe', 'Notes', 'Moyenne'];
    csv.push(headers.join(','));
    
    // Données
    rows.forEach(row => {
        if (row.querySelector('th')) return;
        const cols = row.querySelectorAll('td');
        const rowData = [];
        cols.forEach((col, index) => {
            if (index === 9) return;
            let text = col.textContent.trim();
            text = text.replace(/,/g, ';');
            rowData.push(text);
        });
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });
    
    const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'liste_eleves.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

// Fonction pour exporter en PDF (simple version print to PDF)
function exportToPDF() {
    window.print();
}

// Toggle columns visibility
function toggleColumns() {
    const columns = ['col-nom', 'col-prenom', 'col-date', 'col-age', 'col-sexe', 'col-classe', 'col-notes', 'col-moyenne'];
    const hidden = localStorage.getItem('hiddenColumns');
    const hiddenArray = hidden ? JSON.parse(hidden) : [];
    
    if (hiddenArray.length === 0) {
        const toHide = window.innerWidth < 768 ? ['col-date', 'col-age'] : [];
        localStorage.setItem('hiddenColumns', JSON.stringify(toHide));
        applyColumnVisibility(toHide);
    } else {
        localStorage.setItem('hiddenColumns', JSON.stringify([]));
        applyColumnVisibility([]);
    }
}

function applyColumnVisibility(hidden) {
    const columns = ['col-nom', 'col-prenom', 'col-date', 'col-age', 'col-sexe', 'col-classe', 'col-notes', 'col-moyenne'];
    columns.forEach(col => {
        const elements = document.querySelectorAll(`.${col}`);
        elements.forEach(el => {
            el.style.display = hidden.includes(col) ? 'none' : '';
        });
    });
}

// Auto-soumission des filtres
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.filter-card form');
    const selects = form.querySelectorAll('select:not(#perPageSelect)');
    const searchInput = document.getElementById('search');
    
    selects.forEach(select => {
        select.addEventListener('change', function() {
            form.submit();
        });
    });
    
    let timeout = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                form.submit();
            }, 500);
        });
    }
    
    // Restaurer la visibilité des colonnes
    const hidden = localStorage.getItem('hiddenColumns');
    if (hidden) {
        applyColumnVisibility(JSON.parse(hidden));
    }
});

// DataTables (si jQuery est chargé)
if (typeof $ !== 'undefined' && $.fn.DataTable) {
    $(document).ready(function() {
        if ($('#elevesTable').length) {
            $('#elevesTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/fr-FR.json'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tous"]],
                order: [[1, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [0, 9] }
                ],
                responsive: true,
                searching: false,
                paging: false,
                info: false
            });
        }
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>