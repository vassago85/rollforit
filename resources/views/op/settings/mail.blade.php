@extends('layouts.op')

@section('title', 'Mail settings · Roll4It operator')

@section('content')
  <a class="back" href="{{ route('op.dashboard') }}">← Dashboard</a>
  <h1>Mail (Mailgun)</h1>
  <p class="subtitle">
    These values override whatever is in <code>.env</code> at runtime. Leave a field blank to fall back to the <code>.env</code> value shown next to it.
  </p>

  @if (session('status'))
    <div class="pill on" style="padding: 10px 14px; display: block; margin: 0 0 16px;">{{ session('status') }}</div>
  @endif
  @if (session('error'))
    <div class="err">{{ session('error') }}</div>
  @endif

  <section class="cards" style="grid-template-columns: repeat(3, 1fr);">
    <article class="card">
      <p class="num" style="font-size: 1.2rem;">{{ $summary['mailer'] ?: '—' }}</p>
      <p class="label">Active mailer</p>
    </article>
    <article class="card">
      <p class="num" style="font-size: 1.2rem;">{{ $summary['from_address'] ?: '—' }}</p>
      <p class="label">From address ({{ $summary['from_name'] ?: 'no display name' }})</p>
    </article>
    <article class="card">
      <p class="num" style="font-size: 1.2rem;">{{ $summary['mailgun_domain'] ?: '—' }}</p>
      <p class="label">Mailgun domain {!! $summary['mailgun_secret_set'] ? '<span class="pill on">key set</span>' : '<span class="pill danger">no key</span>' !!}</p>
    </article>
  </section>

  <section class="panel" style="padding: 24px;">
    <form method="post" action="{{ route('op.settings.mail.update') }}">
      @csrf

      <div class="grid-2">
        <div>
          <label for="mail_mailer">Mailer</label>
          <select name="mail_mailer" id="mail_mailer" style="width:100%;background:var(--panel-2);border:1px solid var(--line);color:var(--text);border-radius:10px;padding:10px 14px;font:inherit;margin:0 0 16px;">
            @foreach (['', 'log', 'array', 'smtp', 'mailgun', 'ses'] as $option)
              <option value="{{ $option }}" @selected(in_array('mail.mailer', $summary['overridden_in_db'], true) && $summary['mailer'] === $option)>
                {{ $option === '' ? '— use .env ('.($env_fallbacks['mail.mailer'] ?: 'log').') —' : $option }}
              </option>
            @endforeach
          </select>

          <label for="mail_from_address">From address</label>
          <input type="email" name="mail_from_address" id="mail_from_address" autocomplete="off"
            value="{{ in_array('mail.from.address', $summary['overridden_in_db'], true) ? $summary['from_address'] : '' }}"
            placeholder="{{ $env_fallbacks['mail.from.address'] ?: 'noreply@yourdomain.com' }}" />

          <label for="mail_from_name">From display name</label>
          <input type="text" name="mail_from_name" id="mail_from_name" autocomplete="off"
            value="{{ in_array('mail.from.name', $summary['overridden_in_db'], true) ? $summary['from_name'] : '' }}"
            placeholder="{{ $env_fallbacks['mail.from.name'] ?: 'Roll4It' }}" />
          <p class="label" style="margin-top:-10px;margin-bottom:16px;">
            Each shopper's confirmation email replaces this name with the shop they're buying from. This is the fallback for app emails.
          </p>
        </div>

        <div>
          <label for="mailgun_domain">Mailgun domain</label>
          <input type="text" name="mailgun_domain" id="mailgun_domain" autocomplete="off"
            value="{{ in_array('mail.mailgun.domain', $summary['overridden_in_db'], true) ? $summary['mailgun_domain'] : '' }}"
            placeholder="{{ $env_fallbacks['mail.mailgun.domain'] ?: 'mg.yourdomain.com' }}" />

          <label for="mailgun_endpoint">Mailgun endpoint</label>
          <input type="text" name="mailgun_endpoint" id="mailgun_endpoint" autocomplete="off"
            value="{{ in_array('mail.mailgun.endpoint', $summary['overridden_in_db'], true) ? $summary['mailgun_endpoint'] : '' }}"
            placeholder="{{ $env_fallbacks['mail.mailgun.endpoint'] ?: 'api.mailgun.net' }}" />
          <p class="label" style="margin-top:-10px;margin-bottom:16px;">
            Use <code>api.eu.mailgun.net</code> for EU-hosted Mailgun accounts.
          </p>

          <label for="mailgun_secret">
            Mailgun API key
            @if ($summary['mailgun_secret_set'])
              <span class="pill on" style="margin-left:6px;">current: {{ $summary['mailgun_secret_masked'] }}</span>
            @endif
          </label>
          <input type="password" name="mailgun_secret" id="mailgun_secret" autocomplete="new-password"
            placeholder="{{ $summary['mailgun_secret_set'] ? 'Leave blank to keep current key' : 'key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' }}" />
          <div class="row-check">
            <input type="checkbox" name="clear_secret" id="clear_secret" value="1" />
            <label for="clear_secret" style="margin:0;">Clear the saved key and fall back to <code>.env</code></label>
          </div>
        </div>
      </div>

      <button class="btn" type="submit" style="width:auto;padding:12px 32px;">Save mail settings</button>
    </form>
  </section>

  <section class="panel" style="padding: 24px; margin-top: 24px;">
    <h2 style="margin:0 0 12px;font-size:1rem;">Send a test email</h2>
    <p class="label" style="margin:0 0 16px;">
      Fires a real <code>StorefrontVerifyCode</code> email through whichever mailer is currently active ({{ $summary['mailer'] }}),
      using the From/Reply-To settings above. Use this to confirm your Mailgun creds work end-to-end.
    </p>
    <form method="post" action="{{ route('op.settings.mail.test') }}" style="display:flex;gap:12px;align-items:flex-end;max-width:520px;">
      @csrf
      <div style="flex:1;">
        <label for="to">Send to</label>
        <input type="email" name="to" id="to" required autocomplete="email" placeholder="you@example.com" style="margin:0;" />
      </div>
      <button class="btn" type="submit" style="width:auto;white-space:nowrap;">Send test</button>
    </form>
  </section>

  <section class="panel" style="padding: 24px; margin-top: 24px;">
    <h2 style="margin:0 0 12px;font-size:1rem;">What's in <code>.env</code> as a fallback</h2>
    <dl class="kv" style="padding:0;">
      <dt>MAIL_MAILER</dt><dd class="mono">{{ $env_fallbacks['mail.mailer'] ?: '— not set —' }}</dd>
      <dt>MAIL_FROM_ADDRESS</dt><dd class="mono">{{ $env_fallbacks['mail.from.address'] ?: '— not set —' }}</dd>
      <dt>MAIL_FROM_NAME</dt><dd class="mono">{{ $env_fallbacks['mail.from.name'] ?: '— not set —' }}</dd>
      <dt>MAILGUN_DOMAIN</dt><dd class="mono">{{ $env_fallbacks['mail.mailgun.domain'] ?: '— not set —' }}</dd>
      <dt>MAILGUN_ENDPOINT</dt><dd class="mono">{{ $env_fallbacks['mail.mailgun.endpoint'] ?: 'api.mailgun.net' }}</dd>
      <dt>MAILGUN_SECRET</dt><dd class="mono">{{ $env_fallbacks['mail.mailgun.secret_set'] ? '•••• set' : '— not set —' }}</dd>
    </dl>
  </section>
@endsection
