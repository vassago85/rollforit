<!doctype html>
<html>
<body style="font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #f8fafc; padding: 24px;">
<div style="max-width: 520px; margin: 0 auto; background: #fff; padding: 28px; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);">
    <h1 style="margin: 0 0 8px; font-size: 20px;">A customer just won on Roll4It</h1>
    <p style="margin: 0 0 20px; color: #475569;">{{ $shop->name }}</p>

    <table style="width: 100%; border-collapse: collapse; margin: 0 0 20px;">
        <tr>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; color: #475569;">Prize won</td>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; text-align: right; font-weight: 700;">{{ $payout }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; color: #475569;">Discount code</td>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $roll->discount_code ?? '—' }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; color: #475569;">Cart value at roll</td>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $cart }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; color: #475569;">Channel</td>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $roll->channel === 'pos' ? 'Point of sale' : 'Online storefront' }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0; color: #475569;">Rolled</td>
            <td style="padding: 10px 0; text-align: right;">{{ optional($roll->created_at)->setTimezone($shop->timezone)->format('H:i, D j M') }}</td>
        </tr>
    </table>

    <p style="margin: 0; color: #475569; font-size: 13px;">
        The code is single-use, expires at midnight in {{ $shop->timezone }}, and can only be applied to a qualifying cart. You'll see the final result in your Roll4It reporting once it's used.
    </p>
</div>
</body>
</html>
