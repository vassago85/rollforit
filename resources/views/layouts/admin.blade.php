<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    <title>{{ $title ?? 'Roll4It' }}</title>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-1.js"></script>
    <style>
        .rfi-preview { border: 1px solid #e3e3e3; border-radius: 8px; padding: 16px; max-height: 480px; overflow: auto; background: #fff; }
        .rfi-copy { white-space: pre-wrap; font-family: inherit; margin: 0; }
        .rfi-meter { height: 8px; background: #eee; border-radius: 4px; overflow: hidden; }
        .rfi-meter > span { display: block; height: 100%; background: #008060; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <s-app-nav>
        <s-link href="/" rel="home">Settings</s-link>
        <s-link href="/terms">Terms</s-link>
        <s-link href="/advert-copy">Advert copy</s-link>
        <s-link href="/reporting">Reporting</s-link>
        <s-link href="/simulator">Test lab</s-link>
    </s-app-nav>

    @yield('content')

    <script src="{{ asset('admin/rfi-admin.js') }}?v={{ filemtime(public_path('admin/rfi-admin.js')) }}"></script>
    @stack('scripts')
</body>
</html>
