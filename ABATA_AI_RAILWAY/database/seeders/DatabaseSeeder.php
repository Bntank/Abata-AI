<?php
namespace Database\Seeders;
use App\Services\Csv;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
class DatabaseSeeder extends Seeder {
 public function run(): void {
  if(DB::table('demo_state')->exists()){ $this->command?->info('Demo already initialized; no changes.');return; }
  if(DB::table('products')->exists()||DB::table('users')->exists())throw new \RuntimeException('Use a new empty database for reference demo');
  $dir=base_path('datasets');$manifest=json_decode(file_get_contents(base_path('ml_models/manifest.json')),true,512,JSON_THROW_ON_ERROR);
  foreach($manifest['seed_validation']['sha256'] as $file=>$hash)if(hash_file('sha256',$dir.'/'.$file)!==$hash)throw new \RuntimeException('Seed checksum mismatch: '.$file);
  foreach(config('abata.passwords') as $r=>$p)if(!is_string($p)||strlen($p)<12)throw new \RuntimeException('Set each ABATA_*_PASSWORD (minimum 12 characters)');
  DB::transaction(function()use($dir,$manifest){
   foreach(config('abata.passwords') as $r=>$p)DB::table('users')->insert(['name'=>ucfirst($r),'email'=>$r.'@abata.local','role'=>$r,'password'=>Hash::make($p),'created_at'=>now(),'updated_at'=>now()]);
   $ps=[];$ms=[];foreach(Csv::read($dir.'/product_master.csv') as $r){$ps[$r['product_name']]=DB::table('products')->insertGetId(['name'=>$r['product_name'],'label'=>$r['product_name'],'unit'=>$r['unit'],'purchase_cost'=>$r['purchase_cost'],'underage_cost'=>$r['underage_cost'],'overage_cost'=>$r['overage_cost'],'lead_time'=>$r['lead_time'],'order_multiple'=>$r['order_multiple'],'shelf_life_days'=>$r['shelf_life_days'],'is_production'=>$r['product_name']==='Nasi Porsi']);}
   foreach(Csv::read($dir.'/recipes_bom.csv') as $r){$id=$ms[$r['menu_id']]??=DB::table('menus')->insertGetId(['code'=>$r['menu_id'],'name'=>$r['menu_name'],'active'=>true]);DB::table('bom')->insert(['menu_id'=>$id,'product_id'=>$ps[$r['product_name']],'qty'=>$r['quantity_per_menu']]);}
   foreach(Csv::read($dir.'/calendar.csv') as $r)DB::table('calendar_days')->insert(['date'=>$r['date'],'is_holiday'=>$r['is_holiday'],'is_promotion'=>$r['is_promotion'],'is_ramadan'=>$r['is_ramadan'],'is_eid_period'=>$r['is_eid_period'],'source'=>'seed']);
   $bom=DB::table('bom')->get()->groupBy('menu_id');
   foreach(Csv::read($dir.'/menu_sales.csv') as $r){$mid=$ms[$r['menu_id']];$id=DB::table('sales')->insertGetId(['menu_id'=>$mid,'date'=>$r['date'],'requested'=>$r['requested_qty'],'sold'=>$r['sold_qty'],'lost'=>$r['lost_qty'],'event_key'=>'seed-'.$r['date'].'-'.$mid,'source'=>'seed']);foreach($bom[$mid] as $b)DB::table('sale_items')->insert(['sale_id'=>$id,'product_id'=>$b->product_id,'usage_qty'=>$r['sold_qty']*$b->qty,'demand_qty'=>$r['requested_qty']*$b->qty]);}
   $last=$manifest['history_end'];$first=$manifest['history_start'];
   foreach(Csv::read($dir.'/dataset_abata_v2.csv') as $r){$pid=$ps[$r['product_name']];$data=['product_id'=>$pid,'date'=>$r['date'],'source'=>'seed'];foreach(['quantity','demand_quantity','opening_stock','incoming_stock','waste_quantity','closing_stock'] as $c)$data[$c]=$r[$c];DB::table('daily_history')->insert($data);
    $moves=['receipt'=>$r['incoming_stock'],'usage'=>-$r['quantity'],'waste'=>-$r['waste_quantity']];if($r['date']===$first)$moves['opening']=$r['opening_stock'];foreach($moves as $k=>$qty)if(abs((float)$qty)>0.0001)DB::table('stock_movements')->insert(['product_id'=>$pid,'date'=>$r['date'],'kind'=>$k,'qty'=>$qty,'event_key'=>'seed-'.$pid.'-'.$r['date'].'-'.$k,'note'=>'Synthetic historical snapshot']);
    if($r['date']===$last && (float)$r['closing_stock']>0){$p=DB::table('products')->find($pid);DB::table('stock_lots')->insert(['product_id'=>$pid,'received_date'=>$last,'expires_date'=>date('Y-m-d',strtotime($last.' +'.($p->shelf_life_days-1).' days')),'remaining'=>$r['closing_stock'],'source'=>'seed_age_assumption']);}
   }
   $mf=base_path('ml_models/manifest.json');DB::table('model_registries')->insert(['run_id'=>$manifest['run_id'],'status'=>$manifest['status'],'scope'=>$manifest['scope'],'manifest_sha256'=>hash_file('sha256',$mf),'artifact_dir'=>'ml_models','manifest_json'=>file_get_contents($mf),'created_at'=>now(),'updated_at'=>now()]);
   $next=date('Y-m-d',strtotime($last.' +1 day'));DB::table('demo_state')->insert(['id'=>1,'closed_through'=>$last,'open_date'=>$next,'scope'=>'synthetic_seed_v2','seed_hash'=>$manifest['history_sha256']]);DB::table('calendar_days')->insert(['date'=>$next,'is_holiday'=>0,'is_promotion'=>0,'is_ramadan'=>0,'is_eid_period'=>0,'source'=>'demo_scenario']);
  });
 }
}
