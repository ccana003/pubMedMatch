(function () {
    function hideNativeSurvey() {
        var form = document.getElementById('form');
        if (form) {
            form.style.display = 'none';
        }
    }

    function injectStyles() {
        var style = document.createElement('style');
        style.textContent = '#core-pubmatch-survey-root .cpm-wrap{max-width:980px;margin:12px auto;padding:12px;background:#fff;border:1px solid #ddd;border-radius:6px}#core-pubmatch-survey-root .cpm-card{border:1px solid #d6d6d6;background:#fafafa;border-radius:6px;padding:12px;margin:10px 0}#core-pubmatch-survey-root .cpm-title{font-size:18px;font-weight:600;margin:0 0 6px}#core-pubmatch-survey-root .cpm-sub{font-size:13px;color:#555}#core-pubmatch-survey-root .cpm-empty{padding:10px;border:1px solid #ffd591;background:#fff7e6;border-radius:6px}#core-pubmatch-survey-root .cpm-investigator{margin-top:10px}#core-pubmatch-survey-root .cpm-review{margin-top:10px}#core-pubmatch-survey-root .cpm-review-row{margin-top:8px;display:flex;gap:14px;flex-wrap:wrap;align-items:center}';
        document.head.appendChild(style);
    }

    function render(root, payload) {
        var wrap = document.createElement('div');
        wrap.className = 'cpm-wrap';

        var heading = document.createElement('h3');
        heading.textContent = 'Matched Publications';
        wrap.appendChild(heading);

        var sub = document.createElement('div');
        sub.className = 'cpm-sub';
        sub.textContent = 'Identifier: ' + (payload.identifier || '');
        wrap.appendChild(sub);

        var matches = Array.isArray(payload.matches) ? payload.matches : [];
        if (matches.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'cpm-empty';
            empty.textContent = 'No matches found for this identifier.';
            wrap.appendChild(empty);
            root.appendChild(wrap);
            return;
        }

        matches.forEach(function (m, idx) {
            var card = document.createElement('section');
            card.className = 'cpm-card';
            card.innerHTML =
                '<div class="cpm-sub">Publication ' + (idx + 1) + '</div>' +
                '<div class="cpm-title"></div>' +
                '<div class="cpm-sub"></div>' +
                '<div class="cpm-sub"></div>' +
                '<div class="cpm-sub cpm-investigator"></div>' +
                '<div class="cpm-review"></div>' +
                '<button type="button" class="cpm-save">Save review</button>' +
                '<div class="cpm-sub cpm-save-status"></div>';

            card.querySelector('.cpm-title').textContent = m.title || '(Untitled publication)';
            var highlightedAuthors = highlightName(m.authors || '', m.matched_investigator || '');
            card.querySelectorAll('.cpm-sub')[1].innerHTML = highlightedAuthors;

            var line = '';
            if (m.journal) line += m.journal;
            if (m.pub_year) line += (line ? ' (' + m.pub_year + ')' : m.pub_year);
            if (m.pmid) line += (line ? ' • PMID: ' + m.pmid : 'PMID: ' + m.pmid);
            card.querySelectorAll('.cpm-sub')[2].textContent = line;
            card.querySelector('.cpm-investigator').innerHTML = '<strong>Matched investigator:</strong> ' + escapeHtml(m.matched_investigator || '');

            var review = card.querySelector('.cpm-review');
            review.innerHTML = [
                '<div class="cpm-review-row">',
                '<label>Is this your publication? ',
                '<select class="cpm-is-mine"><option value=""></option><option value="1">Yes</option><option value="0">No</option></select></label> ',
                '<label>PI confidence ',
                '<select class="cpm-pi-confidence"><option value=""></option><option value="1">Low</option><option value="2">Medium</option><option value="3">High</option></select></label> ',
                '<label>PI review date <input type="date" class="cpm-review-date"></label>',
                '</div>',
                '<div class="cpm-review-row">',
                '<label>Core related?</label> ',
                '<label><input type="radio" name="core_' + idx + '" class="cpm-core-related" value="1"> Yes</label> ',
                '<label><input type="radio" name="core_' + idx + '" class="cpm-core-related" value="0"> No</label> ',
                '<label><input type="radio" name="core_' + idx + '" class="cpm-core-related" value="2"> Maybe</label> ',
                '</div>',
                '<div class="cpm-review-row">',
                '<label>Level of support</label> ',
                '<label><input type="radio" name="support_' + idx + '" class="cpm-level-support" value="1"> Low</label> ',
                '<label><input type="radio" name="support_' + idx + '" class="cpm-level-support" value="2"> Medium</label> ',
                '<label><input type="radio" name="support_' + idx + '" class="cpm-level-support" value="3"> High</label> ',
                '</div>'
            ].join('');

            card.querySelector('.cpm-is-mine').value = m.is_mine || '';
            card.querySelector('.cpm-pi-confidence').value = m.pi_confidence || '';
            checkRadio(card, '.cpm-core-related', m.is_core_related || '');
            checkRadio(card, '.cpm-level-support', m.level_of_support || '');
            card.querySelector('.cpm-review-date').value = m.pi_review_date || todayDate();

            card.querySelector('.cpm-save').addEventListener('click', function () {
                saveReview(card, m, payload.identifier || '');
            });

            wrap.appendChild(card);
        });

        root.appendChild(wrap);
    }

    function todayDate() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function escapeRegExp(s) {
        return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function highlightName(authors, investigator) {
        var safeAuthors = escapeHtml(authors);
        if (!investigator) return safeAuthors;
        var escaped = escapeRegExp(investigator.trim());
        if (!escaped) return safeAuthors;
        var r = new RegExp('(' + escaped + ')', 'ig');
        return safeAuthors.replace(r, '<mark>$1</mark>');
    }

    function checkRadio(card, selector, value) {
        if (!value) return;
        var radios = card.querySelectorAll(selector);
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].value === String(value)) radios[i].checked = true;
        }
    }

    function getRadioValue(card, selector) {
        var radios = card.querySelectorAll(selector);
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked) return radios[i].value;
        }
        return '';
    }

    async function saveReview(card, match, identifier) {
        var status = card.querySelector('.cpm-save-status');
        status.textContent = 'Saving...';

        try {
            var primary = new URL(window.CorePubMatchSurvey.apiBase, window.location.origin);
            primary.searchParams.set('cpm_action', 'save_review');
            primary.searchParams.set('pid', window.CorePubMatchSurvey.pid || '');
            primary.searchParams.set('core_pubmatch_identifier', identifier || '');
            primary.searchParams.set('s', window.CorePubMatchSurvey.surveyHash || '');
            primary.searchParams.set('cpm_sig', window.CorePubMatchSurvey.sig || '');

            var secondary = new URL(primary.toString());
            secondary.searchParams.set('page', 'pages/survey_matches.php');

            var body = {
                record_id: match.record_id || '',
                instance: parseInt(match.instance || '0', 10),
                is_mine: card.querySelector('.cpm-is-mine').value || '',
                pi_confidence: card.querySelector('.cpm-pi-confidence').value || '',
                is_core_related: getRadioValue(card, '.cpm-core-related'),
                level_of_support: getRadioValue(card, '.cpm-level-support'),
                pi_review_date: card.querySelector('.cpm-review-date').value || todayDate()
            };

            var urls = [primary.toString(), secondary.toString()];
            var payload = null;
            var lastErr = null;
            for (var i = 0; i < urls.length; i++) {
                try {
                    var response = await fetch(urls[i], {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify(body)
                    });
                    var raw = await response.text();
                    payload = JSON.parse(raw);
                    if (!response.ok || payload.error) throw new Error(payload.error || 'Save failed.');
                    break;
                } catch (e) {
                    lastErr = e;
                    payload = null;
                }
            }
            if (!payload) throw lastErr || new Error('Save failed.');
            status.textContent = 'Saved';
            setTimeout(function () {
                window.location.reload();
            }, 250);
        } catch (e) {
            status.textContent = 'Save failed: ' + e.message;
        }
    }

    async function init() {
        if (!window.CorePubMatchSurvey || !window.CorePubMatchSurvey.apiBase) {
            return;
        }

        hideNativeSurvey();
        injectStyles();

        var root = document.getElementById('core-pubmatch-survey-root');
        if (!root) {
            return;
        }

        try {
            var primary = new URL(window.CorePubMatchSurvey.apiBase, window.location.origin);
            primary.searchParams.set('cpm_action', 'survey_matches');
            primary.searchParams.set('pid', window.CorePubMatchSurvey.pid || '');
            primary.searchParams.set('core_pubmatch_identifier', window.CorePubMatchSurvey.identifier || '');
            primary.searchParams.set('s', window.CorePubMatchSurvey.surveyHash || '');
            primary.searchParams.set('cpm_sig', window.CorePubMatchSurvey.sig || '');

            var secondary = new URL(primary.toString());
            secondary.searchParams.delete('cpm_action');
            secondary.searchParams.set('page', 'pages/survey_matches.php');

            var urls = [primary.toString(), secondary.toString()];
            var payload = null;
            var lastError = null;

            for (var i = 0; i < urls.length; i++) {
                try {
                    var response = await fetch(urls[i], {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    var rawText = await response.text();
                    try {
                        payload = JSON.parse(rawText);
                    } catch (parseError) {
                        throw new Error('Non-JSON response from survey endpoint: ' + rawText.slice(0, 200));
                    }

                    if (!response.ok || payload.error) {
                        throw new Error(payload.error || 'Failed to load matches.');
                    }

                    break;
                } catch (e) {
                    lastError = e;
                }
            }

            if (!payload) {
                throw lastError || new Error('Unable to load matches.');
            }

            render(root, payload);
        } catch (error) {
            root.innerHTML = '<div style="color:#b00020;">' + error.message + '</div>';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
