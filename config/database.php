<?php
require_once __DIR__ . '/env.php';

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Identifiants lus dans .env (valeurs par défaut : installation locale XAMPP/MariaDB)
        $this->host     = env('DB_HOST', 'localhost');
        $this->db_name  = env('DB_NAME', 'gestion_notes');
        $this->username = env('DB_USER', 'root');
        $this->password = env('DB_PASS', '');
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                                  $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            // Ne pas afficher le détail de l'erreur (il peut contenir des informations sensibles)
            error_log('Connexion base de données : ' . $exception->getMessage());
            die('Erreur de connexion à la base de données.');
        }
        return $this->conn;
    }
}
?>
