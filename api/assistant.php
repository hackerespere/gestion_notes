<?php
// ========================================
// API DE L'ASSISTANT IA — contrat version 1.0 (détail : docs/contrat_api.md)
//
// Entrée : POST, en-tête X-CSRF-Token, corps JSON, une des formes :
//   { "message": "texte de 1 à 1000 caractères" }
//   { "reinitialiser": true }
//   { "avis": { "id_echange": 12, "valeur": 1 | -1, "signaler": false } }
// Sortie (succès, HTTP 200) :
//   { "ok": true, "version": "1.0", "id_echange": 12, "reponse": "...", "source": "modele" | "cache" | "secours",
//     "fournisseur": "groq", "modele": "...", "outils": ["mes_notes"], "actions": [...], "latence_ms": 1234 }
// Sortie (erreur) : { "ok": false, "version": "1.0", "erreur": { "code": "...", "message": "..." } }
//   400 entree_invalide · 401 non_connecte · 403 csrf | acces_refuse · 405 methode
//   429 limite · 503 ia_indisponible
// ========================================
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/ai/Assistant.php';
require_once __DIR__ . '/../includes/ai/journal.php';

const CONTRAT_VERSION = '1.0';
const MESSAGE_MAX     = 1000;
const HISTORIQUE_MAX  = 10;     // messages conservés (5 échanges)

header('Content-Type: application/json');

function repondreJson($statut, array $corps) {
    http_response_code($statut);
    echo json_encode(['ok' => $statut === 200, 'version' => CONTRAT_VERSION] + $corps, JSON_UNESCAPED_UNICODE);
    exit;
}

function erreur($statut, $code, $message) {
    repondreJson($statut, ['erreur' => ['code' => $code, 'message' => $message]]);
}

// Ajouter l'échange à l'historique de la session (rouverte après l'appel au modèle)
function memoriser(array $historique, $reponse) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $historique[] = ['role' => 'assistant', 'content' => $reponse];
    $_SESSION['assistant_historique'] = array_slice($historique, -HISTORIQUE_MAX);
}

// ---------- Contrôles d'accès ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erreur(405, 'methode', 'Méthode POST attendue.');
}
// Comme checkAuth(), mais avec une réponse JSON 401 au lieu d'une redirection vers login.php
if (!isLoggedIn() || isSessionExpired() || !refreshAccount()) {
    erreur(401, 'non_connecte', 'Session expirée, reconnectez-vous.');
}
$_SESSION['last_activity'] = time();
if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    erreur(403, 'csrf', 'Requête invalide ou expirée. Rechargez la page.');
}
$role    = $_SESSION['user_role'];
$id_user = (int) $_SESSION['user_id'];
if ($role === 'user') {
    erreur(403, 'acces_refuse', "Votre compte n'a accès à aucune donnée.");
}

// ---------- Validation de l'entrée ----------
$entree = json_decode(file_get_contents('php://input'), true);
if (!is_array($entree)) {
    erreur(400, 'entree_invalide', 'Corps JSON attendu.');
}
$db = (new Database())->getConnection();

if (!empty($entree['reinitialiser'])) {
    $_SESSION['assistant_historique'] = [];
    repondreJson(200, ['reponse' => '']);
}

if (isset($entree['avis'])) {
    $avis = is_array($entree['avis']) ? $entree['avis'] : [];
    $valeur = (int) ($avis['valeur'] ?? 0);
    if (!in_array($valeur, [1, -1], true) || (int) ($avis['id_echange'] ?? 0) < 1) {
        erreur(400, 'entree_invalide', 'Avis invalide.');
    }
    $ok = aiAvis($db, (int) $avis['id_echange'], $id_user, $valeur, !empty($avis['signaler']));
    $ok ? repondreJson(200, ['reponse' => '']) : erreur(400, 'entree_invalide', 'Échange introuvable.');
}

$message = is_string($entree['message'] ?? null) ? trim($entree['message']) : '';
if ($message === '' || mb_strlen($message) > MESSAGE_MAX) {
    erreur(400, 'entree_invalide', 'Le message doit contenir entre 1 et ' . MESSAGE_MAX . ' caractères.');
}

// ---------- Quota (coût et abus) ----------
if (aiLimiteAtteinte($db, $id_user)) {
    aiJournaliser($db, ['id_user' => $id_user, 'role' => $role, 'statut' => 'limite']);
    erreur(429, 'limite', 'Vous avez posé beaucoup de questions en peu de temps. Réessayez dans un moment.');
}

// Historique : uniquement les textes échangés (pas les résultats d'outils), limité aux derniers messages
$historique   = $_SESSION['assistant_historique'] ?? [];
$premiere     = empty($historique);   // seule une première question peut venir du cache (pas de contexte)
$historique[] = ['role' => 'user', 'content' => $message];
$historique   = array_slice($historique, -HISTORIQUE_MAX);
$cle_cache    = aiCacheCle($id_user, $role, $message);

// ---------- Cache : même question récente → pas de nouvel appel au modèle ----------
if ($premiere && ($cache = aiCacheLire($db, $cle_cache, (int) env('AI_CACHE_TTL', 600)))) {
    $id = aiJournaliser($db, ['id_user' => $id_user, 'role' => $role, 'statut' => 'cache',
                              'fournisseur' => $cache['fournisseur'], 'modele' => $cache['modele']]);
    memoriser($historique, $cache['reponse']);
    repondreJson(200, ['id_echange' => $id, 'reponse' => $cache['reponse'], 'source' => 'cache',
                       'fournisseur' => $cache['fournisseur'], 'modele' => $cache['modele'],
                       'outils' => [], 'actions' => [], 'latence_ms' => 0]);
}

// ---------- Appel au modèle (avec nouvelles tentatives et bascule de fournisseur) ----------
$assistant = new Assistant(Assistant::providersFromEnv(), assistantTools($db, $role), $role, (int) env('AI_RETRIES', 1));

// Libérer la session pendant l'appel au modèle (plusieurs secondes) :
// sinon les autres requêtes de l'utilisateur (navigation, prolongation de session) attendent
session_write_close();

try {
    $r = $assistant->repondre($historique);
} catch (AIProviderException $e) {
    // Secours : une réponse déjà donnée à la même question dans les dernières 24 h
    $cache = aiCacheLire($db, $cle_cache, 86400);
    $id = aiJournaliser($db, ['id_user' => $id_user, 'role' => $role, 'statut' => $cache ? 'secours' : 'erreur',
                              'echecs' => $e->echecs, 'usage' => $e->usage, 'latence_ms' => $e->latence]);
    if ($cache) {
        memoriser($historique, $cache['reponse']);
        repondreJson(200, ['id_echange' => $id, 'reponse' => $cache['reponse'], 'source' => 'secours',
                           'fournisseur' => $cache['fournisseur'], 'modele' => $cache['modele'],
                           'outils' => [], 'actions' => [], 'latence_ms' => $e->latence,
                           'avertissement' => "L'assistant est indisponible : voici la réponse donnée le "
                                            . date('d/m/Y à H:i', strtotime($cache['created_at'])) . ". Elle peut ne plus être à jour."]);
    }
    erreur(503, 'ia_indisponible',
        "L'assistant est momentanément indisponible. Vos notes restent consultables dans l'application.");
}

$id = aiJournaliser($db, ['id_user' => $id_user, 'role' => $role, 'statut' => 'ok'] + $r);
// Une réponse qui propose une action n'est pas mise en cache : l'action doit être préparée à nouveau
if (!$r['actions']) {
    aiCacheEcrire($db, $cle_cache, $id_user, $r['reponse'], $r['fournisseur'], $r['modele']);
}
memoriser($historique, $r['reponse']);

repondreJson(200, [
    'id_echange'  => $id,
    'reponse'     => $r['reponse'],
    'source'      => 'modele',
    'fournisseur' => $r['fournisseur'],
    'modele'      => $r['modele'],
    'outils'      => $r['outils'],
    'actions'     => $r['actions'],
    'latence_ms'  => $r['latence_ms'],
]);
