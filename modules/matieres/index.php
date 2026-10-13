<?php
require_once '../../includes/header.php';
require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer les filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;

// Limites pour la pagination
$per_page_options = [10, 20, 50, 100];
if(!in_array($per_page, $per_page_options)) {
    $per_page = 20;
}

$offset = ($page - 1) * $per_page;

// Construction de la requête COUNT
$count_sql = "SELECT COUNT(*) as total FROM matiere WHERE 1=1";
$params = [];

if($search) {
    $count_sql .= " AND (nom_matiere LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Exécuter la requête COUNT
$count_stmt = $db->prepare($count_sql);
foreach($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_matieres = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_matieres / $per_page);

// Construction de la requête principale
$sql = "SELECT m.*, 
        (SELECT COUNT(*) FROM enseigner WHERE id_matiere = m.id_matiere) as total_enseignants,
        (SELECT COUNT(*) FROM porter_sur WHERE id_matiere = m.id_matiere) as total_notes
        FROM matiere m
        WHERE 1=1";

if($search) {
    $sql .= " AND (m.nom_matiere LIKE :search)";
}

$sql .= " ORDER BY m.nom_matiere LIMIT :offset, :per_page";

// Exécuter la requête principale
$stmt = $db->prepare($sql);
foreach($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques globales
$stats_query = "SELECT COUNT(*) as total, 
                AVG(coef) as moyenne_coef,
                SUM(coef) as total_coef,
                COUNT(DISTINCT nom_matiere) as distinct_matiere
                FROM matiere";
$stats_result = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

$total_matieres_stats = $stats_result['total'] ?? 0;
$moyenne_coef = round($stats_result['moyenne_coef'] ?? 0, 1);
$total_coef = $stats_result['total_coef'] ?? 0;
$distinct_matiere = $stats_result['distinct_matiere'] ?? 0;

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
                    <i class="bi bi-book-fill"></i>
                </span>
                Liste des matières
            </h1>
            <p class="page-subtitle text-muted">
                Consultez toutes les matières disponibles
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="ajouter.php" class="btn btn-dark">
                <i class="bi bi-plus-circle"></i> Ajouter une matière
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-grid"></i> Vue tableau
            </a>
        </div>
    </div>
</div>

<!-- Statistiques -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="bi bi-book-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_matieres_stats; ?></span>
                <span class="stat-label">Total matières</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <div class="stat-icon text-primary">
                <i class="bi bi-calculator-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $moyenne_coef; ?></span>
                <span class="stat-label">Coef. moyen</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <div class="stat-icon text-success">
                <i class="bi bi-plus-circle-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $total_coef; ?></span>
                <span class="stat-label">Total coefficients</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card">
            <div class="stat-icon text-warning">
                <i class="bi bi-tag-fill"></i>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?php echo $distinct_matiere; ?></span>
                <span class="stat-label">Matières distinctes</span>
            </div>
        </div>
    </div>
</div>

<!-- Filtres -->
<div class="filter-card mb-4">
    <div class="filter-card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label">Rechercher</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Nom de la matière..." value="<?php echo htmlspecialchars($search); ?>">
                    <?php if($search): ?>
                        <a href="liste.php" class="input-group-text bg-transparent border-0 text-muted" 
                           style="margin-left: -30px; z-index: 5;">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <label for="per_page" class="form-label">Afficher</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-list-ul"></i></span>
                    <select class="form-select" id="per_page" name="per_page" onchange="this.form.submit()">
                        <?php foreach($per_page_options as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo ($per_page == $option) ? 'selected' : ''; ?>>
                                <?php echo $option; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-dark w-100">
                    <i class="bi bi-funnel"></i> Filtrer
                </button>
            </div>
        </form>
        <?php if($search): ?>
            <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
                <a href="liste.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                </a>
                <span class="text-muted small">
                    <i class="bi bi-info-circle"></i>
                    <?php echo $total_matieres; ?> résultat(s) trouvé(s)
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Liste des matières en cartes -->
<div class="row g-4">
    <?php if(count($matieres) > 0): ?>
        <?php foreach($matieres as $matiere): ?>
            <div class="col-md-6 col-lg-4">
                <div class="matiere-card">
                    <div class="matiere-card-header">
                        <div class="matiere-icon">
                            <i class="bi bi-book"></i>
                        </div>
                        <div class="matiere-info">
                            <h5 class="matiere-name"><?php echo htmlspecialchars($matiere['nom_matiere']); ?></h5>
                            <span class="matiere-coef">
                                <i class="bi bi-star-fill text-warning"></i>
                                Coefficient: <?php echo $matiere['coef']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="matiere-card-body">
                        <div class="matiere-stats">
                            <div class="stat-item">
                                <span class="stat-label">
                                    <i class="bi bi-person-badge"></i> Enseignants
                                </span>
                                <span class="stat-value"><?php echo $matiere['total_enseignants'] ?? 0; ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">
                                    <i class="bi bi-pencil"></i> Notes
                                </span>
                                <span class="stat-value"><?php echo $matiere['total_notes'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="matiere-card-footer">
                        <a href="modifier.php?id=<?php echo $matiere['id_matiere']; ?>" 
                           class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil"></i> Modifier
                        </a>
                        <a href="voir.php?id=<?php echo $matiere['id_matiere']; ?>" 
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> Voir
                        </a>
                        <a href="supprimer.php?id=<?php echo $matiere['id_matiere']; ?>" 
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Confirmer la suppression de cette matière ?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
            <div class="col-12">
                <div class="pagination-container">
                    <div class="pagination-info">
                        <span class="text-muted small">
                            Affichage de <?php echo $offset + 1; ?> à <?php echo min($offset + $per_page, $total_matieres); ?> 
                            sur <?php echo $total_matieres; ?> matières
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
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="col-12">
            <div class="empty-state">
                <i class="bi bi-book"></i>
                <h6>Aucune matière trouvée</h6>
                <p class="text-muted">
                    <?php echo $search ? 
                        'Aucun résultat pour votre recherche' : 
                        'Commencez par ajouter une matière'; ?>
                </p>
                <?php if($search): ?>
                    <a href="liste.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser les filtres
                    </a>
                <?php else: ?>
                    <a href="ajouter.php" class="btn btn-dark btn-sm">
                        <i class="bi bi-plus-circle"></i> Ajouter une matière
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
/* ========================================
   PAGE DE LISTE DES MATIÈRES - VUE CARTES
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

/* Matiere Cards */
.matiere-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    height: 100%;
}

.matiere-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    transform: translateY(-4px);
}

.matiere-card-header {
    padding: 16px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 14px;
}

.matiere-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #64748b;
    flex-shrink: 0;
}

.matiere-info {
    flex: 1;
    min-width: 0;
}

.matiere-name {
    font-weight: 600;
    font-size: 1rem;
    color: #1a202c;
    margin: 0;
}

.matiere-coef {
    font-size: 0.8rem;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 4px;
}

.matiere-coef i {
    font-size: 0.7rem;
}

.matiere-card-body {
    padding: 14px 18px;
}

.matiere-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.matiere-stats .stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 10px;
    background: #f8fafc;
    border-radius: 6px;
    font-size: 0.8rem;
}

.matiere-stats .stat-item .stat-label {
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 4px;
}

.matiere-stats .stat-item .stat-value {
    font-weight: 600;
    color: #1a202c;
}

.matiere-card-footer {
    padding: 12px 18px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.matiere-card-footer .btn {
    padding: 4px 12px;
    font-size: 0.75rem;
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

/* Pagination */
.pagination-container {
    padding: 16px 20px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: #fafbfc;
    border-radius: 12px;
    margin-top: 16px;
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
    
    .matiere-stats {
        grid-template-columns: 1fr;
    }
    
    .matiere-card-footer {
        flex-direction: column;
        align-items: stretch;
    }
    
    .matiere-card-footer .btn {
        text-align: center;
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
    .page-header .btn,
    .matiere-card-footer .btn,
    .pagination-container,
    .no-print {
        display: none !important;
    }
    
    .matiere-card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        break-inside: avoid;
    }
}
</style>

<script>
// Auto-soumission des filtres
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.filter-card form');
    const selects = form.querySelectorAll('select');
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
});
</script>

<?php require_once '../../includes/footer.php'; ?>