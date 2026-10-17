<?php
// ========================================
// SUPERVISION DE L'ASSISTANT IA (administrateurs uniquement)
// Indicateurs tirés de ai_logs : volume, disponibilité, latence, bascules, cache, coûts, avis,
// réponses signalées pour relecture humaine ; test de disponibilité des fournisseurs.
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/ai/Assistant.php';

requireRole(ROLES_ADMIN);

$db = (new Database())->getConnection();

$periodes = ['1' => 'Dernières 24 h', '7' => '7 derniers jours', '30' => '30 derniers jours'];
$jours = isset($periodes[$_GET['jours'] ?? '']) ? (int) $_GET['jours'] : 7;
$depuis = "created_at > NOW() - INTERVAL $jours DAY";   // $jours est un entier de la liste ci-dessus

// Indicateurs globaux
$g = $db->query("SELECT COUNT(*) AS total,
                        SUM(statut = 'ok') AS ok, SUM(statut = 'cache') AS cache, SUM(statut = 'secours') AS secours,
                        SUM(statut = 'erreur') AS erreur, SUM(statut = 'limite') AS limite,
                        SUM(statut = 'ok' AND echecs IS NOT NULL) AS bascules,
                        AVG(CASE WHEN statut = 'ok' THEN latence_ms END) AS latence_moy,
                        SUM(prompt_tokens) AS pt, SUM(completion_tokens) AS ct, SUM(cout) AS cout,
                        SUM(avis = 1) AS utiles, SUM(avis = -1) AS inutiles, SUM(signale) AS signales,
                        COUNT(DISTINCT id_user) AS utilisateurs
                 FROM ai_logs WHERE $depuis")->fetch(PDO::FETCH_ASSOC);
$total = (int) $g['total'];
$traitees = $total - (int) $g['limite'];
$repondues = (int) $g['ok'] + (int) $g['cache'] + (int) $g['secours'];
$pct = fn($n, $sur) => $sur > 0 ? round(100 * $n / $sur, 1) . ' %' : '—';

// Latence au 95e centile (réponses du modèle)
$latences = $db->query("SELECT latence_ms FROM ai_logs WHERE statut = 'ok' AND $depuis ORDER BY latence_ms")->fetchAll(PDO::FETCH_COLUMN);
$p95 = $latences ? (int) $latences[(int) floor(0.95 * (count($latences) - 1))] : null;

$par_fournisseur = $db->query("SELECT fournisseur, modele, COUNT(*) AS nb, AVG(latence_ms) AS latence,
                                      SUM(prompt_tokens + completion_tokens) AS tokens, SUM(cout) AS cout
                               FROM ai_logs WHERE statut = 'ok' AND $depuis
                               GROUP BY fournisseur, modele ORDER BY nb DESC")->fetchAll(PDO::FETCH_ASSOC);

// Échecs par fournisseur (colonne echecs : "groq:timeout, groq:http_429")
$echecs = [];
foreach ($db->query("SELECT echecs FROM ai_logs WHERE echecs IS NOT NULL AND $depuis")->fetchAll(PDO::FETCH_COLUMN) as $ligne) {
    foreach (explode(', ', $ligne) as $e) {
        $echecs[$e] = ($echecs[$e] ?? 0) + 1;
    }
}
arsort($echecs);

$par_role = $db->query("SELECT role, COUNT(*) AS nb, SUM(avis = 1) AS utiles, SUM(avis = -1) AS inutiles
                        FROM ai_logs WHERE $depuis GROUP BY role ORDER BY nb DESC")->fetchAll(PDO::FETCH_ASSOC);

$outils = [];
foreach ($db->query("SELECT outils FROM ai_logs WHERE outils IS NOT NULL AND $depuis")->fetchAll(PDO::FETCH_COLUMN) as $ligne) {
    foreach (explode(', ', $ligne) as $o) {
        $outils[$o] = ($outils[$o] ?? 0) + 1;
    }
}
arsort($outils);

// Réponses signalées : relecture humaine (le compte est affiché pour pouvoir recontacter l'utilisateur)
$signalements = $db->query("SELECT l.created_at, l.role, l.fournisseur, l.outils, u.email
                            FROM ai_logs l JOIN users u ON u.id = l.id_user
                            WHERE l.signale = 1 AND l.$depuis ORDER BY l.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$derniers = $db->query("SELECT created_at, role, statut, fournisseur, echecs, outils, latence_ms,
                               prompt_tokens + completion_tokens AS tokens, avis
                        FROM ai_logs WHERE $depuis ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

// Test de disponibilité des fournisseurs (à la demande : chaque test est un vrai appel au modèle)
$tests = [];
if (isPostWithValidCsrf() && isset($_POST['tester'])) {
    foreach (Assistant::providersFromEnv() as $provider) {
        $debut = microtime(true);
        try {
            $provider->chat([['role' => 'user', 'content' => 'Réponds seulement : OK']], []);
            $etat = ['ok' => true, 'detail' => 'Disponible'];
        } catch (AIProviderException $e) {
            $etat = ['ok' => false, 'detail' => $e->getMessage()];
        }
        $tests[] = $etat + ['nom' => $provider->name(), 'modele' => $provider->model(),
                            'latence' => (int) round((microtime(true) - $debut) * 1000)];
    }
}

$statuts = ['ok' => 'success', 'cache' => 'info', 'secours' => 'warning', 'erreur' => 'danger', 'limite' => 'secondary'];

require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi bi-activity"></i></span>
                Supervision de l'assistant IA
            </h1>
            <p class="page-subtitle text-muted">Disponibilité, latence, coûts et qualité perçue — le texte des questions n'est pas conservé</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($periodes as $j => $libelle): ?>
                <a href="?jours=<?php echo $j; ?>" class="btn btn-sm <?php echo (int) $j === $jours ? 'btn-dark' : 'btn-outline-secondary'; ?>"><?php echo $libelle; ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Questions', $total, $g['utilisateurs'] . ' utilisateur(s)'],
        ['Taux de réponse', $pct($repondues, $traitees), (int) $g['erreur'] . ' sans réponse'],
        ['Latence moyenne', $g['latence_moy'] !== null ? number_format($g['latence_moy'] / 1000, 2, ',', ' ') . ' s' : '—', 'p95 : ' . ($p95 !== null ? number_format($p95 / 1000, 2, ',', ' ') . ' s' : '—')],
        ['Bascules de fournisseur', (int) $g['bascules'], 'réponses obtenues après un échec'],
        ['Cache', (int) $g['cache'], (int) $g['secours'] . ' réponse(s) de secours'],
        ['Quota atteint', (int) $g['limite'], 'max ' . (int) env('AI_RATE_LIMIT', 30) . ' questions / heure'],
        ['Tokens', number_format((int) $g['pt'] + (int) $g['ct'], 0, ',', ' '), number_format((int) $g['pt'], 0, ',', ' ') . ' entrée / ' . number_format((int) $g['ct'], 0, ',', ' ') . ' sortie'],
        ['Coût estimé', '$' . number_format((float) $g['cout'], 4), (int) $g['ok'] > 0 ? '$' . number_format(1000 * (float) $g['cout'] / (int) $g['ok'], 2) . ' / 1 000 questions (tarifs du .env)' : 'tarifs payants du .env'],
        ['Avis', '👍 ' . (int) $g['utiles'] . ' · 👎 ' . (int) $g['inutiles'], 'satisfaction : ' . $pct((int) $g['utiles'], (int) $g['utiles'] + (int) $g['inutiles'])],
        ['Signalements', (int) $g['signales'], 'à relire par un humain'],
    ] as [$titre, $valeur, $detail]): ?>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="card voir-card stat-card h-100">
            <span class="text-muted"><?php echo htmlspecialchars($titre); ?></span>
            <strong><?php echo htmlspecialchars((string) $valeur); ?></strong>
            <small class="text-muted"><?php echo htmlspecialchars((string) $detail); ?></small>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card voir-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hdd-network"></i> Fournisseurs</span>
                <form method="post" class="m-0">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="tester" value="1" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-broadcast"></i> Tester la disponibilité
                    </button>
                </form>
            </div>
            <?php if ($tests): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($tests as $t): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span><i class="bi bi-circle-fill text-<?php echo $t['ok'] ? 'success' : 'danger'; ?>"></i>
                        <strong><?php echo htmlspecialchars($t['nom']); ?></strong>
                        <span class="text-muted small"><?php echo htmlspecialchars($t['modele']); ?></span></span>
                    <span class="small"><?php echo htmlspecialchars($t['detail']); ?> · <?php echo $t['latence']; ?> ms</span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Fournisseur</th><th>Réponses</th><th>Latence moy.</th><th>Tokens</th><th>Coût</th></tr></thead>
                    <tbody>
                        <?php if (!$par_fournisseur): ?><tr><td colspan="5" class="text-muted text-center">Aucune réponse sur la période</td></tr><?php endif; ?>
                        <?php foreach ($par_fournisseur as $f): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($f['fournisseur']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($f['modele']); ?></small></td>
                            <td><?php echo (int) $f['nb']; ?></td>
                            <td><?php echo number_format($f['latence'] / 1000, 2, ',', ' '); ?> s</td>
                            <td><?php echo number_format((int) $f['tokens'], 0, ',', ' '); ?></td>
                            <td>$<?php echo number_format((float) $f['cout'], 4); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($echecs): ?>
            <div class="card-body border-top">
                <div class="small text-muted mb-1">Échecs (fournisseur : cause)</div>
                <?php foreach ($echecs as $e => $nb): ?>
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($e); ?> × <?php echo $nb; ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="card voir-card h-100">
            <div class="card-header"><i class="bi bi-people"></i> Par rôle</div>
            <ul class="list-group list-group-flush">
                <?php if (!$par_role): ?><li class="list-group-item text-muted">Aucune question</li><?php endif; ?>
                <?php foreach ($par_role as $r): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span><?php echo htmlspecialchars($r['role']); ?></span>
                    <span><?php echo (int) $r['nb']; ?> <small class="text-muted">(👍 <?php echo (int) $r['utiles']; ?> · 👎 <?php echo (int) $r['inutiles']; ?>)</small></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="card voir-card h-100">
            <div class="card-header"><i class="bi bi-tools"></i> Outils utilisés</div>
            <ul class="list-group list-group-flush">
                <?php if (!$outils): ?><li class="list-group-item text-muted">Aucun appel d'outil</li><?php endif; ?>
                <?php foreach ($outils as $o => $nb): ?>
                <li class="list-group-item d-flex justify-content-between"><code><?php echo htmlspecialchars($o); ?></code><span><?php echo $nb; ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-12">
        <div class="card voir-card">
            <div class="card-header"><i class="bi bi-flag"></i> Réponses signalées (relecture humaine)</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Date</th><th>Compte</th><th>Rôle</th><th>Fournisseur</th><th>Outils</th></tr></thead>
                    <tbody>
                        <?php if (!$signalements): ?><tr><td colspan="5" class="text-muted text-center">Aucun signalement</td></tr><?php endif; ?>
                        <?php foreach ($signalements as $s): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($s['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($s['email']); ?></td>
                            <td><?php echo htmlspecialchars($s['role']); ?></td>
                            <td><?php echo htmlspecialchars((string) $s['fournisseur']); ?></td>
                            <td><code><?php echo htmlspecialchars((string) $s['outils']); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card voir-card">
            <div class="card-header"><i class="bi bi-list-ul"></i> Derniers échanges</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Date</th><th>Rôle</th><th>Statut</th><th>Fournisseur</th><th>Échecs</th><th>Outils</th><th>Latence</th><th>Tokens</th><th>Avis</th></tr></thead>
                    <tbody>
                        <?php if (!$derniers): ?><tr><td colspan="9" class="text-muted text-center">Aucun échange</td></tr><?php endif; ?>
                        <?php foreach ($derniers as $d): ?>
                        <tr>
                            <td><?php echo date('d/m H:i:s', strtotime($d['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($d['role']); ?></td>
                            <td><span class="badge bg-<?php echo $statuts[$d['statut']]; ?>"><?php echo htmlspecialchars($d['statut']); ?></span></td>
                            <td><?php echo htmlspecialchars((string) $d['fournisseur']); ?></td>
                            <td class="small text-danger"><?php echo htmlspecialchars((string) $d['echecs']); ?></td>
                            <td><code class="small"><?php echo htmlspecialchars((string) $d['outils']); ?></code></td>
                            <td><?php echo number_format($d['latence_ms'] / 1000, 2, ',', ' '); ?> s</td>
                            <td><?php echo (int) $d['tokens']; ?></td>
                            <td><?php echo $d['avis'] === null ? '' : ((int) $d['avis'] === 1 ? '👍' : '👎'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
