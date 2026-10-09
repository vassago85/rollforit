@extends('layouts.admin', ['title' => 'Roll for It: Terms'])

@section('content')
<s-page heading="Terms and conditions">
    <s-button slot="primary-action" variant="primary" id="publish">Publish terms page</s-button>

    <s-banner id="lawyer" tone="warning" heading="Have these terms reviewed by a lawyer before going live" hidden>
        <s-stack gap="small-200">
            <s-paragraph>The generated terms are a starting point, not legal advice. Promotional competitions in South Africa are governed by the Consumer Protection Act.</s-paragraph>
        </s-stack>
    </s-banner>
    <s-banner id="error" tone="critical" hidden></s-banner>

    <s-section heading="Status">
        <s-stack gap="base">
            <s-stack direction="inline" gap="small" alignItems="center">
                <s-badge id="published-badge"></s-badge>
                <s-link id="terms-link" target="_blank" hidden>View live page</s-link>
            </s-stack>
            <s-checkbox id="reviewed" label="Terms reviewed (hides the lawyer warning)"></s-checkbox>
        </s-stack>
    </s-section>

    <s-section heading="Terms source">
        <s-stack gap="base">
            <s-select id="terms_mode" label="Use">
                <s-option value="generated">Generated terms (kept in sync with your settings)</s-option>
                <s-option value="own">My own page</s-option>
            </s-select>
            <s-select id="own_page_id" label="Your terms page" hidden></s-select>
            <s-paragraph id="mode-help" color="subdued"></s-paragraph>
            <s-button id="save-mode">Save source</s-button>
        </s-stack>
    </s-section>

    <s-section heading="Preview" id="preview-section">
        <s-banner id="missing" tone="info" heading="Fill these settings to generate the terms" hidden>
            <s-unordered-list id="missing-list"></s-unordered-list>
        </s-banner>
        <div id="preview" class="rfi-preview" hidden></div>
    </s-section>

    <s-section heading="Published versions">
        <s-paragraph color="subdued">Every roll records the terms version that applied when it was made.</s-paragraph>
        <s-table id="versions-table">
            <s-table-header-row>
                <s-table-header>Version</s-table-header>
                <s-table-header>Source</s-table-header>
                <s-table-header>Published</s-table-header>
                <s-table-header></s-table-header>
            </s-table-header-row>
            <s-table-body id="versions"></s-table-body>
        </s-table>
        <div id="version-view" class="rfi-preview" hidden></div>
    </s-section>
</s-page>
@endsection

@push('scripts')
<script>
(() => {
  const { api, toast, el, esc } = RFI;
  let pagesLoaded = false;

  function render(d) {
    el('lawyer').hidden = d.reviewed;
    el('reviewed').checked = d.reviewed;
    el('published-badge').textContent = d.published ? 'Published' : 'Not published';
    el('published-badge').tone = d.published ? 'success' : 'attention';
    el('terms-link').hidden = !d.terms_url;
    if (d.terms_url) { el('terms-link').href = d.terms_url; el('terms-link').textContent = d.terms_url; }

    el('terms_mode').value = d.terms_mode;
    el('own_page_id').hidden = d.terms_mode !== 'own';
    el('mode-help').textContent = d.terms_mode === 'own'
      ? 'We record a copy of your page each time you publish, so every roll is linked to the exact wording. Make sure your page covers everything in the generated version.'
      : 'Publishing creates a page at /pages/roll-for-it-terms. It is updated automatically whenever you save settings.';
    if (d.terms_mode === 'own') loadPages(d.own_page_id);

    const generated = d.terms_mode === 'generated';
    el('missing').hidden = !generated || !d.missing.length;
    el('missing-list').innerHTML = d.missing.map((m) => '<s-list-item>' + esc(m) + '</s-list-item>').join('');
    el('preview').hidden = !d.preview_html;
    el('preview').innerHTML = d.preview_html || '';
    el('preview-section').hidden = !generated;

    el('versions').innerHTML = d.versions.length
      ? d.versions.map((v) => '<s-table-row><s-table-cell>v' + v.version + '</s-table-cell><s-table-cell>'
          + esc(v.source) + '</s-table-cell><s-table-cell>' + esc(new Date(v.published_at).toLocaleString())
          + '</s-table-cell><s-table-cell><s-button variant="tertiary" data-version="' + v.version + '">View</s-button></s-table-cell></s-table-row>').join('')
      : '<s-table-row><s-table-cell>No versions yet</s-table-cell><s-table-cell></s-table-cell><s-table-cell></s-table-cell><s-table-cell></s-table-cell></s-table-row>';
  }

  async function loadPages(selected) {
    if (pagesLoaded) { el('own_page_id').value = selected || ''; return; }
    try {
      const { pages } = await api('GET', 'terms/pages');
      el('own_page_id').innerHTML = '<s-option value="">Choose a page</s-option>' + pages.map((p) =>
        '<s-option value="' + esc(p.id) + '">' + esc(p.title) + (p.isPublished ? '' : ' (hidden)') + '</s-option>').join('');
      el('own_page_id').value = selected || '';
      pagesLoaded = true;
    } catch (e) {
      toast(e.message, true);
    }
  }

  async function run(fn) {
    el('error').hidden = true;
    try {
      render(await fn());
    } catch (e) {
      el('error').hidden = false;
      el('error').textContent = e.message;
    }
  }

  el('publish').addEventListener('click', async () => {
    el('publish').loading = true;
    await run(() => api('POST', 'terms/publish'));
    el('publish').loading = false;
    if (el('error').hidden) toast('Terms page published');
  });
  el('reviewed').addEventListener('change', () => run(() => api('POST', 'terms/reviewed', { reviewed: !!el('reviewed').checked })));
  el('terms_mode').addEventListener('change', () => {
    el('own_page_id').hidden = el('terms_mode').value !== 'own';
    if (el('terms_mode').value === 'own') loadPages(null);
  });
  el('save-mode').addEventListener('click', () => run(() => api('PUT', 'terms', {
    terms_mode: el('terms_mode').value,
    own_page_id: el('terms_mode').value === 'own' ? (el('own_page_id').value || null) : null,
  })).then(() => toast('Saved. Publish to make it live.')));
  el('versions').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-version]');
    if (!button) return;
    try {
      const v = await api('GET', 'terms/versions/' + button.dataset.version);
      el('version-view').hidden = false;
      el('version-view').innerHTML = v.content;
    } catch (e) {
      toast(e.message, true);
    }
  });

  run(() => api('GET', 'terms'));
})();
</script>
@endpush
