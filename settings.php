<?php require __DIR__.'/app/bootstrap.php';
function val(PDO $p,string $k,string $d):string{return htmlspecialchars(setting($p,$k,$d),ENT_QUOTES,'UTF-8');}
$webEnabled=setting($pdo,'websites_enabled','0')==='1';
$websites=json_decode(setting($pdo,'websites_config','[]'),true);if(!is_array($websites))$websites=[];if(!$websites)$websites=[['id'=>'timeanddate-weather','name'=>'Timeanddate - Weather','url'=>'https://www.timeanddate.com/weather/vietnam/','search_url'=>'','enabled'=>true]];
require __DIR__.'/app/views/settings.php';
