<?php

/*
 * The Test lab glue is JavaScript, not PHP, so Pest can't exercise it against
 * a browser without pulling in Playwright. The next-best regression guard is
 * to assert that the files still contain the key behaviours that caused real
 * merchant-visible bugs (dice_count=2 stuck on 1-die, caps/widget disagreement
 * invisible to the merchant). If any of these strings disappear, something has
 * been regressed and the test fails before the merchant notices.
 */
it('ships the lab-frame glue with the dice-safe remount and rendered-callback behaviour', function () {
    $glue = file_get_contents(public_path('admin/lab-frame.js'));

    expect($glue)->not->toBeFalse();

    // Fetch intercept must await the ready promise so the widget cannot boot
    // against stale defaults when the config message is a hair late.
    expect($glue)->toContain('ready.then');

    // On every config change after the first we drop the entire iframe scope
    // via location.reload(). The widget caches its status response in a
    // MODULE-SCOPED promise, so a mere host-swap or init(true) call still
    // serves the first config's status (this was literally the dice_count=2
    // stuck-on-1 bug). The reload-on-change rule is the only reliable fix.
    expect($glue)->toContain('location.reload()');

    // After each render the iframe reports what the widget actually drew so the
    // parent can show the merchant a visible "widget disagrees with settings"
    // warning if the two ever fall out of sync.
    expect($glue)->toContain("type: 'rendered'")
        ->and($glue)->toContain('pick_min')
        ->and($glue)->toContain('pick_max')
        ->and($glue)->toContain('dice_count')
        ->and($glue)->toContain('game');
});

it('overlays saved game settings onto the widget status so the Test lab matches Settings', function () {
    $view = file_get_contents(resource_path('views/admin/simulator.blade.php'));

    expect($view)->not->toBeFalse();

    // frameConfig() must override status.game / dice_count / pick_range with the
    // values just fetched from the simulator endpoint. Without this overlay a
    // double-or-nothing shop (or a dice_count=2 shop) would still boot the
    // widget with a stale board when the cached status predates the change.
    expect($view)->toContain("game: s.game || 'dice'")
        ->and($view)->toContain('dice_count: s.dice_count')
        ->and($view)->toContain('pick_range: s.pick_range')
        ->and($view)->toContain('base_percent: s.base_percent')
        ->and($view)->toContain('double_percent: s.double_percent');

    // The "What the widget sees" diagnostic card lets the merchant spot a
    // caps-vs-widget mismatch at a glance. Its three readouts and the
    // rendered-message handler must stay wired up.
    expect($view)->toContain('What the widget sees')
        ->and($view)->toContain('lab-seen-game')
        ->and($view)->toContain('lab-seen-range')
        ->and($view)->toContain("msg.type === 'rendered'")
        ->and($view)->toContain('renderSeen(');
});
