<?php
/** System information and model discovery tool definitions. */
return [
  'get_server_info'=>['name'=>'get_server_info','label'=>'Server Information','group'=>'System','description'=>'Read PHP version, operating system, server, and memory limits.','risk'=>'read','timeout'=>5,'retryable'=>true,'arguments'=>[],'schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false]],
  'list_models'=>['name'=>'list_models','label'=>'List Models','group'=>'System','description'=>'List models from an OpenAI-compatible endpoint.','risk'=>'read','timeout'=>10,'retryable'=>true,'arguments'=>[],'schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false]],
];
