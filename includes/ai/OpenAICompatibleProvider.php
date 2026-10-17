<?php
// ========================================
// FOURNISSEUR « COMPATIBLE OPENAI »
// Groq (service externe) et Ollama (modèle local) exposent la même API /chat/completions :
// une seule implémentation suffit, seuls l'URL, la clé et le modèle changent (lus dans .env).
// ========================================
require_once __DIR__ . '/http.php';

class OpenAICompatibleProvider implements AIProvider {
    private $name;
    private $baseUrl;
    private $apiKey;
    private $model;
    private $timeout;

    public function __construct($name, $baseUrl, $apiKey, $model, $timeout) {
        $this->name    = $name;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->model   = $model;
        $this->timeout = $timeout;
    }

    // Construire le fournisseur $name à partir des variables {NAME}_BASE_URL, {NAME}_API_KEY, {NAME}_MODEL, {NAME}_TIMEOUT (facultatif)
    public static function fromEnv($name) {
        $prefix = strtoupper($name);
        return new self(
            $name,
            (string) env($prefix . '_BASE_URL', ''),
            (string) env($prefix . '_API_KEY', ''),
            (string) env($prefix . '_MODEL', ''),
            (int) env($prefix . '_TIMEOUT', env('AI_TIMEOUT', 30))   // délai propre au fournisseur, sinon AI_TIMEOUT
        );
    }

    public function name() {
        return $this->name;
    }

    public function model() {
        return $this->model;
    }

    public function chat(array $messages, array $tools) {
        if ($this->baseUrl === '' || $this->model === '') {
            throw new AIProviderException('non_configure', "Fournisseur {$this->name} non configuré dans .env");
        }

        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.2,   // réponses factuelles : peu de variabilité
            'max_tokens'  => 1024,
        ];
        if ($tools) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        $headers = [];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;   // Ollama n'a pas de clé
        }
        $data = aiPostJson($this->name, $this->baseUrl . '/chat/completions', $headers, $body, $this->timeout);

        // Valider la structure de la réponse avant de l'utiliser
        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new AIProviderException('reponse_invalide', "{$this->name} : réponse sans message");
        }

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            if (!isset($call['id'], $call['function']['name'])) {
                throw new AIProviderException('reponse_invalide', "{$this->name} : appel d'outil mal formé");
            }
            $toolCalls[] = [
                'id'        => (string) $call['id'],
                'name'      => (string) $call['function']['name'],
                'arguments' => (string) ($call['function']['arguments'] ?? '{}'),
            ];
        }

        return [
            'content'    => isset($message['content']) ? (string) $message['content'] : null,
            'tool_calls' => $toolCalls,
            'usage'      => [
                'prompt_tokens'     => (int) ($data['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }
}
