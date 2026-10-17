<?php
// ========================================
// FOURNISSEUR GOOGLE GEMINI (API native generateContent)
// Adaptateur : Gemini n'utilise pas le format OpenAI (rôles "user"/"model", "parts",
// functionCall / functionResponse, schémas en majuscules). Cette classe traduit dans les deux sens,
// si bien que l'orchestrateur (Assistant.php) et le reste de l'application ne changent pas.
// Le modèle tourne chez Google : aucune charge sur la machine, mais les données sortent (voir securite_donnees.md).
// ========================================
require_once __DIR__ . '/http.php';

class GeminiProvider implements AIProvider {
    private $name;
    private $baseUrl;
    private $apiKey;
    private $model;
    private $timeout;
    // Appels d'outils reçus, par identifiant : renvoyés tels quels au tour suivant,
    // car les modèles qui « réfléchissent » exigent leur signature (thoughtSignature)
    private $appels = [];

    public function __construct($name, $baseUrl, $apiKey, $model, $timeout) {
        $this->name    = $name;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->model   = $model;
        $this->timeout = $timeout;
    }

    // Variables {NAME}_BASE_URL (facultative), {NAME}_API_KEY, {NAME}_MODEL
    public static function fromEnv($name) {
        $prefix = strtoupper($name);
        return new self(
            $name,
            (string) env($prefix . '_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
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
        if ($this->apiKey === '' || $this->model === '') {
            throw new AIProviderException('non_configure', "Fournisseur {$this->name} non configuré dans .env");
        }

        $body = $this->traduireMessages($messages);
        $body['generationConfig'] = [
            'temperature'     => 0.2,    // réponses factuelles : peu de variabilité
            'maxOutputTokens' => 2048,   // plus large qu'ailleurs : la « réflexion » des modèles Gemini 2.5+ y est comptée
        ];
        if ($tools) {
            $body['tools'] = [['functionDeclarations' => array_map([$this, 'traduireOutil'], $tools)]];
            $body['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        $url  = $this->baseUrl . '/models/' . rawurlencode($this->model) . ':generateContent';
        $data = aiPostJson($this->name, $url, ['x-goog-api-key: ' . $this->apiKey], $body, $this->timeout);

        // Valider la structure de la réponse avant de l'utiliser
        $parts = $data['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            // Pas de contenu : question bloquée par les filtres, ou réponse coupée
            $raison = $data['promptFeedback']['blockReason'] ?? $data['candidates'][0]['finishReason'] ?? 'inconnue';
            throw new AIProviderException('reponse_invalide', "{$this->name} : réponse sans contenu ($raison)");
        }

        $texte = '';
        $toolCalls = [];
        foreach ($parts as $part) {
            if (!empty($part['thought'])) {
                continue;   // résumé du raisonnement interne : jamais affiché
            }
            if (isset($part['text'])) {
                $texte .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                if (!isset($part['functionCall']['name'])) {
                    throw new AIProviderException('reponse_invalide', "{$this->name} : appel d'outil mal formé");
                }
                // Gemini ne donne pas toujours d'identifiant : en fabriquer un, unique pour cette conversation
                $id = (string) ($part['functionCall']['id'] ?? 'gemini_' . (count($this->appels) + 1));
                $args = $part['functionCall']['args'] ?? [];
                // json_decode transforme {} en [] : le renvoyer tel quel donnerait une liste, que Gemini refuse (HTTP 400)
                $part['functionCall']['args'] = $args ?: new stdClass();
                $this->appels[$id] = $part;
                $toolCalls[] = [
                    'id'        => $id,
                    'name'      => (string) $part['functionCall']['name'],
                    'arguments' => json_encode($args ?: new stdClass(), JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $usage = $data['usageMetadata'] ?? [];
        return [
            'content'    => $texte !== '' ? $texte : null,
            'tool_calls' => $toolCalls,
            'usage'      => [
                'prompt_tokens'     => (int) ($usage['promptTokenCount'] ?? 0),
                // La réflexion est facturée comme de la sortie
                'completion_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            ],
        ];
    }

    // Messages au format OpenAI → systemInstruction + contents au format Gemini
    private function traduireMessages(array $messages) {
        $systeme  = [];
        $contents = [];
        $nomsOutils = [];   // id d'appel → nom de l'outil (functionResponse demande le nom)

        foreach ($messages as $m) {
            switch ($m['role']) {
                case 'system':
                    $systeme[] = ['text' => (string) $m['content']];
                    continue 2;
                case 'user':
                    $role  = 'user';
                    $parts = [['text' => (string) $m['content']]];
                    break;
                case 'assistant':
                    $role  = 'model';
                    $parts = [];
                    if (isset($m['content']) && $m['content'] !== '') {
                        $parts[] = ['text' => (string) $m['content']];
                    }
                    foreach ($m['tool_calls'] ?? [] as $call) {
                        $id = $call['id'];
                        $nomsOutils[$id] = $call['function']['name'];
                        $parts[] = $this->appels[$id] ?? ['functionCall' => [
                            'name' => $call['function']['name'],
                            'args' => json_decode($call['function']['arguments'], true) ?: new stdClass(),
                        ]];
                    }
                    break;
                case 'tool':
                    $role = 'user';
                    $id   = $m['tool_call_id'];
                    $resultat = json_decode($m['content'], true);
                    // La réponse d'un outil doit être un objet JSON
                    if (!is_array($resultat) || array_is_list($resultat)) {
                        $resultat = ['resultat' => $resultat];
                    }
                    $reponse = ['name' => $nomsOutils[$id] ?? '', 'response' => $resultat ?: new stdClass()];
                    if (isset($this->appels[$id]['functionCall']['id'])) {
                        $reponse['id'] = $id;
                    }
                    $parts = [['functionResponse' => $reponse]];
                    break;
                default:
                    continue 2;
            }
            if (!$parts) {
                continue;
            }
            // Gemini veut des tours alternés : fusionner les messages consécutifs du même rôle
            // (en particulier les résultats de plusieurs outils appelés ensemble)
            $dernier = count($contents) - 1;
            if ($dernier >= 0 && $contents[$dernier]['role'] === $role) {
                $contents[$dernier]['parts'] = array_merge($contents[$dernier]['parts'], $parts);
            } else {
                $contents[] = ['role' => $role, 'parts' => $parts];
            }
        }

        $body = ['contents' => $contents];
        if ($systeme) {
            $body['systemInstruction'] = ['parts' => $systeme];
        }
        return $body;
    }

    // Outil au format OpenAI ({type: function, function: {...}}) → déclaration de fonction Gemini
    private function traduireOutil(array $tool) {
        $def = $tool['function'];
        $declaration = ['name' => $def['name'], 'description' => $def['description'] ?? ''];
        // Gemini refuse un objet sans propriétés : un outil sans paramètre n'en déclare pas
        if (!empty((array) ($def['parameters']['properties'] ?? []))) {
            $declaration['parameters'] = $this->traduireSchema($def['parameters']);
        }
        return $declaration;
    }

    // JSON Schema → schéma OpenAPI de Gemini (types en majuscules : OBJECT, STRING, INTEGER...)
    private function traduireSchema($schema) {
        $schema = (array) $schema;
        if (isset($schema['type'])) {
            $schema['type'] = strtoupper($schema['type']);
        }
        if (isset($schema['properties'])) {
            $schema['properties'] = array_map([$this, 'traduireSchema'], (array) $schema['properties']);
        }
        if (isset($schema['items'])) {
            $schema['items'] = $this->traduireSchema($schema['items']);
        }
        return $schema;
    }
}
