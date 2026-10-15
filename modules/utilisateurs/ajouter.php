<?php
// ========================================
// COMPTES UTILISATEURS : création et modification (administrateurs uniquement)
// ajouter.php          -> nouveau compte
// ajouter.php?id=X     -> modifier le compte X
// ajouter.php?eleve=Y  -> nouveau compte élève déjà relié à la fiche Y
// ajouter.php?enseignant=Z -> nouveau compte enseignant déjà relié à la fiche Z
// ========================================
require_once '../../config/session.php';
require_once '../../config/database.php';

requireRole(ROLES_ADMIN);

$database = new Database();
$db = $database->getConnection();

$roles = [
    'admin'      => 'Administrateur',
    'directeur'  => 'Directeur',
    'enseignant' => 'Enseignant',
    'eleve'      => 'Élève',
    'parent'     => 'Parent',
    'user'       => 'Utilisateur (aucun accès aux données)',
];

// Compte à modifier
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$compte = null;
if ($id > 0) {
    $stmt = $db->prepare("SELECT id, nom, prenom, email, role, id_eleve, id_enseignant, status FROM users WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $compte = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$compte) {
        setFlash('warning', 'Compte introuvable.');
        header('Location: index.php');
        exit;
    }
    // Enfants suivis (compte parent)
    $stmt = $db->prepare("SELECT id_eleve FROM parent_eleve WHERE id_user = :id");
    $stmt->execute([':id' => $id]);
    $compte['enfants'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
$edit_mode = $compte !== null;
$moi = $edit_mode && (int) $compte['id'] === (int) $_SESSION['user_id'];

$errors = [];
$error = csrfError();

if (isPostWithValidCsrf()) {
    $nom       = trim($_POST['nom'] ?? '');
    $prenom    = trim($_POST['prenom'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = $_POST['role'] ?? '';
    $id_eleve  = (int) ($_POST['id_eleve'] ?? 0);
    $id_enseignant = (int) ($_POST['id_enseignant'] ?? 0);
    $enfants   = array_values(array_unique(array_map('intval', (array) ($_POST['enfants'] ?? []))));
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    // Un administrateur ne peut pas modifier son propre rôle (il pourrait se retirer l'accès)
    if ($moi) {
        $role = $compte['role'];
    }

    if ($nom === '') {
        $errors['nom'] = 'Le nom est obligatoire.';
    }
    if ($prenom === '') {
        $errors['prenom'] = 'Le prénom est obligatoire.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "L'adresse email n'est pas valide.";
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND id <> :id");
        $stmt->execute([':email' => $email, ':id' => $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors['email'] = 'Cette adresse email est déjà utilisée par un autre compte.';
        }
    }
    if (!isset($roles[$role])) {
        $errors['role'] = 'Rôle invalide.';
    }

    // Un compte élève doit être relié à une fiche élève, et une fiche n'a qu'un compte
    if ($role === ROLE_ELEVE) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM eleve WHERE id_eleve = :id");
        $stmt->execute([':id' => $id_eleve]);
        if ($id_eleve <= 0 || $stmt->fetchColumn() == 0) {
            $errors['id_eleve'] = 'Choisissez la fiche élève à relier à ce compte.';
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id_eleve = :eleve AND id <> :id");
            $stmt->execute([':eleve' => $id_eleve, ':id' => $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors['id_eleve'] = 'Cet élève a déjà un compte.';
            }
        }
    } else {
        $id_eleve = null;
    }

    // Un compte enseignant doit être relié à une fiche enseignant (un compte par fiche) :
    // c'est elle qui fixe l'auteur des notes saisies et les matières autorisées
    if ($role === ROLE_ENSEIGNANT) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM enseignant WHERE id_enseignant = :id");
        $stmt->execute([':id' => $id_enseignant]);
        if ($id_enseignant <= 0 || $stmt->fetchColumn() == 0) {
            $errors['id_enseignant'] = 'Choisissez la fiche enseignant à relier à ce compte.';
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id_enseignant = :ens AND id <> :id");
            $stmt->execute([':ens' => $id_enseignant, ':id' => $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors['id_enseignant'] = 'Cet enseignant a déjà un compte.';
            }
        }
    } else {
        $id_enseignant = null;
    }

    // Un compte parent suit au moins un enfant (élèves existants uniquement)
    if ($role === ROLE_PARENT) {
        if ($enfants) {
            $marques = implode(',', array_fill(0, count($enfants), '?'));
            $stmt = $db->prepare("SELECT id_eleve FROM eleve WHERE id_eleve IN ($marques)");
            $stmt->execute($enfants);
            $enfants = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if (empty($enfants)) {
            $errors['enfants'] = 'Cochez au moins un enfant suivi par ce parent.';
        }
    } else {
        $enfants = [];
    }

    // Mot de passe : obligatoire à la création, facultatif en modification (vide = inchangé)
    if (!$edit_mode || $password !== '') {
        if (strlen($password) < 8) {
            $errors['password'] = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($password !== $password2) {
            $errors['password2'] = 'Les deux mots de passe ne correspondent pas.';
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $params = [':nom' => $nom, ':prenom' => $prenom, ':email' => $email,
                       ':role' => $role, ':id_eleve' => $id_eleve, ':id_enseignant' => $id_enseignant];
            if ($edit_mode) {
                $sql = "UPDATE users SET nom = :nom, prenom = :prenom, email = :email,
                               role = :role, id_eleve = :id_eleve, id_enseignant = :id_enseignant";
                if ($password !== '') {
                    $sql .= ", password = :password";
                    $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= " WHERE id = :id";
                $params[':id'] = $id;
            } else {
                $sql = "INSERT INTO users (nom, prenom, email, password, role, id_eleve, id_enseignant)
                        VALUES (:nom, :prenom, :email, :password, :role, :id_eleve, :id_enseignant)";
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $db->prepare($sql)->execute($params);
            $id_compte = $edit_mode ? $id : (int) $db->lastInsertId();

            // Enfants suivis : remplacer la liste (vide si le compte n'est pas parent)
            $db->prepare("DELETE FROM parent_eleve WHERE id_user = :id")->execute([':id' => $id_compte]);
            $stmt = $db->prepare("INSERT INTO parent_eleve (id_user, id_eleve) VALUES (:user, :eleve)");
            foreach ($enfants as $id_enfant) {
                $stmt->execute([':user' => $id_compte, ':eleve' => $id_enfant]);
            }

            $db->commit();
            setFlash('success', $edit_mode ? 'Compte modifié.' : 'Compte créé : ' . $email);
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $db->rollBack();
            error_log('Enregistrement compte: ' . $e->getMessage());
            $error = "Erreur lors de l'enregistrement du compte.";
        }
    } else {
        $error = 'Veuillez corriger les erreurs ci-dessous.';
    }
}

// Valeurs affichées : saisie renvoyée, sinon compte existant, sinon valeurs par défaut
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = ['nom' => $nom ?? '', 'prenom' => $prenom ?? '', 'email' => $email ?? '',
             'role' => $role ?? 'user', 'id_eleve' => $id_eleve ?? null,
             'id_enseignant' => $id_enseignant ?? null, 'enfants' => $enfants ?? []];
} elseif ($edit_mode) {
    $form = $compte;
} else {
    $eleve_choisi = isset($_GET['eleve']) ? (int) $_GET['eleve'] : 0;
    $enseignant_choisi = isset($_GET['enseignant']) ? (int) $_GET['enseignant'] : 0;
    $form = ['nom' => '', 'prenom' => '', 'email' => '',
             'role' => $eleve_choisi ? ROLE_ELEVE : ROLE_ENSEIGNANT,
             'id_eleve' => $eleve_choisi ?: null, 'id_enseignant' => $enseignant_choisi ?: null, 'enfants' => []];
    // Pré-remplir le nom depuis la fiche élève ou enseignant
    if ($eleve_choisi) {
        $stmt = $db->prepare("SELECT nom, prenom FROM eleve WHERE id_eleve = :id");
        $stmt->execute([':id' => $eleve_choisi]);
    } elseif ($enseignant_choisi) {
        $stmt = $db->prepare("SELECT nom, prenom FROM enseignant WHERE id_enseignant = :id");
        $stmt->execute([':id' => $enseignant_choisi]);
    }
    if (($eleve_choisi || $enseignant_choisi) && ($fiche = $stmt->fetch(PDO::FETCH_ASSOC))) {
        $form['nom'] = $fiche['nom'];
        $form['prenom'] = $fiche['prenom'];
    }
}

// Élèves proposés : ceux sans compte, plus celui déjà relié à ce compte
$stmt = $db->prepare("SELECT e.id_eleve, e.nom, e.prenom, c.nom_classe
                      FROM eleve e
                      LEFT JOIN classe c ON c.id_classe = e.id_classe
                      WHERE e.id_eleve NOT IN (SELECT id_eleve FROM users WHERE id_eleve IS NOT NULL AND id <> :id)
                      ORDER BY e.nom, e.prenom");
$stmt->execute([':id' => $id]);
$eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Enseignants proposés : ceux sans compte, plus celui déjà relié à ce compte
$stmt = $db->prepare("SELECT id_enseignant, nom, prenom, grade FROM enseignant
                      WHERE id_enseignant NOT IN (SELECT id_enseignant FROM users WHERE id_enseignant IS NOT NULL AND id <> :id)
                      ORDER BY nom, prenom");
$stmt->execute([':id' => $id]);
$enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tous les élèves, pour les enfants d'un parent (un élève peut avoir plusieurs parents)
$tous_eleves = $db->query("SELECT e.id_eleve, e.nom, e.prenom, c.nom_classe FROM eleve e
                           LEFT JOIN classe c ON c.id_classe = e.id_classe ORDER BY e.nom, e.prenom")->fetchAll(PDO::FETCH_ASSOC);

function champInvalide($errors, $champ) {
    return isset($errors[$champ]) ? 'is-invalid' : '';
}

function messageErreur($errors, $champ) {
    if (isset($errors[$champ])) {
        echo '<div class="invalid-feedback">' . htmlspecialchars($errors[$champ]) . '</div>';
    }
}

require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">
                <span class="title-icon"><i class="bi <?php echo $edit_mode ? 'bi-pencil-square' : 'bi-person-plus'; ?>"></i></span>
                <?php echo $edit_mode ? 'Modifier un compte' : 'Nouveau compte'; ?>
            </h1>
            <p class="page-subtitle text-muted">
                <?php echo $edit_mode ? htmlspecialchars($compte['email']) : 'Créez un accès à l\'application'; ?>
            </p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card voir-card">
            <div class="card-body">
                <form method="post" action="" novalidate>
                    <?php echo csrfField(); ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="nom" class="form-label">Nom *</label>
                            <input type="text" class="form-control <?php echo champInvalide($errors, 'nom'); ?>" id="nom" name="nom"
                                   value="<?php echo htmlspecialchars($form['nom']); ?>" required>
                            <?php messageErreur($errors, 'nom'); ?>
                        </div>
                        <div class="col-md-6">
                            <label for="prenom" class="form-label">Prénom *</label>
                            <input type="text" class="form-control <?php echo champInvalide($errors, 'prenom'); ?>" id="prenom" name="prenom"
                                   value="<?php echo htmlspecialchars($form['prenom']); ?>" required>
                            <?php messageErreur($errors, 'prenom'); ?>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email (identifiant de connexion) *</label>
                            <input type="email" class="form-control <?php echo champInvalide($errors, 'email'); ?>" id="email" name="email"
                                   value="<?php echo htmlspecialchars($form['email']); ?>" required>
                            <?php messageErreur($errors, 'email'); ?>
                        </div>
                        <div class="col-md-6">
                            <label for="role" class="form-label">Rôle *</label>
                            <select class="form-select <?php echo champInvalide($errors, 'role'); ?>" id="role" name="role" <?php echo $moi ? 'disabled' : ''; ?>>
                                <?php foreach ($roles as $code => $libelle): ?>
                                    <option value="<?php echo $code; ?>" <?php echo $form['role'] === $code ? 'selected' : ''; ?>><?php echo $libelle; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($moi): ?>
                                <small class="text-muted">Vous ne pouvez pas modifier votre propre rôle.</small>
                            <?php endif; ?>
                            <?php messageErreur($errors, 'role'); ?>
                        </div>

                        <div class="col-12" id="bloc-eleve">
                            <label for="id_eleve" class="form-label">Fiche élève reliée *</label>
                            <select class="form-select <?php echo champInvalide($errors, 'id_eleve'); ?>" id="id_eleve" name="id_eleve">
                                <option value="">Choisir l'élève…</option>
                                <?php foreach ($eleves as $e): ?>
                                    <option value="<?php echo (int) $e['id_eleve']; ?>" <?php echo (int) $form['id_eleve'] === (int) $e['id_eleve'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($e['nom'] . ' ' . $e['prenom'] . ($e['nom_classe'] ? ' — ' . $e['nom_classe'] : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Seuls les élèves sans compte sont proposés. L'élève verra uniquement ses propres notes.</small>
                            <?php messageErreur($errors, 'id_eleve'); ?>
                        </div>

                        <div class="col-12" id="bloc-enseignant">
                            <label for="id_enseignant" class="form-label">Fiche enseignant reliée *</label>
                            <select class="form-select <?php echo champInvalide($errors, 'id_enseignant'); ?>" id="id_enseignant" name="id_enseignant">
                                <option value="">Choisir l'enseignant…</option>
                                <?php foreach ($enseignants as $ens): ?>
                                    <option value="<?php echo (int) $ens['id_enseignant']; ?>" <?php echo (int) ($form['id_enseignant'] ?? 0) === (int) $ens['id_enseignant'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ens['nom'] . ' ' . $ens['prenom'] . ($ens['grade'] ? ' — ' . $ens['grade'] : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Les notes saisies avec ce compte seront à son nom, dans ses matières uniquement.
                                Fiche absente ? <a href="../enseignants/ajouter.php">Ajouter un enseignant</a>.</small>
                            <?php messageErreur($errors, 'id_enseignant'); ?>
                        </div>

                        <div class="col-12" id="bloc-parent">
                            <label class="form-label">Enfants suivis *</label>
                            <div class="border rounded p-2 <?php echo isset($errors['enfants']) ? 'border-danger' : ''; ?>" style="max-height: 220px; overflow-y: auto;">
                                <?php if (empty($tous_eleves)): ?>
                                    <span class="text-muted small">Aucun élève enregistré.</span>
                                <?php endif; ?>
                                <?php foreach ($tous_eleves as $e): $eid = (int) $e['id_eleve']; ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="enfants[]" value="<?php echo $eid; ?>" id="enfant<?php echo $eid; ?>"
                                               <?php echo in_array($eid, $form['enfants'] ?? [], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enfant<?php echo $eid; ?>">
                                            <?php echo htmlspecialchars($e['nom'] . ' ' . $e['prenom'] . ($e['nom_classe'] ? ' — ' . $e['nom_classe'] : '')); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Le parent verra uniquement les notes et bulletins de ces élèves.</small>
                            <?php if (isset($errors['enfants'])): ?>
                                <div class="text-danger small"><?php echo htmlspecialchars($errors['enfants']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label for="password" class="form-label">Mot de passe <?php echo $edit_mode ? '' : '*'; ?></label>
                            <input type="password" class="form-control <?php echo champInvalide($errors, 'password'); ?>" id="password" name="password"
                                   autocomplete="new-password" <?php echo $edit_mode ? '' : 'required'; ?>>
                            <small class="text-muted"><?php echo $edit_mode ? 'Laisser vide pour ne pas le changer. ' : ''; ?>8 caractères minimum.</small>
                            <?php messageErreur($errors, 'password'); ?>
                        </div>
                        <div class="col-md-6">
                            <label for="password2" class="form-label">Confirmer le mot de passe</label>
                            <input type="password" class="form-control <?php echo champInvalide($errors, 'password2'); ?>" id="password2" name="password2"
                                   autocomplete="new-password">
                            <?php messageErreur($errors, 'password2'); ?>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-dark">
                            <i class="bi bi-save"></i> <?php echo $edit_mode ? 'Enregistrer' : 'Créer le compte'; ?>
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card voir-card">
            <div class="card-header"><i class="bi bi-shield-lock"></i> Ce que permet chaque rôle</div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item"><strong>Administrateur</strong> : tout, y compris les comptes</li>
                <li class="list-group-item"><strong>Directeur</strong> : élèves, classes, matières, notes, bulletins</li>
                <li class="list-group-item"><strong>Enseignant</strong> : consulter, bulletins ; saisir des notes dans ses matières, supprimer celles qu'il a saisies</li>
                <li class="list-group-item"><strong>Élève</strong> : ses propres notes et bulletins uniquement</li>
                <li class="list-group-item"><strong>Parent</strong> : notes et bulletins de ses enfants uniquement</li>
                <li class="list-group-item"><strong>Utilisateur</strong> : aucun accès aux données</li>
            </ul>
        </div>
    </div>
</div>

<script>
// Afficher le lien adapté au rôle : fiche élève, fiche enseignant ou enfants suivis
(function () {
    const role = document.getElementById('role');
    const blocs = { eleve: 'bloc-eleve', enseignant: 'bloc-enseignant', parent: 'bloc-parent' };
    function majBlocs() {
        for (const [code, id] of Object.entries(blocs)) {
            document.getElementById(id).hidden = role.value !== code;
        }
    }
    role.addEventListener('change', majBlocs);
    majBlocs();
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
