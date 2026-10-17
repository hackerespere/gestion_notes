<?php
// ========================================
// JOURNAL, QUOTA, COÛT ET CACHE DE L'ASSISTANT
// - ai_logs  : un enregistrement par question (sans le texte) → supervision, quota, coûts, avis.
// - ai_cache : réponses récentes par utilisateur → même question = pas de nouvel appel au modèle,
//              et réponse de secours si tous les fournisseurs sont indisponibles.
// ========================================

// Coût estimé en dollars, d'après les tarifs {FOURNISSEUR}_PRICE_IN / _PRICE_OUT du .env ($ par million de tokens)
function aiCout($fournisseur, array $usage) {
    $prefix = strtoupper((string) $fournisseur);
    return ($usage['prompt_tokens'] * (float) env($prefix . '_PRICE_IN', 0)
          + $usage['completion_tokens'] * (float) env($prefix . '_PRICE_OUT', 0)) / 1000000;
}

// Enregistrer un échange ; renvoie son identifiant (utilisé pour l'avis de l'utilisateur)
function aiJournaliser($db, array $e) {
    $usage = $e['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0];
    $echecs = array_map(fn($x) => $x['fournisseur'] . ':' . $x['code'], $e['echecs'] ?? []);
    $stmt = $db->prepare("INSERT INTO ai_logs (id_user, role, statut, fournisseur, modele, echecs, outils, latence_ms,
                                               prompt_tokens, completion_tokens, cout)
                          VALUES (:user, :role, :statut, :fournisseur, :modele, :echecs, :outils, :latence,
                                  :pt, :ct, :cout)");
    $stmt->execute([
        ':user'        => $e['id_user'],
        ':role'        => $e['role'],
        ':statut'      => $e['statut'],
        ':fournisseur' => $e['fournisseur'] ?? null,
        ':modele'      => $e['modele'] ?? null,
        ':echecs'      => $echecs ? mb_substr(implode(', ', $echecs), 0, 255) : null,
        ':outils'      => !empty($e['outils']) ? mb_substr(implode(', ', $e['outils']), 0, 255) : null,
        ':latence'     => (int) ($e['latence_ms'] ?? 0),
        ':pt'          => (int) $usage['prompt_tokens'],
        ':ct'          => (int) $usage['completion_tokens'],
        ':cout'        => isset($e['fournisseur']) ? aiCout($e['fournisseur'], $usage) : 0,
    ]);
    return (int) $db->lastInsertId();
}

// Quota : nombre de questions par utilisateur et par heure (AI_RATE_LIMIT)
function aiLimiteAtteinte($db, $id_user) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM ai_logs
                          WHERE id_user = :user AND statut <> 'limite' AND created_at > NOW() - INTERVAL 1 HOUR");
    $stmt->execute([':user' => $id_user]);
    return (int) $stmt->fetchColumn() >= (int) env('AI_RATE_LIMIT', 30);
}

// Avis de l'utilisateur sur une de SES réponses : 1 utile, -1 pas utile ; $signale = à relire par un humain
function aiAvis($db, $id_log, $id_user, $avis, $signale) {
    $stmt = $db->prepare("UPDATE ai_logs SET avis = :avis, signale = GREATEST(signale, :signale)
                          WHERE id = :id AND id_user = :user");
    $stmt->execute([':avis' => $avis, ':signale' => $signale ? 1 : 0, ':id' => $id_log, ':user' => $id_user]);
    return $stmt->rowCount() > 0;
}

// Clé de cache : propre à l'utilisateur et à son rôle (les données diffèrent d'un utilisateur à l'autre),
// question normalisée (casse et espaces)
function aiCacheCle($id_user, $role, $question) {
    $q = preg_replace('/\s+/u', ' ', mb_strtolower(trim($question)));
    return hash('sha256', $id_user . '|' . $role . '|' . $q);
}

// Réponse en cache plus récente que $max_age secondes (null sinon)
function aiCacheLire($db, $cle, $max_age) {
    $stmt = $db->prepare("SELECT reponse, fournisseur, modele, created_at FROM ai_cache
                          WHERE cle = :cle AND created_at > NOW() - INTERVAL :age SECOND");
    $stmt->bindValue(':cle', $cle);
    $stmt->bindValue(':age', (int) $max_age, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aiCacheEcrire($db, $cle, $id_user, $reponse, $fournisseur, $modele) {
    $stmt = $db->prepare("REPLACE INTO ai_cache (cle, id_user, reponse, fournisseur, modele, created_at)
                          VALUES (:cle, :user, :reponse, :fournisseur, :modele, NOW())");
    $stmt->execute([':cle' => $cle, ':user' => $id_user, ':reponse' => $reponse,
                    ':fournisseur' => $fournisseur, ':modele' => $modele]);
    // Les réponses contiennent des données scolaires : ne pas les garder plus d'un jour
    $db->exec("DELETE FROM ai_cache WHERE created_at < NOW() - INTERVAL 1 DAY");
}
