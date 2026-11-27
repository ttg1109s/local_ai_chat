<?php
/**
 * Provider boundary adapter contract.
 * The active transport currently targets OpenAI-compatible chat completions and
 * normalizes choices[0].delta/message.content before the Unified Agent protocol
 * controller sees the response. Provider-native tool calls are never execution
 * authority. Add provider-specific request/response adapters here when a provider
 * requires a different HTTP request envelope; do not add provider branches to the
 * Agent state machine.
 */
function lac_agent_adapter_normalize_content(mixed $content): string {
 if(is_string($content))return $content;
 if(!is_array($content))return '';
 $parts=[];
 foreach($content as $part){
  if(is_string($part))$parts[]=$part;
  elseif(is_array($part)&&is_string($part['text']??null))$parts[]=$part['text'];
 }
 return implode('',$parts);
}
