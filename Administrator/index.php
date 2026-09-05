<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

// Page keys are now plain strings, validated and access-checked centrally
// by Router::dispatch() -- see app/Core/Router.php for why the previous
// symmetric-encryption wrapper around this value was removed. An
// unrecognized value (including garbage left over from an old
// ?page=<ciphertext> bookmark) safely falls back to the Dashboard, matching
// the previous app's default: behavior.
Router::dispatch((string) ($_GET['page'] ?? ''));
