<?php
// ========================================
// CONTRAT ENTRE L'APPLICATION ET UN FOURNISSEUR D'IA
// L'application ne dépend que de cette interface : changer de fournisseur ou de modèle
// revient à fournir une autre implémentation (ou d'autres valeurs dans .env).
// ========================================

interface AIProvider {
    // Nom court du fournisseur (ex. "groq", "ollama"), affiché et journalisé
    public function name();

    // Modèle utilisé (ex. "gemini-3.8-flash")
    public function model();

    // Envoie la conversation et les outils disponibles, renvoie le message du modèle normalisé :
    // ['content' => ?string, 'tool_calls' => [['id', 'name', 'arguments' (chaîne JSON)]], 'usage' => [...]]
    // Lève AIProviderException en cas d'échec (indisponible, délai dépassé, réponse invalide).
    public function chat(array $messages, array $tools);
}

// Erreur d'un fournisseur. $errorCode : timeout, reseau, http_<statut>, reponse_invalide, non_configure
class AIProviderException extends Exception {
    public $errorCode;
    // Renseignés quand tous les fournisseurs ont échoué (pour le journal)
    public $echecs = [];
    public $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    public $latence = 0;

    public function __construct($errorCode, $message) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}
