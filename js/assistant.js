// ========================================
// WIDGET DE L'ASSISTANT IA
// Bouton flottant + fenêtre de discussion, reliés à api/assistant.php.
// Les textes sont insérés avec textContent (jamais innerHTML) : une réponse du modèle
// contenant du HTML ou du JavaScript est affichée comme du texte, sans être exécutée.
// ========================================
(function () {
    const config = window.APP_CONFIG || {};
    if (!config.assistant) return;

    const API_URL = config.baseUrl + 'api/assistant.php';
    let enCours = false;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function icone(nom) {
        return el('i', 'bi ' + nom);
    }

    // Construction du widget
    const bouton = el('button', 'assistant-toggle');
    bouton.type = 'button';
    bouton.title = 'Assistant';
    bouton.setAttribute('aria-label', "Ouvrir l'assistant");
    bouton.appendChild(icone('bi-chat-dots-fill'));

    const panneau = el('div', 'assistant-panel');
    panneau.setAttribute('role', 'dialog');
    panneau.setAttribute('aria-label', 'Assistant');

    const entete = el('div', 'assistant-header');
    const titre = el('span', 'assistant-title');
    titre.appendChild(icone('bi-robot'));
    titre.appendChild(document.createTextNode(' Assistant'));
    const actions = el('div', 'assistant-actions');
    const btnNouveau = el('button', 'assistant-icon-btn');
    btnNouveau.type = 'button';
    btnNouveau.title = 'Nouvelle conversation';
    btnNouveau.appendChild(icone('bi-arrow-counterclockwise'));
    const btnFermer = el('button', 'assistant-icon-btn');
    btnFermer.type = 'button';
    btnFermer.title = 'Fermer';
    btnFermer.appendChild(icone('bi-x-lg'));
    actions.append(btnNouveau, btnFermer);
    entete.append(titre, actions);

    const fil = el('div', 'assistant-messages');
    fil.setAttribute('aria-live', 'polite');

    const formulaire = el('form', 'assistant-form');
    const champ = el('textarea', 'assistant-input');
    champ.rows = 1;
    champ.maxLength = 1000;
    champ.placeholder = 'Posez votre question…';
    const btnEnvoyer = el('button', 'assistant-send');
    btnEnvoyer.type = 'submit';
    btnEnvoyer.title = 'Envoyer';
    btnEnvoyer.appendChild(icone('bi-send-fill'));
    formulaire.append(champ, btnEnvoyer);

    const mention = el('div', 'assistant-note',
        "Réponses générées par une IA : elles peuvent contenir des erreurs. Vos notes officielles sont celles de l'application.");

    panneau.append(entete, fil, formulaire, mention);
    document.body.append(bouton, panneau);

    function accueil() {
        fil.replaceChildren();
        ajouterMessage('assistant', 'Bonjour ! Je peux répondre à vos questions sur les notes et sur l\'utilisation de l\'application.');
    }

    // type : user | assistant | erreur ; meta : texte discret sous la bulle (fournisseur utilisé)
    function ajouterMessage(type, texte, meta) {
        const bulle = el('div', 'assistant-msg assistant-msg-' + type);
        bulle.appendChild(el('div', 'assistant-msg-text', texte));
        if (meta) bulle.appendChild(el('div', 'assistant-msg-meta', meta));
        fil.appendChild(bulle);
        fil.scrollTop = fil.scrollHeight;
        return bulle;
    }

    // Réponse du modèle : avertissement éventuel, actions proposées, avis de l'utilisateur
    function ajouterReponse(data) {
        const sources = { modele: 'via ' + data.fournisseur, cache: 'réponse récente (cache)', secours: 'réponse de secours' };
        const bulle = ajouterMessage('assistant', data.reponse, sources[data.source] || '');

        if (data.avertissement) {
            bulle.insertBefore(el('div', 'assistant-msg-warning', data.avertissement), bulle.firstChild);
        }

        // Action sensible : seulement un lien vers le formulaire existant, que l'utilisateur vérifie et valide
        (data.actions || []).forEach(function (action) {
            if (action.type !== 'saisie_note' || action.url.indexOf('modules/notes/saisir.php?') !== 0) return;
            const bloc = el('div', 'assistant-action');
            bloc.appendChild(el('div', 'assistant-action-label', action.libelle));
            const lien = el('a', 'assistant-action-btn', 'Vérifier et enregistrer');
            lien.href = config.baseUrl + action.url;
            bloc.appendChild(lien);
            bloc.appendChild(el('div', 'assistant-action-note', "Rien n'est enregistré tant que vous n'avez pas validé le formulaire."));
            bulle.appendChild(bloc);
        });

        if (data.id_echange) {
            const avis = el('div', 'assistant-feedback');
            [['bi-hand-thumbs-up', 'Réponse utile', 1, false],
             ['bi-hand-thumbs-down', 'Réponse pas utile', -1, false],
             ['bi-flag', 'Signaler une erreur à l\'administration', -1, true]].forEach(function (def) {
                const b = el('button', 'assistant-feedback-btn');
                b.type = 'button';
                b.title = def[1];
                b.appendChild(icone(def[0]));
                b.addEventListener('click', function () {
                    appeler({ avis: { id_echange: data.id_echange, valeur: def[2], signaler: def[3] } }).then(function (r) {
                        if (r && r.ok) {
                            avis.replaceChildren(el('span', '', def[3] ? 'Signalé, merci.' : 'Merci pour votre avis.'));
                        }
                    });
                });
                avis.appendChild(b);
            });
            bulle.appendChild(avis);
        }
        fil.scrollTop = fil.scrollHeight;
    }

    function appeler(corps) {
        return fetch(API_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrfToken },
            body: JSON.stringify(corps)
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, erreur: { code: 'reponse_invalide', message: 'Réponse inattendue du serveur.' } };
            }).then(function (data) {
                if (r.status === 401) {
                    window.location.href = config.baseUrl + 'login.php?timeout=1';
                }
                return data;
            });
        });
    }

    function envoyer(texte) {
        if (enCours) return;
        enCours = true;
        btnEnvoyer.disabled = true;
        ajouterMessage('user', texte);
        const attente = ajouterMessage('assistant', 'Réflexion…');
        attente.classList.add('assistant-msg-wait');

        appeler({ message: texte })
            .then(function (data) {
                attente.remove();
                if (data && data.ok) {
                    ajouterReponse(data);
                } else {
                    ajouterMessage('erreur', (data && data.erreur && data.erreur.message) || 'Une erreur est survenue.');
                }
            })
            .catch(function () {
                attente.remove();
                ajouterMessage('erreur', 'Impossible de joindre le serveur. Vérifiez votre connexion.');
            })
            .finally(function () {
                enCours = false;
                btnEnvoyer.disabled = false;
                champ.focus();
            });
    }

    bouton.addEventListener('click', function () {
        panneau.classList.toggle('open');
        if (panneau.classList.contains('open')) champ.focus();
    });
    btnFermer.addEventListener('click', function () {
        panneau.classList.remove('open');
    });
    btnNouveau.addEventListener('click', function () {
        if (enCours) return;
        appeler({ reinitialiser: true }).finally(accueil);
    });
    formulaire.addEventListener('submit', function (e) {
        e.preventDefault();
        const texte = champ.value.trim();
        if (!texte) return;
        champ.value = '';
        envoyer(texte);
    });
    // Entrée envoie, Maj+Entrée va à la ligne
    champ.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            formulaire.requestSubmit();
        }
    });

    accueil();
})();
