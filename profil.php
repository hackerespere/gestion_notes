<?php
// ========================================
// MON PROFIL : informations du compte et changement de mot de passe (tout utilisateur connecté)
// ========================================
require_once 'config/session.php';
require_once 'config/database.php';

checkAuth();

$db = (new Database())->getConnection();

$stmt = $db->prepare("SELECT nom, prenom, email, role, password, last_login FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$moi = $stmt->fetch(PDO::FETCH_ASSOC);

$errors = [];
$error = csrfError();

if (isPostWithValidCsrf()) {
    $actuel  = $_POST['actuel'] ?? '';
    $nouveau = $_POST['nouveau'] ?? '';
    $confirm = $_POST['confirmation'] ?? '';

    // Le mot de passe actuel est exigé : une session laissée ouverte ne suffit pas à le changer
    if (!password_verify($actuel, $moi['password'])) {
        $errors['actuel'] = 'Mot de passe actuel incorrect.';
    }
    if (strlen($nouveau) < 8) {
        $errors['nouveau'] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    } elseif ($nouveau !== $confirm) {
        $errors['confirmation'] = 'Les deux mots de passe ne correspondent pas.';
    } elseif ($nouveau === $actuel) {
        $errors['nouveau'] = "Le nouveau mot de passe doit être différent de l'actuel.";
    }

    if (empty($errors)) {
        $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
        $stmt->execute([':password' => password_hash($nouveau, PASSWORD_DEFAULT), ':id' => $_SESSION['user_id']]);
        setFlash('success', 'Votre mot de passe a été modifié.');
        header('Location: profil.php');
        exit;
    }
}

$roles = ['admin' => 'Administrateur', 'directeur' => 'Directeur', 'enseignant' => 'Enseignant',
          'eleve' => 'Élève', 'parent' => 'Parent', 'user' => 'Utilisateur'];

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">
        <span class="title-icon"><i class="bi bi-person-circle"></i></span>
        Mon profil
    </h1>
    <p class="page-subtitle text-muted">Vos informations et votre mot de passe</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card voir-card">
            <div class="card-header"><i class="bi bi-person-vcard"></i> Mon compte</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Nom</span>
                    <span><?php echo htmlspecialchars($moi['prenom'] . ' ' . $moi['nom']); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Email</span>
                    <span><?php echo htmlspecialchars($moi['email']); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Rôle</span>
                    <span><?php echo $roles[$moi['role']] ?? htmlspecialchars($moi['role']); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Dernière connexion</span>
                    <span><?php echo $moi['last_login'] ? date('d/m/Y H:i', strtotime($moi['last_login'])) : '—'; ?></span>
                </li>
            </ul>
            <div class="card-body small text-muted">
                Pour modifier votre nom ou votre email, contactez l'administration.
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card voir-card">
            <div class="card-header"><i class="bi bi-key"></i> Changer mon mot de passe</div>
            <div class="card-body">
                <form method="post" action="" novalidate>
                    <?php echo csrfField(); ?>
                    <?php foreach (['actuel' => 'Mot de passe actuel', 'nouveau' => 'Nouveau mot de passe (8 caractères minimum)', 'confirmation' => 'Confirmer le nouveau mot de passe'] as $champ => $libelle): ?>
                        <div class="mb-3">
                            <label for="<?php echo $champ; ?>" class="form-label"><?php echo $libelle; ?></label>
                            <input type="password" class="form-control <?php echo isset($errors[$champ]) ? 'is-invalid' : ''; ?>"
                                   id="<?php echo $champ; ?>" name="<?php echo $champ; ?>"
                                   autocomplete="<?php echo $champ === 'actuel' ? 'current-password' : 'new-password'; ?>" required>
                            <?php if (isset($errors[$champ])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors[$champ]); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-dark"><i class="bi bi-save"></i> Changer le mot de passe</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
