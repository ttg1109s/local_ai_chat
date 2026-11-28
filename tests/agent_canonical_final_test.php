<?php
require_once __DIR__.'/../app/agent/model_transport.php';
require_once __DIR__.'/../app/agent/loop/protocol.php';
$events=[];$emit=static function(string $event,array $data)use(&$events):void{if($event==='delta')$events[]=(string)($data['text']??'');};
$offset=null;$mode=null;$escape='';$escapeNeed=0;$utf8Pending='';$streamed=false;$wire='';
foreach(['{"type":"final","content":"Hello ','\n','world','!"}'] as $chunk){$wire.=$chunk;lac_agent_stream_final_delta($wire,$offset,$mode,$escape,$escapeNeed,$utf8Pending,$streamed,$emit);}
if(implode('',$events)!=="Hello \nworld!"||!$streamed||$mode!=='json')throw new RuntimeException('Canonical final message was not streamed incrementally.');
$events=[];$offset=null;$mode=null;$escape='';$escapeNeed=0;$utf8Pending='';$streamed=false;$wire='';
foreach(["UTF-8 test: \xC3", "\xA9"] as $chunk){$wire.=$chunk;lac_agent_stream_final_delta($wire,$offset,$mode,$escape,$escapeNeed,$utf8Pending,$streamed,$emit);}
if(implode('',$events)!=='UTF-8 test: é'||$utf8Pending!=='')throw new RuntimeException('UTF-8 code points split across model deltas were corrupted.');
$decoded=lac_agent_loop_decode_protocol('{"type":"final","content":"JSON final"}');
if(($decoded['type']??'')!=='final'||($decoded['content']??'')!=='JSON final')throw new RuntimeException('Canonical final message decode failed.');
$decoded=lac_agent_loop_decode_protocol('Plain Markdown answer');
if(($decoded['type']??'')!=='final'||($decoded['content']??'')!=='Plain Markdown answer')throw new RuntimeException('Ordinary text must be a direct final answer.');
$decoded=lac_agent_loop_decode_protocol('{"type":"final_stream","content":"obsolete"}');
if(($decoded['type']??'')!=='final_stream')throw new RuntimeException('Removed protocol types must remain unrecognized, not be silently normalized.');
echo "PASS: canonical final protocol, incremental streaming, and UTF-8 boundaries\n";
