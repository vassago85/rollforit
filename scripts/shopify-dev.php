<?php

// `shopify app dev` passes the port in $PORT; this keeps the command cross-platform.
$port = getenv('PORT') ?: '8000';

passthru(sprintf('php %s serve --host=127.0.0.1 --port=%d', escapeshellarg(__DIR__.'/../artisan'), (int) $port), $code);

exit($code);
