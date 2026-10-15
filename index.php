<?php
// ========================================
// CHARGEMENT DES DÉPENDANCES
// (header.php vérifie la connexion et le timeout via config/session.php)
// ========================================
require_once 'config/session.php';

// Les élèves et les parents ont leur propre espace
if (hasRole(ROLE_ELEVE)) {
    header('Location: ' . BASE_URL . 'modules/espace_eleve/index.php');
    exit;
}
if (hasRole(ROLE_PARENT)) {
    header('Location: ' . BASE_URL . 'modules/espace_parent/index.php');
    exit;
}

require_once 'includes/header.php';
require_once 'config/database.php';

// Le tableau de bord affiche des données d'élèves : réservé aux rôles pédagogiques
if (!hasRole(ROLES_PEDAGOGIE)): ?>
    <div class="container mt-5">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            Bienvenue <?php echo htmlspecialchars($user_name); ?>. Votre compte n'a pas encore accès aux données de l'établissement.
            Contactez un administrateur pour obtenir les droits nécessaires.
        </div>
    </div>
<?php
    require_once 'includes/footer.php';
    exit;
endif;

$database = new Database();
$db = $database->getConnection();

// ========================================
// STATISTIQUES GÉNÉRALES
// ========================================
$stats = [
    'eleves'      => 0,
    'classes'     => 0,
    'matieres'    => 0,
    'notes'       => 0,
    'enseignants' => 0,
];

try {
    // Nombre d'élèves
    $stmt = $db->query("SELECT COUNT(*) as total FROM eleve");
    $stats['eleves'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Nombre de classes
    $stmt = $db->query("SELECT COUNT(*) as total FROM classe");
    $stats['classes'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Nombre de matières
    $stmt = $db->query("SELECT COUNT(*) as total FROM matiere");
    $stats['matieres'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Nombre de notes
    $stmt = $db->query("SELECT COUNT(*) as total FROM note");
    $stats['notes'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Nombre d'enseignants
    $stmt = $db->query("SELECT COUNT(*) as total FROM enseignant");
    $stats['enseignants'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    error_log('Erreur stats: ' . $e->getMessage());
}

// ========================================
// DERNIÈRES NOTES SAISIES
// ========================================
$dernieres_notes = [];
try {
    $query = "SELECT e.nom, e.prenom, m.nom_matiere, n.valeur, n.date_note, n.type_note
              FROM note n
              JOIN obtenir o ON n.id_note = o.id_note
              JOIN eleve e ON o.id_eleve = e.id_eleve
              JOIN porter_sur ps ON n.id_note = ps.id_note
              JOIN matiere m ON ps.id_matiere = m.id_matiere
              ORDER BY n.date_note DESC, n.id_note DESC
              LIMIT 5";
    $dernieres_notes = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erreur dernières notes: ' . $e->getMessage());
}

// ========================================
// MEILLEURS ÉLÈVES (MOYENNE GÉNÉRALE)
// ========================================
$meilleurs_eleves = [];
try {
    $query = "SELECT e.id_eleve, e.nom, e.prenom,
              AVG(n.valeur) as moyenne,
              COUNT(n.id_note) as nb_notes
              FROM eleve e
              JOIN obtenir o ON e.id_eleve = o.id_eleve
              JOIN note n ON o.id_note = n.id_note
              GROUP BY e.id_eleve, e.nom, e.prenom
              HAVING COUNT(n.id_note) >= 3
              ORDER BY moyenne DESC
              LIMIT 5";
    $meilleurs_eleves = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erreur top élèves: ' . $e->getMessage());
}

// ========================================
// RÉPARTITION DES ÉLÈVES PAR CLASSE
// ========================================
$repartition_classes = [];
try {
    $query = "SELECT c.id_classe, c.nom_classe, COUNT(e.id_eleve) as total
              FROM classe c
              LEFT JOIN eleve e ON c.id_classe = e.id_classe
              GROUP BY c.id_classe, c.nom_classe
              ORDER BY c.nom_classe ASC";
    $repartition_classes = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erreur répartition: ' . $e->getMessage());
}

// ========================================
// CALCULS STATISTIQUES
// ========================================
$total_notes  = $stats['notes'];
$total_eleves = $stats['eleves'];
$moyenne_notes_par_eleve = $total_eleves > 0 ? round($total_notes / $total_eleves, 1) : 0;

// Taux de remplissage (base 30 élèves par classe)
$total_places = $stats['classes'] * 30;
$taux_remplissage = $total_places > 0 ? min(100, round(($stats['eleves'] / $total_places) * 100)) : 0;

// Nombre max d'élèves dans une classe (pour les barres de progression)
$max_eleves_classe = 0;
foreach ($repartition_classes as $c) {
    if ((int) $c['total'] > $max_eleves_classe) {
        $max_eleves_classe = (int) $c['total'];
    }
}
$max_eleves_classe = max($max_eleves_classe, 1); // éviter division par 0
?>

<!-- ========================================
     DASHBOARD HEADER
     ======================================== -->
<div class="dashboard-header mb-4">
    <div class="row align-items-center">
        <div class="col-lg-8">
            <h1 class="dashboard-title">
                <span class="title-icon">
                    <i class="bi bi-mortarboard-fill"></i>
                </span>
                Tableau de bord
            </h1>
            <p class="dashboard-subtitle text-muted">
                Bonjour <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></strong>,
                voici la vue d'ensemble du système de gestion des notes scolaires.
            </p>
        </div>
        <div class="col-lg-4 text-lg-end">
            <div class="dashboard-actions">
                <span class="badge bg-light text-dark border me-2">
                    <i class="bi bi-calendar3"></i> <?php echo date('d/m/Y'); ?>
                </span>
                <span class="badge bg-dark">
                    <i class="bi bi-clock"></i> <?php echo date('H:i'); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- ========================================
     KPI CARDS
     ======================================== -->
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-lg-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-content">
                <div class="kpi-icon">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Élèves</span>
                    <span class="kpi-value"><?php echo $stats['eleves']; ?></span>
                </div>
            </div>
            <div class="kpi-footer">
                <a href="modules/eleves/index.php" class="kpi-link">
                    Voir la liste <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-content">
                <div class="kpi-icon">
                    <i class="bi bi-building-fill"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Classes</span>
                    <span class="kpi-value"><?php echo $stats['classes']; ?></span>
                </div>
            </div>
            <div class="kpi-footer">
                <a href="modules/classes/index.php" class="kpi-link">
                    Voir la liste <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-content">
                <div class="kpi-icon">
                    <i class="bi bi-book-fill"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Matières</span>
                    <span class="kpi-value"><?php echo $stats['matieres']; ?></span>
                </div>
            </div>
            <div class="kpi-footer">
                <a href="modules/matieres/index.php" class="kpi-link">
                    Voir la liste <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-content">
                <div class="kpi-icon">
                    <i class="bi bi-pencil-fill"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Notes</span>
                    <span class="kpi-value"><?php echo $stats['notes']; ?></span>
                </div>
            </div>
            <div class="kpi-footer">
                <a href="modules/notes/index.php" class="kpi-link">
                    Gérer les notes <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-content">
                <div class="kpi-icon">
                    <i class="bi bi-person-badge-fill"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Enseignants</span>
                    <span class="kpi-value"><?php echo $stats['enseignants']; ?></span>
                </div>
            </div>
            <div class="kpi-footer">
                <a href="modules/enseignants/index.php" class="kpi-link">
                    Voir la liste <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-content">
                <div class="kpi-icon">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-label">Taux remplissage</span>
                    <span class="kpi-value"><?php echo $taux_remplissage; ?>%</span>
                </div>
            </div>
            <div class="kpi-footer">
                <div class="progress" style="height: 4px;">
                    <div class="progress-bar bg-dark" role="progressbar"
                         style="width: <?php echo $taux_remplissage; ?>%;"
                         aria-valuenow="<?php echo $taux_remplissage; ?>"
                         aria-valuemin="0" aria-valuemax="100">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================
     CONTENU PRINCIPAL
     ======================================== -->
<div class="row g-4">
    <!-- Dernières notes -->
    <div class="col-lg-7">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="card-icon">
                            <i class="bi bi-clock-history"></i>
                        </span>
                        <span class="card-title">Dernières notes</span>
                    </div>
                    <span class="card-badge">5 dernières</span>
                </div>
            </div>
            <div class="dashboard-card-body">
                <?php if (count($dernieres_notes) > 0): ?>
                    <div class="notes-list">
                        <?php foreach ($dernieres_notes as $note): ?>
                            <div class="note-item">
                                <div class="note-avatar">
                                    <?php echo strtoupper(
                                        substr($note['prenom'] ?? '', 0, 1) .
                                        substr($note['nom'] ?? '', 0, 1)
                                    ); ?>
                                </div>
                                <div class="note-content">
                                    <div class="note-student">
                                        <?php echo htmlspecialchars(($note['nom'] ?? '') . ' ' . ($note['prenom'] ?? '')); ?>
                                    </div>
                                    <div class="note-details">
                                        <span class="note-subject">
                                            <i class="bi bi-book"></i>
                                            <?php echo htmlspecialchars($note['nom_matiere'] ?? ''); ?>
                                        </span>
                                        <span class="note-date">
                                            <i class="bi bi-calendar3"></i>
                                            <?php
                                            $date = $note['date_note'] ?? null;
                                            echo $date ? date('d/m/Y', strtotime($date)) : '—';
                                            ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="note-score">
                                    <?php $valeur = (float) ($note['valeur'] ?? 0); ?>
                                    <span class="score-badge <?php echo $valeur >= 10 ? 'score-high' : 'score-low'; ?>">
                                        <?php echo number_format($valeur, 2); ?>/20
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>Aucune note enregistrée</p>
                        <a href="modules/notes/saisir.php" class="btn btn-dark btn-sm">
                            <i class="bi bi-plus-circle"></i> Saisir une note
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="dashboard-card-footer">
                <a href="modules/notes/index.php" class="card-footer-link">
                    Voir toutes les notes <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Meilleurs élèves -->
    <div class="col-lg-5">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="card-icon">
                            <i class="bi bi-trophy-fill"></i>
                        </span>
                        <span class="card-title">Meilleurs élèves</span>
                    </div>
                    <span class="card-badge">Top 5</span>
                </div>
            </div>
            <div class="dashboard-card-body">
                <?php if (count($meilleurs_eleves) > 0): ?>
                    <div class="ranking-list">
                        <?php foreach ($meilleurs_eleves as $index => $eleve): ?>
                            <div class="ranking-item">
                                <div class="ranking-position <?php
                                    echo $index === 0 ? 'position-1' :
                                        ($index === 1 ? 'position-2' :
                                        ($index === 2 ? 'position-3' : 'position-other'));
                                ?>">
                                    <?php echo $index + 1; ?>
                                </div>
                                <div class="ranking-info">
                                    <div class="ranking-name">
                                        <?php echo htmlspecialchars(($eleve['nom'] ?? '') . ' ' . ($eleve['prenom'] ?? '')); ?>
                                    </div>
                                    <div class="ranking-details">
                                        <span class="ranking-notes">
                                            <i class="bi bi-pencil"></i>
                                            <?php echo (int) ($eleve['nb_notes'] ?? 0); ?> notes
                                        </span>
                                    </div>
                                </div>
                                <div class="ranking-score">
                                    <span class="score-number">
                                        <?php echo number_format((float) ($eleve['moyenne'] ?? 0), 2); ?>
                                    </span>
                                    <span class="score-max">/20</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-people"></i>
                        <p>Pas assez de données</p>
                        <span class="text-muted small">Minimum 3 notes par élève requis</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ========================================
     ACTIONS RAPIDES & RÉPARTITION
     ======================================== -->
<div class="row g-4 mt-2">
    <!-- Répartition par classe -->
    <div class="col-lg-5">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="card-icon">
                            <i class="bi bi-pie-chart-fill"></i>
                        </span>
                        <span class="card-title">Répartition par classe</span>
                    </div>
                </div>
            </div>
            <div class="dashboard-card-body">
                <?php if (count($repartition_classes) > 0): ?>
                    <div class="class-distribution">
                        <?php foreach ($repartition_classes as $classe): ?>
                            <?php
                            $nb = (int) $classe['total'];
                            $pct = round(($nb / $max_eleves_classe) * 100);
                            ?>
                            <div class="class-item">
                                <div class="class-info">
                                    <span class="class-name">
                                        <?php echo htmlspecialchars($classe['nom_classe'] ?? ''); ?>
                                    </span>
                                    <span class="class-count">
                                        <?php echo $nb; ?> élève<?php echo $nb > 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                                <div class="class-progress">
                                    <div class="progress-bar-custom" style="width: <?php echo $pct; ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-building"></i>
                        <p>Aucune classe enregistrée</p>
                        <?php if (hasRole(ROLES_GESTION)): ?>
                        <a href="modules/classes/ajouter.php" class="btn btn-dark btn-sm">
                            <i class="bi bi-plus-circle"></i> Créer une classe
                        </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actions rapides -->
    <div class="col-lg-7">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="card-icon">
                            <i class="bi bi-lightning-fill"></i>
                        </span>
                        <span class="card-title">Actions rapides</span>
                    </div>
                </div>
            </div>
            <div class="dashboard-card-body">
                <div class="actions-grid">
                    <?php if (hasRole(ROLES_GESTION)): ?>
                    <a href="modules/eleves/ajouter.php" class="action-item">
                        <div class="action-icon">
                            <i class="bi bi-person-plus"></i>
                        </div>
                        <div class="action-label">Ajouter un élève</div>
                    </a>
                    <?php endif; ?>
                    <a href="modules/notes/saisir.php" class="action-item">
                        <div class="action-icon">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                        <div class="action-label">Saisir des notes</div>
                    </a>
                    <?php if (hasRole(ROLES_GESTION)): ?>
                    <a href="modules/classes/ajouter.php" class="action-item">
                        <div class="action-icon">
                            <i class="bi bi-building-add"></i>
                        </div>
                        <div class="action-label">Créer une classe</div>
                    </a>
                    <?php endif; ?>
                    <a href="modules/bulletins/index.php" class="action-item">
                        <div class="action-icon">
                            <i class="bi bi-file-pdf"></i>
                        </div>
                        <div class="action-label">Générer un bulletin</div>
                    </a>
                    <?php if (hasRole(ROLES_GESTION)): ?>
                    <a href="modules/matieres/ajouter.php" class="action-item">
                        <div class="action-icon">
                            <i class="bi bi-book-plus"></i>
                        </div>
                        <div class="action-label">Ajouter une matière</div>
                    </a>
                    <?php endif; ?>
                    <a href="modules/notes/consulter.php" class="action-item">
                        <div class="action-icon">
                            <i class="bi bi-eye"></i>
                        </div>
                        <div class="action-label">Consulter les notes</div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================
     FOOTER INFO
     ======================================== -->
<div class="row mt-4">
    <div class="col-12">
        <div class="info-footer">
            <div class="info-footer-content">
                <div class="info-footer-left">
                    <i class="bi bi-check-circle-fill text-success"></i>
                    <span>Système basé sur la méthode MERISE</span>
                </div>
                <div class="info-footer-center">
                    <span class="badge bg-light text-dark border">MCD</span>
                    <span class="badge bg-light text-dark border">MLD</span>
                    <span class="badge bg-light text-dark border">MPD</span>
                    <span class="badge bg-light text-dark border">MCT</span>
                </div>
                <div class="info-footer-right">
                    <i class="bi bi-clock"></i>
                    <span>Mis à jour le <?php echo date('d/m/Y à H:i'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ========================================
   DASHBOARD PROFESSIONNEL
   ======================================== */

:root {
    --primary-dark: #1a202c;
    --primary-light: #2d3748;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
}

/* Dashboard Header */
.dashboard-header {
    padding: 20px 0 10px 0;
}

.dashboard-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--primary-dark);
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.dashboard-title .title-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: var(--primary-dark);
    color: white;
    border-radius: 12px;
    font-size: 1.25rem;
}

.dashboard-subtitle {
    font-size: 0.95rem;
    color: var(--gray-500);
    margin: 8px 0 0 56px;
}

.dashboard-actions .badge {
    padding: 8px 14px;
    font-weight: 500;
}

/* KPI Cards */
.kpi-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 16px 20px;
    transition: all 0.25s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.kpi-card:hover {
    border-color: var(--gray-300);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    transform: translateY(-2px);
}

.kpi-content {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
}

.kpi-icon {
    width: 48px;
    height: 48px;
    background: var(--gray-50);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--gray-600);
    flex-shrink: 0;
}

.kpi-info {
    flex: 1;
    min-width: 0;
}

.kpi-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-400);
}

.kpi-value {
    display: block;
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--primary-dark);
    line-height: 1.2;
}

.kpi-footer {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--gray-100);
}

.kpi-link {
    font-size: 0.8rem;
    color: var(--gray-500);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
}

.kpi-link:hover {
    color: var(--primary-dark);
    gap: 8px;
}

.kpi-link i {
    font-size: 0.6rem;
}

/* Dashboard Cards */
.dashboard-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    overflow: hidden;
    height: 100%;
    transition: all 0.25s ease;
}

.dashboard-card:hover {
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
}

.dashboard-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--gray-100);
    background: var(--gray-50);
}

.card-icon {
    font-size: 1rem;
    color: var(--gray-500);
    margin-right: 8px;
}

.card-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--primary-dark);
}

.card-badge {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--gray-500);
    background: var(--gray-100);
    padding: 4px 12px;
    border-radius: 50px;
}

.dashboard-card-body {
    padding: 16px 20px;
    flex: 1;
}

.dashboard-card-footer {
    padding: 12px 20px;
    border-top: 1px solid var(--gray-100);
    background: var(--gray-50);
}

.card-footer-link {
    font-size: 0.85rem;
    color: var(--gray-600);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.card-footer-link:hover {
    color: var(--primary-dark);
    gap: 10px;
}

/* Notes List */
.notes-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.note-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.note-item:hover {
    background: var(--gray-50);
    border-color: var(--gray-200);
}

.note-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--gray-600);
    flex-shrink: 0;
}

.note-content {
    flex: 1;
    min-width: 0;
}

.note-student {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--primary-dark);
}

.note-details {
    display: flex;
    gap: 12px;
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-top: 2px;
}

.note-details i {
    font-size: 0.65rem;
}

.note-subject,
.note-date {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.note-score {
    flex-shrink: 0;
}

.score-badge {
    font-weight: 700;
    font-size: 0.85rem;
    padding: 4px 12px;
    border-radius: 50px;
}

.score-high {
    background: #dcfce7;
    color: #166534;
}

.score-low {
    background: #fee2e2;
    color: #991b1b;
}

/* Ranking List */
.ranking-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ranking-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.ranking-item:hover {
    background: var(--gray-50);
    border-color: var(--gray-200);
}

.ranking-position {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
    color: white;
}

.position-1 { background: #f59e0b; }
.position-2 { background: #94a3b8; }
.position-3 { background: #cd7f32; }
.position-other {
    background: var(--gray-200);
    color: var(--gray-600);
}

.ranking-info {
    flex: 1;
    min-width: 0;
}

.ranking-name {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--primary-dark);
}

.ranking-details {
    font-size: 0.7rem;
    color: var(--gray-500);
}

.ranking-notes {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.ranking-score {
    text-align: right;
    flex-shrink: 0;
}

.score-number {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--primary-dark);
}

.score-max {
    font-size: 0.7rem;
    color: var(--gray-400);
}

/* Class Distribution */
.class-distribution {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.class-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.class-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.class-name {
    font-weight: 500;
    font-size: 0.85rem;
    color: var(--primary-dark);
}

.class-count {
    font-size: 0.8rem;
    color: var(--gray-500);
}

.class-progress {
    width: 100%;
    height: 4px;
    background: var(--gray-100);
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar-custom {
    height: 100%;
    background: var(--primary-dark);
    border-radius: 4px;
    transition: width 0.6s ease;
}

/* Actions Grid */
.actions-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.action-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 16px 12px;
    border-radius: 10px;
    border: 1px solid var(--gray-200);
    text-decoration: none;
    color: var(--gray-600);
    transition: all 0.25s ease;
}

.action-item:hover {
    border-color: var(--gray-300);
    background: var(--gray-50);
    transform: translateY(-2px);
    text-decoration: none;
    color: var(--primary-dark);
}

.action-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--gray-50);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    color: var(--gray-600);
    transition: all 0.25s ease;
}

.action-item:hover .action-icon {
    background: var(--primary-dark);
    color: white;
}

.action-label {
    font-size: 0.75rem;
    font-weight: 500;
    text-align: center;
    line-height: 1.2;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 30px 20px;
}

.empty-state i {
    font-size: 2.5rem;
    color: var(--gray-300);
}

.empty-state p {
    margin: 12px 0 8px 0;
    color: var(--gray-600);
    font-weight: 500;
}

/* Info Footer */
.info-footer {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 16px 24px;
}

.info-footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.info-footer-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: var(--gray-600);
}

.info-footer-center {
    display: flex;
    gap: 6px;
}

.info-footer-center .badge {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 4px 12px;
}

.info-footer-right {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    color: var(--gray-500);
}

/* Responsive */
@media (max-width: 991.98px) {
    .dashboard-title { font-size: 1.5rem; }
    .dashboard-subtitle { margin-left: 0; }
    .actions-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 767.98px) {
    .dashboard-header { text-align: center; }
    .dashboard-title { justify-content: center; }
    .dashboard-actions { text-align: center !important; margin-top: 12px; }
    .info-footer-content { flex-direction: column; text-align: center; }
    .actions-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 575.98px) {
    .kpi-card .kpi-content { flex-direction: column; text-align: center; }
    .kpi-value { font-size: 1.5rem; }
    .note-item { flex-wrap: wrap; }
    .note-score { width: 100%; text-align: left; padding-left: 48px; }
    .ranking-item { flex-wrap: wrap; }
    .ranking-score { width: 100%; text-align: left; padding-left: 40px; }
    .actions-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>