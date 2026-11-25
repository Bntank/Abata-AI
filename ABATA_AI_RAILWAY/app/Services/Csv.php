<?php
namespace App\Services;
class Csv {
 public static function read(string $path): array { $f=fopen($path,'r'); if(!$f)throw new \RuntimeException('CSV unavailable'); try{$head=fgetcsv($f,0,',','"','');$rows=[];while(($r=fgetcsv($f,0,',','"',''))!==false){if(count($r)!==count($head))throw new \RuntimeException('Invalid CSV row');$rows[]=array_combine($head,$r);}return $rows;}finally{fclose($f);} }
 public static function write(string $path,array $header,iterable $rows): void {$f=fopen($path,'w');if(!$f)throw new \RuntimeException('Cannot write CSV');try{fputcsv($f,$header,',','"','');foreach($rows as $r)fputcsv($f,array_map(fn($k)=>$r[$k],$header),',','"','');}finally{fclose($f);}}
}
