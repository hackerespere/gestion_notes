<?php
// La modification utilise le même formulaire que l'ajout (ajouter.php?id=X gère le mode édition)
require_once '../../config/session.php';

requireRole(ROLES_GESTION);

$id_classe = isset($_GET['id']) ? (int) $_GET['id'] : 0;

header('Location: ' . ($id_classe > 0 ? 'ajouter.php?id=' . $id_classe : 'index.php'));
exit;
