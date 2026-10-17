<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/bulletin.php';

// Page sans header.php (impression) : vérifier la connexion ici
requireRole(array_merge(ROLES_PEDAGOGIE, [ROLE_ELEVE, ROLE_PARENT]));

$database = new Database();
$db = $database->getConnection();

// Un élève n'imprime que SON bulletin : l'identifiant de l'URL est ignoré pour lui
if (hasRole(ROLE_ELEVE)) {
    $eleve_id = requireEleve();
} else {
    $eleve_id = isset($_GET['eleve_id']) ? (int) $_GET['eleve_id'] : null;
}

// Un parent n'imprime que les bulletins de SES enfants
if (hasRole(ROLE_PARENT) && !isset(parentEleves($db)[$eleve_id])) {
    http_response_code(403);
    die('Accès refusé : cet élève ne fait pas partie de vos enfants.');
}
$trimestre_id = isset($_GET['trimestre_id']) ? (int) $_GET['trimestre_id'] : null;

if(!$eleve_id || !$trimestre_id) {
    die('Paramètres manquants');
}

$bulletin_data = generateBulletin($db, $eleve_id, $trimestre_id);

if(!$bulletin_data['eleve']) {
    die('Élève introuvable');
}

// Pour une version PDF simple, on utilise la fonction print CSS
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bulletin - <?php echo htmlspecialchars($bulletin_data['eleve']['nom']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #2c3e50; }
        .info { margin-bottom: 20px; }
        .info table { width: 100%; }
        .info td { padding: 5px; }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #3498db; color: white; padding: 10px; }
        td { padding: 8px; border: 1px solid #ddd; }
        .moyenne { font-weight: bold; background-color: #f8f9fa; }
        @media print {
            .no-print { display: none; }
            .header h1 { color: black; }
            th { background-color: #ccc; color: black; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>BULLETIN SCOLAIRE</h1>
        <h3><?php echo htmlspecialchars($bulletin_data['eleve']['nom'] . ' ' . $bulletin_data['eleve']['prenom']); ?></h3>
    </div>

    <div class="info">
        <table>
            <tr>
                <td><strong>Classe :</strong> <?php echo htmlspecialchars($bulletin_data['eleve']['nom_classe']); ?></td>
                <td><strong>Niveau :</strong> <?php echo htmlspecialchars($bulletin_data['eleve']['niveau']); ?></td>
            </tr>
            <tr>
                <td><strong>Date de naissance :</strong> <?php echo date('d/m/Y', strtotime($bulletin_data['eleve']['date_naiss'])); ?></td>
                <td><strong>Trimestre :</strong> <?php echo $trimestre_id; ?></td>
            </tr>
        </table>
    </div>

    <h4>Résultats</h4>
    <table>
        <thead>
            <tr>
                <th>Matière</th>
                <th>Notes</th>
                <th>Moyenne</th>
                <th>Coef</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            foreach($bulletin_data['matieres'] as $matiere => $data):
                $moy = $data['total'] / $data['count'];
                $notes_list = array_filter($bulletin_data['notes'], function($n) use ($matiere) {
                    return $n['nom_matiere'] == $matiere;
                });
                $notes_str = [];
                foreach($notes_list as $n) {
                    $notes_str[] = $n['valeur'];
                }
            ?>
            <tr>
                <td><?php echo htmlspecialchars($matiere); ?></td>
                <td><?php echo implode(' - ', $notes_str); ?></td>
                <td><?php echo number_format($moy, 2); ?></td>
                <td><?php echo $data['coef']; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="moyenne">
                <td colspan="3"><strong>Moyenne générale</strong></td>
                <td><strong><?php echo number_format($bulletin_data['moyenne_generale'], 2); ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="no-print" style="margin-top: 30px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 30px; font-size: 16px;">Imprimer</button>
        <button onclick="window.close()" style="padding: 10px 30px; font-size: 16px;">Fermer</button>
    </div>

    <script>
        // Auto-print
        window.onload = function() {
            // window.print();
        }
    </script>
</body>
</html>