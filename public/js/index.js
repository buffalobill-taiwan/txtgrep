function initIndex() {
    document.querySelector('#search-form').addEventListener('submit', event => {
        event.preventDefault();
        search();
    });
    initBtnScrollToTop();

    if (document.querySelector('[name="keyword"]').value.trim()) {
        search();
    }
}

function initBtnScrollToTop() {
    const button = document.querySelector('#btnScrollToTop');
    if (!button) return;

    button.addEventListener('click', () => window.scrollTo(0, 0));
    const update = () => button.classList.toggle('uk-hidden', window.scrollY <= 20);
    window.addEventListener('scroll', update, { passive: true });
    update();
}

async function search() {
    const button = document.querySelector('#go');
    const keyword = document.querySelector('[name="keyword"]').value;
    const source = document.querySelector('[name="source"]').value;
    if (button.disabled || !keyword.trim()) return;

    const status = document.querySelector('#search-status');
    const table = document.querySelector('#result-table');
    const modal = UIkit.modal('#loading-dialog');
    button.disabled = true;
    status.textContent = '';
    table.querySelectorAll('tbody').forEach((body) => body.remove());
    table.tHead.classList.add('uk-hidden');

    const startedAt = performance.now();
    async function waitForMinimumLoadingTime() {
        const remaining = 500 - (performance.now() - startedAt);
        if (remaining > 0) {
            await new Promise(resolve => setTimeout(resolve, Math.ceil(remaining)));
        }
    }

    try {
        modal.show();
        const response = await fetch('grep.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ keyword, source }),
            signal: AbortSignal.timeout(50000),
        });
        const results = await response.json();
        await waitForMinimumLoadingTime();
        if (!response.ok || !Array.isArray(results)) {
            status.textContent = results?.error || '搜尋失敗，請稍後再試。';
            return;
        }
        renderResults(table, results);
        const limit = response.headers.get('X-Search-Limit') || '500';
        status.textContent = response.headers.get('X-Search-Truncated') === '1'
            ? `「${keyword}」僅顯示前 ${limit} 筆結果。`
            : `「${keyword}」找到 ${results.length} 筆結果。`;
    } catch {
        await waitForMinimumLoadingTime();
        status.textContent = '搜尋失敗，請稍後再試。';
    } finally {
        button.disabled = false;
        modal.hide();
    }
}

function renderResults(table, results) {
    const encoder = new TextEncoder();
    const decoder = new TextDecoder('utf-8', { ignoreBOM: true });
    const body = table.createTBody();
    for (const item of results) {
        const row = body.insertRow();
        const chapter = document.createElement('th');
        chapter.scope = 'row';
        chapter.className = 'td-file uk-text-top';
        row.append(chapter);
        if (item.ref && (item.url || item.inode)) {
            const link = document.createElement('a');
            link.className = 'uk-text-nowrap uk-text-large';
            link.href = item.url || ('reader.php?inode=' + encodeURIComponent(item.inode));
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.title = '在新分頁開啟';
            link.setAttribute('aria-label', '在新分頁開啟：' + item.file);
            link.textContent = item.file;
            chapter.append(link);
        } else {
            chapter.textContent = item.file;
        }

        const passage = row.insertCell();
        passage.className = 'td-text';
        const text = encoder.encode(item.text);
        const slice = (start, end) => decoder.decode(text.subarray(start, end));
        let end = 0;
        for (const [match, offset] of item.matches) {
            if (!match) continue;
            const matchEnd = offset + encoder.encode(match).length;
            if (matchEnd <= end) continue;
            if (offset > end) passage.append(slice(end, offset));
            const highlight = document.createElement('span');
            highlight.className = 'uk-text-danger';
            highlight.textContent = slice(Math.max(end, offset), matchEnd);
            passage.append(highlight);
            end = matchEnd;
        }
        passage.append(slice(end));
    }
    table.append(body);
    table.tHead.classList.toggle('uk-hidden', results.length === 0);
}
