/* Prospect Studio — interactions de l'administration.
   Le front reste volontairement minimal : pas de framework, pas de build. */

(function () {
    'use strict';

    /** Écrit une ligne dans la console de progression. */
    function logLine(box, text, kind) {
        if (!box) return;
        var line = document.createElement('div');
        line.className = 'line' + (kind ? ' ' + kind : '');
        line.textContent = text;
        box.appendChild(line);
        box.scrollTop = box.scrollHeight;
        return line;
    }

    /**
     * Exécute une étape distante en Server-Sent Events.
     * Résout avec les données de l'événement « done ».
     */
    function runStep(url, box, handlers) {
        return new Promise(function (resolve, reject) {
            var source = new EventSource(url);
            var progressLine = null;
            var settled = false;

            function finish(fn, value) {
                if (settled) return;
                settled = true;
                source.close();
                fn(value);
            }

            source.addEventListener('step', function (event) {
                var data = JSON.parse(event.data);
                progressLine = null;
                logLine(box, data.message, data.state === 'done' ? 'done' : (data.state === 'warn' ? 'warn' : ''));
            });

            source.addEventListener('progress', function (event) {
                var data = JSON.parse(event.data);
                var text = '  … ' + data.chars.toLocaleString('fr-FR') + ' caractères générés';
                if (progressLine) {
                    progressLine.textContent = text;
                } else {
                    progressLine = logLine(box, text, 'progress');
                }
            });

            source.addEventListener('brief', function (event) {
                var data = JSON.parse(event.data);
                if (handlers && handlers.onBrief) handlers.onBrief(data);
                if (data.accroche) logLine(box, '  « ' + data.accroche + ' »');
            });

            source.addEventListener('error', function (event) {
                if (event.data) {
                    var data = JSON.parse(event.data);
                    logLine(box, 'Erreur : ' + data.message, 'err');
                    finish(reject, new Error(data.message));
                } else if (source.readyState === EventSource.CLOSED) {
                    logLine(box, 'Connexion interrompue par le serveur.', 'err');
                    finish(reject, new Error('Connexion interrompue.'));
                }
            });

            source.addEventListener('done', function (event) {
                finish(resolve, JSON.parse(event.data));
            });
        });
    }

    /**
     * Traitements en flux déclenchés depuis la fiche prospect : analyse directe
     * et lecture par l'IA. Chaque bouton porteur de data-analyze est câblé —
     * querySelector n'en renverrait qu'un seul et laisserait les autres inertes.
     */
    function bindAnalyze() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-analyze]'), function (button) {
            var repos = button.textContent.trim();
            var occupe = button.dataset.busy || 'Traitement en cours…';

            function start() {
                var box = document.getElementById('console');
                var panel = document.getElementById('run-panel');
                if (panel) panel.hidden = false;
                if (box) box.innerHTML = '';

                // Les autres déclencheurs sont neutralisés le temps du traitement.
                Array.prototype.forEach.call(document.querySelectorAll('[data-analyze]'), function (other) {
                    other.disabled = true;
                });
                button.textContent = occupe;

                runStep(button.dataset.analyze, box, {})
                    .then(function (data) {
                        logLine(box, resume(data), 'done');
                        setTimeout(function () { window.location.reload(); }, 900);
                    })
                    .catch(function () {
                        Array.prototype.forEach.call(document.querySelectorAll('[data-analyze]'), function (other) {
                            other.disabled = false;
                        });
                        button.textContent = repos;
                    });
            }

            button.addEventListener('click', start);
            if (button.dataset.autorun === '1') start();
        });
    }

    /** Ligne de conclusion, selon ce que l'étape a renvoyé. */
    function resume(data) {
        if (data && typeof data.score !== 'undefined') {
            return 'Analyse terminée — score ' + data.score + '/100 (' + data.level + ')';
        }
        if (data && typeof data.pages !== 'undefined') {
            return 'Lecture terminée — ' + data.pages + ' page(s), ' + data.services + ' prestation(s) relevée(s)';
        }
        return 'Terminé';
    }

    /** Génération et retouche des maquettes, étape par étape. */
    function bindGenerate() {
        var forms = document.querySelectorAll('[data-generate]');
        if (!forms.length) return;

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var base = form.dataset.generate;
                var mode = form.dataset.mode || 'new';
                var instruction = (form.querySelector('[name=instruction]') || {}).value || '';
                var checked = form.querySelectorAll('[name="pages[]"]:checked');
                var pages = checked.length
                    ? Array.prototype.map.call(checked, function (input) { return input.value; })
                    : (form.dataset.pages || '').split(',').filter(Boolean);

                var box = document.getElementById('console');
                var panel = document.getElementById('run-panel');
                var submit = form.querySelector('[type=submit]');

                if (panel) panel.hidden = false;
                if (box) box.innerHTML = '';
                if (submit) { submit.disabled = true; submit.dataset.label = submit.textContent; submit.textContent = 'Génération en cours…'; }

                var query = function (extra) {
                    var params = new URLSearchParams(extra);
                    return base + '&' + params.toString();
                };

                runStep(query({ step: 'brief', mode: mode, instruction: instruction }), box, {})
                    .then(function (data) {
                        var version = data.version;
                        // Les pages s'enchaînent séquentiellement : une requête
                        // par page reste sous les limites d'exécution des
                        // hébergements mutualisés.
                        return pages.reduce(function (chain, page) {
                            return chain.then(function () {
                                return runStep(query({
                                    step: page,
                                    mode: mode,
                                    version: version,
                                    instruction: instruction
                                }), box, {});
                            });
                        }, Promise.resolve());
                    })
                    .then(function () {
                        logLine(box, 'Maquette prête. Rechargement…', 'done');
                        setTimeout(function () { window.location.reload(); }, 900);
                    })
                    .catch(function () {
                        if (submit) { submit.disabled = false; submit.textContent = submit.dataset.label || 'Relancer'; }
                    });
            });
        });
    }

    /** Bascule de largeur pour prévisualiser la maquette en mobile ou tablette. */
    function bindDevices() {
        var bar = document.querySelector('[data-devices]');
        if (!bar) return;
        var frame = document.getElementById(bar.dataset.devices);
        if (!frame) return;

        bar.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-width]');
            if (!button) return;
            Array.prototype.forEach.call(bar.querySelectorAll('button'), function (item) {
                item.classList.toggle('active', item === button);
            });
            frame.style.maxWidth = button.dataset.width;
            frame.style.margin = button.dataset.width === '100%' ? '0' : '0 auto';
        });
    }

    /** Insère une variable de modèle à la position du curseur. */
    function bindVariables() {
        document.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-insert]');
            if (!chip) return;
            var target = document.querySelector(chip.dataset.target || 'textarea[name=body]');
            if (!target) return;

            var value = chip.dataset.insert;
            var start = target.selectionStart || 0;
            var end = target.selectionEnd || 0;
            target.value = target.value.slice(0, start) + value + target.value.slice(end);
            target.focus();
            target.selectionStart = target.selectionEnd = start + value.length;
        });
    }

    /** Confirmation avant les actions destructrices. */
    function bindConfirm() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    }

    /** Copie dans le presse-papiers (lien de maquette, URL de cron…). */
    function bindCopy() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-copy]');
            if (!button) return;
            event.preventDefault();

            var text = button.dataset.copy;
            var restore = button.textContent;
            var done = function () {
                button.textContent = 'Copié';
                setTimeout(function () { button.textContent = restore; }, 1600);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var helper = document.createElement('textarea');
                helper.value = text;
                helper.style.position = 'fixed';
                helper.style.opacity = '0';
                document.body.appendChild(helper);
                helper.select();
                try { document.execCommand('copy'); done(); } catch (error) { /* sans effet */ }
                document.body.removeChild(helper);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindAnalyze();
        bindGenerate();
        bindDevices();
        bindVariables();
        bindConfirm();
        bindCopy();
    });
})();
