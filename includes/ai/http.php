<?php
// ========================================
// APPEL HTTP COMMUN AUX FOURNISSEURS
// Envoi JSON, délai, certificats HTTPS et traduction des pannes en codes d'erreur du contrat
// (timeout, reseau, http_<statut>, reponse_invalide) : chaque fournisseur ne gère que son format.
// ========================================
require_once __DIR__ . '/AIProvider.php';

// Envoie $body en JSON à $url et renvoie la réponse décodée. Lève AIProviderException en cas d'échec.
function aiPostJson($name, $url, array $headers, array $body, $timeout) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    // Certificats HTTPS : fichier indiqué dans AI_CA_BUNDLE, sinon, sous Windows, le magasin du système
    // (PHP pour Windows n'embarque pas de liste d'autorités : sans cela, erreur curl 60)
    if (env('AI_CA_BUNDLE')) {
        curl_setopt($ch, CURLOPT_CAINFO, env('AI_CA_BUNDLE'));
    } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $raw    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        throw new AIProviderException('timeout', "$name : délai de $timeout s dépassé");
    }
    if ($raw === false || $errno) {
        throw new AIProviderException('reseau', "$name : injoignable (erreur curl $errno)");
    }
    if ($status !== 200) {
        // Le message du fournisseur explique le refus (modèle retiré, requête mal formée...) :
        // il va dans le journal du serveur, jamais à l'utilisateur
        $erreur = json_decode($raw, true)['error']['message'] ?? mb_substr($raw, 0, 300);
        throw new AIProviderException('http_' . $status, "$name : réponse HTTP $status : $erreur");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new AIProviderException('reponse_invalide', "$name : réponse qui n'est pas du JSON");
    }
    return $data;
}
