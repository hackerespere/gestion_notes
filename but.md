Travail de recherche et exposés
L’objectif est que chaque groupe maîtrise toute la thématique qui lui est attribuée, et non
qu’il se contente de réciter quelques définitions. Les étudiants devront comprendre les
concepts, expliquer leur fonctionnement, comparer les approches, justifier leurs choix
techniques et montrer comment les appliquer à un problème concret.
Consignes obligatoires pour les 14 groupes
Chaque exposé doit comporter les parties suivantes, adaptées au sujet attribué.
1. Fondements théoriques : définitions, concepts clés, objectifs, principes de
fonctionnement et évolution du domaine.
2. Approfondissement technique : mécanismes internes, composants, étapes,
algorithmes ou règles de conception concernée.
3. Comparaison : au moins deux approches, technologies ou solutions, avec leurs
avantages, limites et critères de choix.
4. Modélisation : schémas d’architecture, diagrammes UML, flux de traitement ou
pipelines, selon le sujet.
5. Application pratique : étude de cas réaliste, avec une solution proposée et des
choix techniques justifiés.
6. Qualité et risques : sécurité, performances, maintenabilité, évolutivité, coûts et
erreurs possibles, selon la pertinence.
7. Analyse critique : limites de la solution et améliorations possibles.
8. Références : documentation officielle, ouvrages et articles scientifiques pertinents.

Sujet 7 — Conception de logiciels intégrant l’intelligence artificielle
Objectif : apprendre à intégrer l’IA dans une application réelle sans confondre le modèle,
le service d’inférence et l’application complète.
A. Contenu obligatoire
1. Définir l’IA et distinguer les systèmes à règles, le Machine Learning, le Deep
Learning et les modèles génératifs.
2. Expliquer les architectures d’intégration d’un modèle :
o appel à une API d’IA externe ;
o modèle hébergé sur un serveur interne ;
o modèle embarqué dans l’application ;
o architecture hybride.
3. Décrire les composants d’une application intelligente : interface, logique métier,
service d’IA, données, stockage, authentification et supervision.
4. Expliquer la différence entre entraînement, validation, inférence et posttraitement.
5. Étudier les formats d’entrée et de sortie, la validation des données, les schémas de
réponse et la gestion des résultats invalides.
6. Présenter les contrats entre l’application et le modèle : version, types de données,
limites, erreurs et compatibilité.
7. Expliquer la gestion de la latence, des quotas, des coûts d’inférence, de la
concurrence et de la disponibilité.
8. Comparer le recours à une API externe avec l’hébergement d’un modèle local.
9. Étudier les mécanismes de secours : réponse de repli, nouvelle tentative contrôlée,
mise en cache et intervention humaine.
10. Présenter la sécurité des données, le contrôle d’accès, la confidentialité et les
risques liés aux entrées malveillantes.
11. Expliquer l’évaluation fonctionnelle et technique d’un service d’IA : qualité des
résultats, latence, stabilité, coût et taux d’erreur.
12. Étudier la supervision du système après sa mise en production.
13. Expliquer pourquoi une sortie de modèle probabiliste ne doit pas être traitée
automatiquement comme une vérité certaine.
14. Présenter les principes de conception permettant de remplacer un fournisseur ou
un modèle sans réécrire toute l’application.
B. Questions auxquelles le groupe doit répondre
• Pourquoi ne faut-il pas placer directement toute la logique métier dans un prompt ?
• Que se passe-t-il si le fournisseur de l’API d’IA devient indisponible ?
• Comment empêcher qu’une réponse générée soit utilisée sans validation dans une
opération sensible ?
• Comment changer de modèle sans modifier toute l’application ?
• Quels critères permettent de choisir entre un modèle local et un service externe ?
• Comment mesurer la qualité d’une application utilisant l’IA au-delà du simple fait
qu’elle fonctionne ?
C. Travail pratique obligatoire
Concevoir un assistant intelligent destiné à une université, par exemple pour répondre aux
questions des étudiants.
Livrables :
• diagramme de composants ;
• diagramme de séquence d’une requête utilisateur ;
• contrat d’entrée et de sortie du service d’IA ;
• exemple d’API ou prototype fonctionnel ;
• gestion des erreurs et des indisponibilités ;
• protection des données ;
• protocole d’évaluation avec des exemples de réponses attendues ;
• estimation comparative des coûts d’hébergement local et d’utilisation d’une API.