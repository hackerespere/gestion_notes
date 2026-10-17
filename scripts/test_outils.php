<?php
// ========================================
// TESTS DES OUTILS DE L'ASSISTANT (sans modèle d'IA)
// Vérifie, sur les données de démonstration, que les outils respectent les droits de chaque rôle,
// renvoient les mêmes chiffres que l'application et ne transmettent pas de données personnelles.
//   php scripts/test_outils.php
// ========================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script en ligne de commande uniquement.');
}
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/ai/tools.php';

$db = (new Database())->getConnection();
$reussis = 0;
$echoues = 0;

function verifier($description, $condition) {
    global $reussis, $echoues;
    echo ($condition ? "  [OK]    " : "  [ÉCHEC] ") . $description . "\n";
    $condition ? $reussis++ : $echoues++;
}

// Simuler la session d'un compte (comme checkAuth() après la connexion)
function connecter($db, $email) {
    $stmt = $db->prepare("SELECT id, role, id_eleve, id_enseignant FROM users WHERE email = :e");
    $stmt->execute([':e' => $email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        exit("Compte $email introuvable : lancer d'abord php scripts/demo_data.php\n");
    }
    $_SESSION = ['user_id' => (int) $u['id'], 'user_role' => $u['role'],
                 'id_eleve' => $u['id_eleve'] ? (int) $u['id_eleve'] : null,
                 'id_enseignant' => $u['id_enseignant'] ? (int) $u['id_enseignant'] : null];
    return assistantTools($db, $u['role']);
}

function appeler($tools, $nom, array $args = []) {
    return ($tools[$nom]['run'])($args);
}

// Les sorties d'outils ne doivent contenir ni date de naissance ni adresse
function sansDonneesPersonnelles($db, $resultat) {
    $json = json_encode($resultat, JSON_UNESCAPED_UNICODE);
    foreach ($db->query("SELECT date_naiss, adresse FROM eleve")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        if (strpos($json, $e['date_naiss']) !== false || ($e['adresse'] && strpos($json, $e['adresse']) !== false)) {
            return false;
        }
    }
    return strpos($json, 'date_naiss') === false && strpos($json, 'adresse') === false;
}

$autre_eleve = function ($sauf) use ($db) {
    $stmt = $db->prepare("SELECT id_eleve, prenom, nom FROM eleve WHERE id_eleve <> :id ORDER BY id_eleve LIMIT 1");
    $stmt->execute([':id' => $sauf]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
};

// ---------- Élève ----------
echo "Élève (adam.blanc@demo.local)\n";
$tools = connecter($db, 'adam.blanc@demo.local');
$moi = $_SESSION['id_eleve'];
verifier("outils limités à aide_application, mes_notes, mon_bulletin",
    array_keys($tools) === ['aide_application', 'mes_notes', 'mon_bulletin']);

$attendu = getNotesParTrimestre($db, $moi);
$r = appeler($tools, 'mes_notes', ['id_trimestre' => 1]);
verifier("mes_notes T1 : moyenne générale identique à la page « Mes notes »",
    abs($r['trimestres'][0]['moyenne_generale'] - $attendu[1]['moyenne']) < 0.005);

$autre = $autre_eleve($moi);
$r = appeler($tools, 'mes_notes', ['id_eleve' => $autre['id_eleve']]);
verifier("mes_notes ignore un id_eleve fourni par le modèle (toujours l'élève connecté)",
    abs($r['trimestres'][0]['moyenne_generale'] - $attendu[1]['moyenne']) < 0.005);

$b = generateBulletin($db, $moi, 2);
$r = appeler($tools, 'mon_bulletin', ['id_trimestre' => 2]);
verifier("mon_bulletin T2 : moyenne et rang identiques au bulletin", $r['rang'] === $b['rang']
    && abs($r['moyenne_generale'] - $b['moyenne_generale']) < 0.005);
$classe = moyennesClasse($db, $b['eleve']['id_classe'], 2);
verifier("rang du bulletin = position dans le classement pondéré de la classe",
    array_search($moi, array_keys($classe)) + 1 === $b['rang']);
verifier("aucune donnée personnelle (date de naissance, adresse) transmise", sansDonneesPersonnelles($db, $r));
verifier("mon_bulletin sans trimestre : erreur explicite", isset(appeler($tools, 'mon_bulletin', [])['erreur']));

// ---------- Parent ----------
echo "\nParent (parent@demo.local)\n";
$tools = connecter($db, 'parent@demo.local');
$enfants = parentEleves($db);
$un_enfant = (int) array_key_first($enfants);
$non_enfant = $db->query("SELECT id_eleve FROM eleve WHERE id_eleve NOT IN (" . implode(',', array_keys($enfants)) . ") LIMIT 1")->fetchColumn();
verifier("outils : aide_application, mes_enfants, notes_enfant, bulletin_enfant",
    array_keys($tools) === ['aide_application', 'mes_enfants', 'notes_enfant', 'bulletin_enfant']);
verifier("mes_enfants renvoie " . count($enfants) . " enfant(s)", count(appeler($tools, 'mes_enfants')['enfants']) === count($enfants));
verifier("notes_enfant d'un de ses enfants : accepté", isset(appeler($tools, 'notes_enfant', ['id_eleve' => $un_enfant])['trimestres']));
verifier("notes_enfant d'un autre élève : refusé", isset(appeler($tools, 'notes_enfant', ['id_eleve' => (int) $non_enfant])['erreur']));
verifier("bulletin_enfant d'un autre élève : refusé", isset(appeler($tools, 'bulletin_enfant', ['id_eleve' => (int) $non_enfant, 'id_trimestre' => 1])['erreur']));

// ---------- Enseignant ----------
echo "\nEnseignant (martin@demo.local)\n";
$tools = connecter($db, 'martin@demo.local');
$mes = mesMatieres($db);
$autre_matiere = (int) $db->query("SELECT id_matiere FROM matiere WHERE id_matiere NOT IN (" . implode(',', array_keys($mes)) . ") LIMIT 1")->fetchColumn();
verifier("pas d'outils de direction (stats_globales, matieres_sans_notes)",
    !isset($tools['stats_globales']) && !isset($tools['matieres_sans_notes']));
verifier("pas d'outils élève/parent (mes_notes, notes_enfant)", !isset($tools['mes_notes']) && !isset($tools['notes_enfant']));

$classe = $db->query("SELECT id_classe FROM classe ORDER BY id_classe LIMIT 1")->fetchColumn();
$moyennes = moyennesClasse($db, (int) $classe, 1);
$r = appeler($tools, 'stats_classe', ['id_classe' => (int) $classe, 'id_trimestre' => 1]);
verifier("stats_classe : moyenne de classe identique à la fiche classe",
    abs($r['moyenne_classe'] - array_sum($moyennes) / count($moyennes)) < 0.005);
verifier("stats_classe : aucune donnée personnelle", sansDonneesPersonnelles($db, $r));

$r = appeler($tools, 'eleves_en_difficulte', ['id_classe' => (int) $classe, 'id_trimestre' => 1, 'seuil' => 10]);
verifier("eleves_en_difficulte : même nombre que les moyennes < 10 de la classe",
    $r['nb'] === count(array_filter($moyennes, fn($m) => $m < 10)));

$r = appeler($tools, 'rechercher_eleve', ['texte' => $autre['nom']]);
verifier("rechercher_eleve trouve « {$autre['prenom']} {$autre['nom']} »", in_array((string) $autre['id_eleve'], array_map('strval', array_column($r['eleves'], 'id_eleve')), true));
verifier("rechercher_eleve : « % » ne renvoie pas toute la base", count(appeler($tools, 'rechercher_eleve', ['texte' => '%'])['eleves']) === 0);

$nb_notes = (int) $db->query("SELECT COUNT(*) FROM note")->fetchColumn();
$r = appeler($tools, 'proposer_saisie_note', ['id_eleve' => $moi, 'id_matiere' => (int) array_key_first($mes), 'id_trimestre' => 1, 'valeur' => 14]);
verifier("proposer_saisie_note dans sa matière : proposition avec lien vers le formulaire",
    isset($r['_action']) && strpos($r['_action']['url'], 'modules/notes/saisir.php?') === 0);
$r = appeler($tools, 'proposer_saisie_note', ['id_eleve' => $moi, 'id_matiere' => $autre_matiere, 'id_trimestre' => 1, 'valeur' => 14]);
verifier("proposer_saisie_note hors de ses matières : refusé", isset($r['erreur']) && !isset($r['_action']));
$r = appeler($tools, 'proposer_saisie_note', ['id_eleve' => $moi, 'id_matiere' => (int) array_key_first($mes), 'id_trimestre' => 1, 'valeur' => 25]);
verifier("proposer_saisie_note avec 25/20 : refusé", isset($r['erreur']));
verifier("aucune note n'a été enregistrée par les propositions", $nb_notes === (int) $db->query("SELECT COUNT(*) FROM note")->fetchColumn());

// ---------- Direction ----------
echo "\nDirection (directeur@demo.local)\n";
$tools = connecter($db, 'directeur@demo.local');
verifier("outils de direction présents", isset($tools['stats_globales'], $tools['matieres_sans_notes']));
$r = appeler($tools, 'stats_globales', ['id_trimestre' => 1]);
verifier("stats_globales : une ligne par classe", count($r['classes']) === (int) $db->query("SELECT COUNT(*) FROM classe")->fetchColumn());
verifier("matieres_sans_notes T3 : réponse exploitable", isset(appeler($tools, 'matieres_sans_notes', ['id_trimestre' => 3])['matieres_sans_notes_par_classe']));

echo "\n$reussis réussi(s), $echoues échec(s)\n";
exit($echoues > 0 ? 1 : 0);
