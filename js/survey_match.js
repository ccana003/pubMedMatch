(function () {
    function hideNativeSurvey() {
        var form = document.getElementById('form');
        if (form) {
            form.style.display = 'none';
        }
    }

    function statusLabel(value) {
        if (value === '1') {
            return 'Yes';
        }
        if (value === '2') {
            return 'No';
        }
        return 'Decide later';
    }

    function createCard(match, index, saveUrl) {
        var wrapper = document.createElement('section');
        wrapper.className = 'core-pubmatch-card';
        wrapper.innerHTML =
            '<div class="core-pubmatch-card-header">' +
            '<div><strong>Publication ' + (index + 1) + '</strong></div>' +
            '<div class="core-pubmatch-state" data-state>Saved</div>' +
            '</div>' +
            '<h4 class="core-pubmatch-title"></h4>' +
            '<div class="core-pubmatch-meta"></div>' +
            '<div class="core-pubmatch-question">Is this your publication?</div>' +
            '<div class="core-pubmatch-options"></div>';

        wrapper.querySelector('.core-pubmatch-title').textContent = match.title || '(Untitled publication)';
        var meta = [];
        if (match.authors) meta.push(match.authors);
        if (match.journal) meta.push(match.journal);
        if (match.pub_year) meta.push(match.pub_year);
        if (match.pmid) meta.push('PMID: ' + match.pmid);
        wrapper.querySelector('.core-pubmatch-meta').textContent = meta.join(' • ');

        var options = wrapper.querySelector('.core-pubmatch-options');
        var statusMap = [
            { value: '1', label: 'Yes' },
            { value: '2', label: 'No' },
            { value: '0', label: 'Decide later' }
        ];

        statusMap.forEach(function (item) {
            var label = document.createElement('label');
            label.className = 'core-pubmatch-option';
            label.innerHTML = '<input type="radio" name="status_' + match.instance + '" value="' + item.value + '"> ' + item.label;
            var input = label.querySelector('input');
            input.checked = (match.status || '0') === item.value;
            input.addEventListener('change', function () {
                saveStatus(saveUrl, match.record_id, match.instance, item.value, wrapper);
            });
            options.appendChild(label);
        });

        return wrapper;
    }

    async function saveStatus(url, recordId, instance, value, wrapper) {
        var state = wrapper.querySelector('[data-state]');
        state.textContent = 'Saving...';
        state.className = 'core-pubmatch-state';

        try {
            var response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    record_id: recordId,
                    instance: instance,
                    status: value
                })
            });
            var payload = await response.json();
            if (!response.ok || payload.error) {
                throw new Error(payload.error || 'Unable to save.');
            }
            state.textContent = 'Saved: ' + statusLabel(value);
            state.className = 'core-pubmatch-state core-pubmatch-ok';
        } catch (error) {
            state.textContent = 'Save failed';
            state.className = 'core-pubmatch-state core-pubmatch-error';
        }
    }

    function render(matches, root, saveUrl) {
        var container = document.createElement('div');
        container.className = 'core-pubmatch-list';
        if (!Array.isArray(matches) || matches.length === 0) {
            container.innerHTML = '<p>No matched publications were found for this identifier.</p>';
            root.appendChild(container);
            return;
        }

        matches.forEach(function (match, index) {
            container.appendChild(createCard(match, index, saveUrl));
        });
        root.appendChild(container);
    }

    function injectStyles() {
        var style = document.createElement('style');
        style.textContent = '.core-pubmatch-card{border:1px solid #d4d4d4;background:#fafafa;padding:12px;margin:12px 0;border-radius:6px}.core-pubmatch-card-header{display:flex;justify-content:space-between;color:#444}.core-pubmatch-title{margin:8px 0 6px 0}.core-pubmatch-meta{font-size:13px;color:#666;margin-bottom:10px}.core-pubmatch-question{font-weight:600;margin-bottom:6px}.core-pubmatch-option{display:inline-block;margin-right:16px}.core-pubmatch-ok{color:#1a7f37}.core-pubmatch-error{color:#b00020}';
        document.head.appendChild(style);
    }

    async function initialize() {
        if (!window.CorePubMatchSurvey || !window.CorePubMatchSurvey.matchesUrl) {
            return;
        }

        hideNativeSurvey();
        injectStyles();

        var root = document.getElementById('core-pubmatch-survey-root');
        if (!root) {
            return;
        }

        try {
            var response = await fetch(window.CorePubMatchSurvey.matchesUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            var payload = await response.json();
            if (!response.ok || payload.error) {
                throw new Error(payload.error || 'Unable to load matches.');
            }

            render(payload.matches || [], root, window.CorePubMatchSurvey.saveUrl);
        } catch (error) {
            root.innerHTML = '<div style="color:#b00020;">' + error.message + '</div>';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
