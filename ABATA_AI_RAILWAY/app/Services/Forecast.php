<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
class Forecast {
 public function __construct(private Inventory $inv){}
 private function call(array $args): array {$p=new Process([config('abata.python'),base_path('python/abata_ml.py'),...$args],base_path(),null,null,90);$p->mustRun();$r=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);if(($r['status']??null)!=='experimental_not_approved'||($r['scope']??null)!=='synthetic_seed_v2')throw new \RuntimeException('Invalid ML response');return $r;}
 public function generate():int {
  $dir=storage_path('app/private/ml-'.bin2hex(random_bytes(12)));if(!mkdir($dir,0700,true))throw new \RuntimeException('Cannot create work dir');
  try{
   $s=$this->inv->locked(function($s)use($dir){if($s->started)Inventory::fail('Prediksi pengadaan dibuat sebelum aktivitas stok/POS pada hari target. Tutup hari untuk prediksi berikutnya.');
    $history=DB::table('daily_history as h')->join('products as p','p.id','=','h.product_id')->join('calendar_days as c','c.date','=','h.date')->where('h.date','<=',$s->closed_through)->orderBy('p.name')->orderBy('h.date')->select('h.date','p.name as product_name','p.unit','h.quantity','h.demand_quantity','c.is_holiday','c.is_promotion','c.is_ramadan','c.is_eid_period')->get()->map(fn($r)=>(array)$r+['data_source'=>'synthetic_seed_v2']);
    Csv::write($dir.'/history.csv',['date','product_name','unit','data_source','quantity','demand_quantity','is_holiday','is_promotion','is_ramadan','is_eid_period'],$history);
    $c=(array)DB::table('calendar_days')->where('date',$s->open_date)->first();$c['data_source']='synthetic_seed_v2';Csv::write($dir.'/calendar.csv',['date','is_holiday','is_promotion','is_ramadan','is_eid_period','data_source'],[$c]);
    $masters=collect(Csv::read(base_path('datasets/product_master.csv')))->keyBy('product_name');$dbProducts=DB::table('products')->get();if($dbProducts->count()!==$masters->count())throw new \RuntimeException('Product count differs from model master');
    $stocks=[];foreach($dbProducts as $p){$m=$masters->get($p->name);if(!$m||$m['unit']!==$p->unit)throw new \RuntimeException('Product contract mismatch');foreach(['purchase_cost','underage_cost','overage_cost','lead_time','order_multiple','shelf_life_days'] as $field)if(abs((float)$m[$field]-(float)$p->$field)>1e-7)throw new \RuntimeException('Product metadata differs from model master');$inbound=$p->is_production?0:DB::table('orders')->where('product_id',$p->id)->whereIn('status',['approved','partially_received'])->where('expected_date',$s->open_date)->selectRaw('COALESCE(SUM(qty-received),0) AS total')->value('total');$stocks[]=['product_name'=>$p->name,'unit'=>$p->unit,'as_of_date'=>$s->closed_through,'usable_on_hand'=>$p->is_production?0:$this->inv->available($p->id,$s->open_date),'eligible_inbound'=>$inbound,'reserved'=>0,'data_source'=>'synthetic_seed_v2'];}
    Csv::write($dir.'/stocks.csv',['product_name','unit','as_of_date','usable_on_hand','eligible_inbound','reserved','data_source'],$stocks);return $s;
   });
   $master=base_path('datasets/product_master.csv');$model=base_path('ml_models');$manifest=json_decode(file_get_contents($model.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$registry=DB::table('model_registries')->where('run_id',$manifest['run_id'])->first();if(!$registry||$registry->manifest_sha256!==hash_file('sha256',$model.'/manifest.json')||$registry->status!=='experimental_not_approved')throw new \RuntimeException('Model registry mismatch');if(hash_file('sha256',base_path('python/abata_ml.py'))!==$manifest['pipeline_sha256'])throw new \RuntimeException('ML pipeline checksum mismatch');
   $fc=$this->call(['predict','--history',$dir.'/history.csv','--master',$master,'--model-dir',$model,'--forecast-date',$s->open_date,'--calendar',$dir.'/calendar.csv','--out',$dir.'/fc.json']);$rec=$this->call(['recommend','--forecast',$dir.'/fc.json','--master',$master,'--stocks',$dir.'/stocks.csv']);
   if($fc['run_id']!==$manifest['run_id']||$rec['run_id']!==$fc['run_id']||$fc['forecast_date']!==$s->open_date||$rec['forecast_date']!==$s->open_date||count($fc['predictions'])!==8||count($rec['recommendations'])!==16)throw new \RuntimeException('ML contract mismatch');
   return $this->inv->locked(function($current)use($s,$fc,$rec){if($current->revision!=$s->revision||$current->open_date!==$s->open_date||$current->started)Inventory::fail('Data berubah selama inferensi; ulangi');return DB::table('forecast_runs')->insertGetId(['as_of'=>$s->closed_through,'target_date'=>$s->open_date,'revision'=>$s->revision,'run_id'=>$fc['run_id'],'status'=>$fc['status'],'forecast_json'=>json_encode($fc,JSON_THROW_ON_ERROR),'recommendation_json'=>json_encode($rec,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);});
  }finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
 }
}
