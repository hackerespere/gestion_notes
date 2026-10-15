<?php
// La modification utilise le même formulaire que l'ajout (ajouter.php?id=X gère le mode édition)
require_once '../../config/session.php';

checkAuth();

$id_eleve = isset($_GET['id']) ? (int) $_GET['id'] : 0;

header('Location: ' . ($id_eleve > 0 ? 'ajouter.php?id=' . $id_eleve : 'index.php'));
exit;
