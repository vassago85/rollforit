<?php

// Local harness for the storefront widget: php -S 127.0.0.1:8787 scripts/widget-harness/router.php
// Mocks the Shopify cart API and the app proxy. ?outcome=win|loss on the page URL forces the result;
// ?opens_in=SECONDS shows the countdown until then.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$assets = __DIR__.'/../../extensions/roll-for-it/assets';
session_start();

$json = function ($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);

    return true;
};

if (str_starts_with($path, '/assets/')) {
    $file = realpath($assets.'/'.basename($path));
    if (! $file) {
        http_response_code(404);

        return true;
    }
    header('Content-Type: '.(str_ends_with($file, '.css') ? 'text/css' : 'application/javascript'));
    readfile($file);

    return true;
}

if ($path === '/cart.js') {
    return $json([
        'token' => 'harness-cart',
        'currency' => 'ZAR',
        'items' => [
            ['variant_id' => 1, 'quantity' => 1, 'original_line_price' => (int) ($_SESSION['cart'] ?? 65000), 'gift_card' => false],
        ],
        'discount_codes' => [],
    ]);
}

if ($path === '/cart/update.js') {
    $body = json_decode(file_get_contents('php://input'), true);

    return $json(['discount_codes' => [['code' => $body['discount'] ?? '', 'applicable' => true]]]);
}

if ($path === '/apps/roll-for-it/status') {
    $opensIn = (int) ($_COOKIE['opens_at'] ?? 0) - time();
    $upcoming = $opensIn > 0;

    return $json([
        'open' => ! $upcoming, 'reason' => $upcoming ? 'upcoming' : null,
        'next_roll' => $upcoming
            ? ['starts_at' => date(DATE_ATOM, time() + $opensIn), 'starts_in' => $opensIn, 'label' => date($opensIn > 86400 ? 'l \a\t H:i' : '\t\o\d\a\y \a\t H:i', time() + $opensIn)]
            : ['starts_at' => date(DATE_ATOM, time() + 604800), 'starts_in' => 604800 - 3600, 'label' => 'next Friday at 08:00'],
        'test' => false, 'currency' => 'ZAR', 'min_order' => 50000,
        'banner' => 'Spend R500, win up to R1,000 off. T&Cs apply.', 'headline' => 'Roll for it!',
        'odds' => 'One in every 10 entries wins.',
        'disclaimer' => 'Your number is just for fun. It does not change your odds: the result is decided when you roll.',
        'spend_to_roll' => 'Spend R500 to roll', 'one_roll' => 'One roll per customer per day.',
        'sold_out_message' => 'Sold out', 'terms_url' => '#terms',
        'min_order_formatted' => 'R500', 'max_payout_formatted' => 'R1,000',
        'animation' => ['die_color' => '#1f2937', 'number_color' => '#ffffff', 'accent_color' => '#f59e0b',
            'duration_ms' => 2500, 'sound' => false, 'celebration' => true],
        'drawer_selector' => null,
    ]);
}

if ($path === '/apps/roll-for-it/roll') {
    $body = json_decode(file_get_contents('php://input'), true);
    usleep(400_000);
    $picked = (int) $body['picked_number'];
    $won = ($_COOKIE['outcome'] ?? 'win') === 'win';
    $shown = $picked;
    while (! $won && $shown === $picked) {
        $shown = random_int(1, 10);
    }

    return $json([
        'repeat' => false, 'test' => false, 'won' => $won, 'displayed_number' => $shown, 'picked_number' => $picked,
        'payout' => $won ? 65000 : 0, 'payout_formatted' => $won ? 'R650' : 'R0',
        'code' => $won ? 'ROLL-HARNESS1' : null, 'code_ready' => true, 'ends_at' => null,
        'message' => $won ? 'You won R650 off your order! Code ROLL-HARNESS1 has been applied to your cart.' : 'Not this time. Thanks for rolling!',
    ]);
}

if (isset($_GET['outcome'])) {
    setcookie('outcome', $_GET['outcome']);
}
if (isset($_GET['opens_in'])) {
    setcookie('opens_at', (string) (time() + (int) $_GET['opens_in']));
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Roll for It harness</title>
  <link rel="stylesheet" href="/assets/rfi-widget.css">
  <style>body { font-family: system-ui, sans-serif; max-width: 520px; margin: 40px auto; padding: 0 16px; background: #f3f4f6; }</style>
</head>
<body>
  <h1>Cart</h1>
  <p>One item, R650.</p>
  <div class="rfi-root" data-rfi data-placement="harness" data-proxy="/apps/roll-for-it" data-currency="ZAR" data-die3d="/assets/rfi-die3d.js" hidden></div>
  <script src="/assets/rfi-widget.js" defer></script>
</body>
</html>
