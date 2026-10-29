/* Subject -> year -> paper -> question cascade, with inline "register missing paper/question". */
(function () {
    const $ = (id) => document.getElementById(id);
    const root = $('paperPicker');
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const withQuestion = root.dataset.withQuestion === '1';
    // paperBase serves the JSON lookups (/api/papers/{id}/...); storeBase is where new questions are POSTed (/papers/{id}/questions).
    const urls = { papers: root.dataset.papers, topics: root.dataset.topics, paperBase: root.dataset.paperBase, storeBase: root.dataset.storeBase };
    const sub = $('pp_subject'), year = $('pp_year'), paper = $('pp_paper'), question = $('pp_question');
    const paperAttempt = $('paper_attempt_id');

    const getJson = (url) => fetch(url, { headers: { Accept: 'application/json' } }).then((r) => r.json());
    const postJson = (url, body) => fetch(url, {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body),
    }).then(async (r) => ({ ok: r.ok, status: r.status, data: await r.json().catch(() => ({})) }));

    const t = (s) => (window.I18N && window.I18N[s]) || s;
    function fill(select, items, placeholder, text) {
        select.innerHTML = '';
        select.add(new Option(placeholder, ''));
        items.forEach((i) => select.add(new Option(text(i), i.id)));
    }
    const msg = (el, text, ok) => { el.textContent = text; el.className = 'small mt-1 ' + (ok ? 'text-success' : 'text-danger'); };

    async function loadPapers(selectId) {
        fill(paper, [], t('Choose paper…'), () => '');
        if (withQuestion) fill(question, [], t('Choose question…'), () => '');
        if (!sub.value || !year.value) return;
        const items = await getJson(`${urls.papers}?subject_id=${sub.value}&year=${year.value}`);
        fill(paper, items, items.length ? t('Choose paper…') : t('No paper registered — add it below'), (p) => p.paper_name || p.label);
        if (selectId) paper.value = selectId;
    }

    async function loadQuestions(selectId) {
        if (!withQuestion) return;
        fill(question, [], t('Choose question…'), () => '');
        if (paperAttempt) fill(paperAttempt, [], t('Not part of a full-paper attempt'), () => '');
        if (!paper.value) return;
        const items = await getJson(`${urls.paperBase}/${paper.value}/questions`);
        fill(question, items, items.length ? t('Choose question…') : t('No questions registered — add one below'),
            (q) => `${q.label}${q.attempts ? ` · ${q.attempts} previous attempt(s)` : ''}`);
        question.querySelectorAll('option').forEach((o, i) => { if (i > 0) o.dataset.max = items[i - 1].max_marks; });
        if (selectId) question.value = selectId;
        if (paperAttempt) {
            const pas = await getJson(`${urls.paperBase}/${paper.value}/attempts`);
            fill(paperAttempt, pas, t('Not part of a full-paper attempt'), (a) => `${t('Part of:')} ${a.label}`);
        }
        showMax();
    }

    function showMax() {
        const o = question && question.selectedOptions[0];
        const el = $('pp_max');
        if (el) el.textContent = o && o.dataset.max ? `${t('Maximum marks for this question:')} ${o.dataset.max}` : '';
    }

    sub.addEventListener('change', () => { loadPapers(); initTopics(); });
    year.addEventListener('change', () => loadPapers());
    paper.addEventListener('change', () => loadQuestions());
    if (question) question.addEventListener('change', showMax);

    // ---- add a missing paper -----------------------------------------------------------------
    $('ap_btn').addEventListener('click', async () => {
        const out = $('ap_msg');
        if (!sub.value || !year.value || !$('ap_name').value.trim()) return msg(out, t('Choose subject and year, then enter a paper name (e.g. Paper I).'), false);
        const r = await postJson(root.dataset.storePaper, {
            subject_id: sub.value, exam_year: year.value, paper_name: $('ap_name').value.trim(), max_marks: $('ap_max').value || null,
        });
        if (!r.ok) return msg(out, Object.values(r.data.errors || {}).flat().join(' ') || t('Could not save the paper.'), false);
        await loadPapers(r.data.id);
        await loadQuestions();
        msg(out, r.data.existing ? t('That paper already existed and is now selected.') : t('Paper registered and selected.'), true);
    });

    // ---- add a missing question --------------------------------------------------------------
    let topicsLoaded = null;
    async function initTopics() {
        const el = $('aq_topics');
        if (!el || !window.jQuery || !sub.value) return;
        if (topicsLoaded === sub.value) return;
        topicsLoaded = sub.value;
        const items = await getJson(`${urls.topics}?subject_id=${sub.value}`);
        const $el = window.jQuery(el);
        if ($el.data('select2')) $el.select2('destroy');
        el.innerHTML = '';
        const groups = {};
        items.forEach((t) => { (groups[t.group] = groups[t.group] || []).push(t); });
        Object.keys(groups).forEach((g) => {
            const og = document.createElement('optgroup'); og.label = g;
            groups[g].forEach((t) => og.appendChild(new Option(t.text, t.id)));
            el.appendChild(og);
        });
        $el.select2({ width: '100%', placeholder: t('Search and pick one or more topics') });
    }

    const aq = $('aq_btn');
    if (aq) aq.addEventListener('click', async () => {
        const out = $('aq_msg');
        if (!paper.value) return msg(out, t('Choose a paper first.'), false);
        if (!$('aq_no').value.trim() || !$('aq_max').value) return msg(out, t('Enter the question number and maximum marks.'), false);
        const topics = Array.from($('aq_topics').selectedOptions).map((o) => o.value);
        const r = await postJson(`${urls.storeBase}/${paper.value}/questions`, {
            question_no: $('aq_no').value.trim(), max_marks: $('aq_max').value, topic_ids: topics,
        });
        if (!r.ok) return msg(out, Object.values(r.data.errors || {}).flat().join(' ') || t('Could not save the question.'), false);
        await loadQuestions(r.data.id);
        msg(out, r.data.existing ? t('That question already existed and is now selected.') : t('Question registered and selected.'), true);
    });

    // ---- preselect (e.g. "retry this question") -----------------------------------------------
    (async function boot() {
        initTopics();
        if (root.dataset.preSubject) {
            sub.value = root.dataset.preSubject; year.value = root.dataset.preYear;
            await loadPapers(root.dataset.prePaper);
            await loadQuestions(root.dataset.preQuestion);
        }
    })();
})();
