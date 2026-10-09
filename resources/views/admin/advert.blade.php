@extends('layouts.admin', ['title' => 'Roll4It: Advert copy'])

@section('content')
<s-page heading="Advert copy">
    <s-banner id="changes" tone="warning" heading="Your settings changed since you last copied these adverts" hidden>
        <s-stack gap="small-200">
            <s-paragraph>Adverts you already posted may now be out of date. Update or remove them, then acknowledge.</s-paragraph>
            <s-unordered-list id="changes-list"></s-unordered-list>
            <s-button id="ack">I've updated my adverts</s-button>
        </s-stack>
    </s-banner>
    <s-banner id="first-ack" tone="info" hidden>
        <s-stack gap="small-200">
            <s-paragraph>Once you've posted adverts, click below. We'll warn you here if a later settings change makes them out of date.</s-paragraph>
            <s-button id="ack-first">I've posted these adverts</s-button>
        </s-stack>
    </s-banner>
    <s-banner id="error" tone="critical" hidden></s-banner>

    <div id="blocks"></div>
</s-page>
@endsection

@push('scripts')
<script>
(() => {
  const { api, toast, el, esc, copy } = RFI;
  let blocks = [];

  function render(d) {
    blocks = d.blocks;
    el('changes').hidden = !d.changes.length;
    el('changes-list').innerHTML = d.changes.map((c) =>
      '<s-list-item>' + esc(c.field) + ': ' + esc(c.from) + ' → ' + esc(c.to) + '</s-list-item>').join('');
    el('first-ack').hidden = d.acknowledged || d.missing.length > 0;

    el('blocks').innerHTML = d.blocks.map((b, i) => '<s-section heading="' + esc(b.label) + '">'
      + (b.error
          ? '<s-banner tone="critical">' + esc(b.error) + '</s-banner>'
          : '<s-stack gap="small"><s-box padding="base" background="subdued" borderRadius="base"><pre class="rfi-copy">'
            + esc(b.text) + '</pre></s-box><s-stack direction="inline"><s-button data-copy="' + i + '">Copy</s-button></s-stack></s-stack>')
      + '</s-section>').join('');
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

  el('blocks').addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy]');
    if (button) copy(blocks[Number(button.dataset.copy)].text);
  });
  el('ack').addEventListener('click', () => run(() => api('POST', 'adverts/acknowledge')).then(() => toast('Acknowledged')));
  el('ack-first').addEventListener('click', () => run(() => api('POST', 'adverts/acknowledge')).then(() => toast('Saved')));

  run(() => api('GET', 'adverts'));
})();
</script>
@endpush
