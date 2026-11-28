<?php
/** Capability handoff relationships between tools. */
return [
  'python_generate_number'=>['can_feed'=>['python_statistics','python_dataframe','python_plot','verify_result']],
  'python_dataframe'=>['can_feed'=>['python_statistics','python_plot','verify_result']],
  'python_statistics'=>['can_feed'=>['python_plot','verify_result']],
  'manage_websites'=>['can_feed'=>[]],
];
