<?php
// Backward-compatible entry point. Agent orchestration is split by responsibility.
// loop/bootstrap.php loads completion_validator.php, model_transport.php and runtime dependencies.
require_once __DIR__.'/loop/bootstrap.php';
require_once __DIR__.'/loop/state.php';
require_once __DIR__.'/loop/runner.php';
