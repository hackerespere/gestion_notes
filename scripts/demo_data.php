<?php
// ========================================
// DONNÉES DE DÉMONSTRATION
// Remplit la base avec des élèves, des notes sur les 3 trimestres, des enseignants
// affectés à leurs matières et des comptes de chaque rôle.
//
// Utilisation (en ligne de commande uniquement) :
//   php scripts/demo_data.php           -> refuse si la base contient déjà des élèves
//   php scripts/demo_data.php --reset   -> SUPPRIME tous les élèves, notes, affectations
//                                          et comptes @demo.local, puis recrée la démo
// Les données sont toujours les mêmes d'une exécution à l'autre (tirage à graine fixe).
// ========================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

const MOT_DE_PASSE_DEMO = 'Demo1234';

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
mt_srand(2026);

$reset = in_array('--reset', $argv, true);
$nb_eleves = (int) $db->query("SELECT COUNT(*) FROM eleve")->fetchColumn();

if ($nb_eleves > 0 && !$reset) {
    fwrite(STDERR, "La base contient déjà $nb_eleves élève(s). Relancez avec --reset pour tout effacer et recréer la démo.\n");
    exit(1);
}

// ----------------------------------------
// Référentiels attendus (créés par sql/database.sql)
// ----------------------------------------
$classes    = $db->query("SELECT id_classe, nom_classe FROM classe ORDER BY id_classe")->fetchAll();
$matieres   = $db->query("SELECT id_matiere, nom_matiere FROM matiere ORDER BY id_matiere")->fetchAll();
$trimestres = $db->query("SELECT id_trimestre, libelle, date_debut, date_fin FROM trimestre ORDER BY id_trimestre")->fetchAll();

if (!$classes || !$matieres || !$trimestres) {
    fwrite(STDERR, "Classes, matières ou trimestres manquants : importez d'abord sql/database.sql.\n");
    exit(1);
}

$id_matiere = array_column($matieres, 'id_matiere', 'nom_matiere');

$db->beginTransaction();
try {
    // ----------------------------------------
    // Remise à zéro
    // ----------------------------------------
    if ($reset) {
        foreach (['durant', 'porter_sur', 'saisir', 'obtenir', 'note', 'parent_eleve', 'enseigner'] as $table) {
            $db->exec("DELETE FROM $table");
        }
        $db->exec("DELETE FROM users WHERE email LIKE '%@demo.local'");
        $db->exec("DELETE FROM eleve");   // supprime aussi les comptes élèves (clé étrangère)
        $db->exec("DELETE FROM enseignant WHERE grade = 'Professeur (démo)'");
        echo "Base remise à zéro.\n";
    }

    // ----------------------------------------
    // Enseignants et matières enseignées
    // ----------------------------------------
    $affectations = [
        ['Dupont',  'Jean',    ['Mathématiques']],
        ['Martin',  'Sophie',  ['Français', 'Histoire-Géographie']],
        ['Bernard', 'Pierre',  ['Anglais']],
        ['Leroy',   'Claire',  ['SVT']],
        ['Moreau',  'Thomas',  ['Physique-Chimie']],
    ];

    $enseignant_de = [];   // id_matiere => id_enseignant (auteur des notes)
    $comptes_enseignants = [];
    foreach ($affectations as [$nom, $prenom, $noms_matieres]) {
        $stmt = $db->prepare("SELECT id_enseignant FROM enseignant WHERE nom = :nom AND prenom = :prenom");
        $stmt->execute([':nom' => $nom, ':prenom' => $prenom]);
        $id_ens = $stmt->fetchColumn();
        if (!$id_ens) {
            $db->prepare("INSERT INTO enseignant (nom, prenom, grade) VALUES (:nom, :prenom, 'Professeur (démo)')")
               ->execute([':nom' => $nom, ':prenom' => $prenom]);
            $id_ens = $db->lastInsertId();
        }
        foreach ($noms_matieres as $nom_matiere) {
            $db->prepare("INSERT IGNORE INTO enseigner (id_enseignant, id_matiere) VALUES (:e, :m)")
               ->execute([':e' => $id_ens, ':m' => $id_matiere[$nom_matiere]]);
            $enseignant_de[$id_matiere[$nom_matiere]] = (int) $id_ens;
        }
        $comptes_enseignants[] = [$nom, $prenom, (int) $id_ens];
    }

    // ----------------------------------------
    // Élèves : 8 par classe, avec un niveau propre à chacun
    // ----------------------------------------
    $prenoms_f = ['Léa', 'Emma', 'Chloé', 'Inès', 'Manon', 'Jade', 'Camille', 'Sarah', 'Louise', 'Zoé', 'Lina', 'Alice'];
    $prenoms_m = ['Lucas', 'Hugo', 'Nathan', 'Adam', 'Louis', 'Gabriel', 'Arthur', 'Noah', 'Jules', 'Ethan', 'Yanis', 'Paul'];
    $noms      = ['Petit', 'Durand', 'Lefebvre', 'Garcia', 'Roux', 'Fournier', 'Girard', 'Bonnet', 'Lambert', 'Fontaine',
                  'Rousseau', 'Vincent', 'Muller', 'Faure', 'Andre', 'Mercier', 'Blanc', 'Guerin', 'Boyer', 'Chevalier',
                  'Robin', 'Clement', 'Morel', 'Gauthier'];
    shuffle($noms);

    // Âge cohérent avec la classe (6ème ~ 11 ans en 2025)
    $annee_naissance = [];
    foreach ($classes as $i => $c) {
        $annee_naissance[$c['id_classe']] = 2014 - $i;
    }

    $eleves = [];
    $n = 0;
    foreach ($classes as $classe) {
        for ($k = 0; $k < 8; $k++, $n++) {
            $fille  = $k % 2 === 0;
            $prenom = $fille ? $prenoms_f[$n % count($prenoms_f)] : $prenoms_m[$n % count($prenoms_m)];
            $nom    = $noms[$n % count($noms)];
            $date   = sprintf('%d-%02d-%02d', $annee_naissance[$classe['id_classe']], mt_rand(1, 12), mt_rand(1, 28));

            $db->prepare("INSERT INTO eleve (nom, prenom, date_naiss, sexe, adresse, id_classe)
                          VALUES (:nom, :prenom, :date, :sexe, :adresse, :classe)")
               ->execute([':nom' => $nom, ':prenom' => $prenom, ':date' => $date, ':sexe' => $fille ? 'F' : 'M',
                          ':adresse' => mt_rand(1, 120) . ' rue des Écoles', ':classe' => $classe['id_classe']]);

            $eleves[] = [
                'id'      => (int) $db->lastInsertId(),
                'nom'     => $nom,
                'prenom'  => $prenom,
                'classe'  => $classe['nom_classe'],
                // Niveau général entre 6 et 17, avec des points forts et faibles par matière
                'niveau'  => mt_rand(60, 170) / 10,
                'ecarts'  => array_map(fn() => mt_rand(-30, 30) / 10, array_flip(array_column($matieres, 'id_matiere'))),
                'tendance' => mt_rand(-15, 15) / 10,   // progression (ou baisse) au fil de l'année
            ];
        }
    }

    // Une fratrie dans deux classes différentes, pour le compte parent
    $aine = $eleves[count($eleves) - 1];
    $cadet_index = 1;
    $db->prepare("UPDATE eleve SET nom = :nom WHERE id_eleve = :id")->execute([':nom' => $aine['nom'], ':id' => $eleves[$cadet_index]['id']]);
    $eleves[$cadet_index]['nom'] = $aine['nom'];

    // ----------------------------------------
    // Notes : 2 à 3 par matière et par trimestre, saisies par l'enseignant de la matière
    // ----------------------------------------
    $types = ['Devoir', 'Contrôle', 'Interrogation', 'Composition', 'TP'];
    $ins_note    = $db->prepare("INSERT INTO note (valeur, type_note, date_note) VALUES (:v, :t, :d)");
    $ins_obtenir = $db->prepare("INSERT INTO obtenir (id_eleve, id_note) VALUES (:e, :n)");
    $ins_porter  = $db->prepare("INSERT INTO porter_sur (id_note, id_matiere) VALUES (:n, :m)");
    $ins_durant  = $db->prepare("INSERT INTO durant (id_trimestre, id_note) VALUES (:t, :n)");
    $ins_saisir  = $db->prepare("INSERT INTO saisir (id_note, id_enseignant) VALUES (:n, :e)");

    $nb_notes = 0;
    foreach ($eleves as $eleve) {
        foreach ($trimestres as $t => $trimestre) {
            $debut = strtotime($trimestre['date_debut']);
            $fin   = strtotime($trimestre['date_fin']);
            foreach ($matieres as $matiere) {
                $mid = (int) $matiere['id_matiere'];
                $nb = mt_rand(2, 3);
                for ($i = 0; $i < $nb; $i++) {
                    $valeur = $eleve['niveau'] + $eleve['ecarts'][$mid] + $eleve['tendance'] * $t + mt_rand(-30, 30) / 10;
                    $valeur = max(0, min(20, round($valeur * 2) / 2));   // au demi-point, entre 0 et 20

                    $ins_note->execute([':v' => $valeur, ':t' => $types[mt_rand(0, count($types) - 1)],
                                        ':d' => date('Y-m-d', mt_rand($debut, $fin))]);
                    $id_note = $db->lastInsertId();
                    $ins_obtenir->execute([':e' => $eleve['id'], ':n' => $id_note]);
                    $ins_porter->execute([':n' => $id_note, ':m' => $mid]);
                    $ins_durant->execute([':t' => $trimestre['id_trimestre'], ':n' => $id_note]);
                    $ins_saisir->execute([':n' => $id_note, ':e' => $enseignant_de[$mid]]);
                    $nb_notes++;
                }
            }
        }
    }

    // ----------------------------------------
    // Comptes de démonstration (mot de passe commun)
    // ----------------------------------------
    $hash = password_hash(MOT_DE_PASSE_DEMO, PASSWORD_DEFAULT);
    $ins_user = $db->prepare("INSERT INTO users (nom, prenom, email, password, role, id_eleve, id_enseignant)
                              VALUES (:nom, :prenom, :email, :password, :role, :eleve, :ens)");
    $comptes = [];

    $ajouterCompte = function ($nom, $prenom, $email, $role, $id_eleve = null, $id_ens = null) use ($ins_user, $hash, $db, &$comptes) {
        $ins_user->execute([':nom' => $nom, ':prenom' => $prenom, ':email' => $email, ':password' => $hash,
                            ':role' => $role, ':eleve' => $id_eleve, ':ens' => $id_ens]);
        $comptes[] = [$role, $email, "$prenom $nom"];
        return (int) $db->lastInsertId();
    };

    $ajouterCompte('Directeur', 'Démo', 'directeur@demo.local', 'directeur');

    foreach ($comptes_enseignants as [$nom, $prenom, $id_ens]) {
        // Une fiche enseignant n'a qu'un compte : ne pas doubler un compte existant
        $stmt = $db->prepare("SELECT email FROM users WHERE id_enseignant = :e");
        $stmt->execute([':e' => $id_ens]);
        if ($existant = $stmt->fetchColumn()) {
            $comptes[] = ['enseignant', $existant, "$prenom $nom (compte existant, mot de passe inchangé)"];
            continue;
        }
        $ajouterCompte($nom, $prenom, strtolower($nom) . '@demo.local', 'enseignant', null, $id_ens);
    }

    // Deux élèves : le meilleur et le plus en difficulté, pour des démonstrations parlantes
    $par_niveau = $eleves;
    usort($par_niveau, fn($a, $b) => $b['niveau'] <=> $a['niveau']);
    foreach ([$par_niveau[0], $par_niveau[count($par_niveau) - 1]] as $e) {
        // Email sans accents (Léa -> lea), iconv//TRANSLIT n'étant pas fiable sous Windows
        $sans_accents = strtr($e['prenom'] . '.' . $e['nom'], ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E',
                                                              'à' => 'a', 'â' => 'a', 'ï' => 'i', 'î' => 'i', 'ô' => 'o',
                                                              'ù' => 'u', 'û' => 'u', 'ç' => 'c']);
        $email = strtolower($sans_accents) . '@demo.local';
        $ajouterCompte($e['nom'], $e['prenom'], $email, 'eleve', $e['id']);
    }

    // Un parent avec la fratrie
    $id_parent = $ajouterCompte($aine['nom'], 'Parent', 'parent@demo.local', 'parent');
    foreach ([$aine['id'], $eleves[$cadet_index]['id']] as $id_enfant) {
        $db->prepare("INSERT INTO parent_eleve (id_user, id_eleve) VALUES (:u, :e)")->execute([':u' => $id_parent, ':e' => $id_enfant]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Erreur, aucune donnée n'a été modifiée : " . $e->getMessage() . "\n");
    exit(1);
}

// ----------------------------------------
// Résumé
// ----------------------------------------
echo "\nDonnées de démonstration créées :\n";
echo "  " . count($eleves) . " élèves (" . count($classes) . " classes), $nb_notes notes sur " . count($trimestres) . " trimestres,\n";
echo "  " . count($affectations) . " enseignants affectés à leurs matières.\n";
echo "\nComptes (mot de passe : " . MOT_DE_PASSE_DEMO . ") :\n";
foreach ($comptes as [$role, $email, $qui]) {
    printf("  %-11s %-32s %s\n", $role, $email, $qui);
}
echo "\nLe parent suit : {$aine['prenom']} {$aine['nom']} ({$aine['classe']}) et "
   . "{$eleves[$cadet_index]['prenom']} {$eleves[$cadet_index]['nom']} ({$eleves[$cadet_index]['classe']}).\n";
