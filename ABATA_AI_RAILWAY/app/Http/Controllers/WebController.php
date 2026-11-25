<?php
namespace App\Http\Controllers;
use App\Services\{Inventory,Forecast,Csv};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class WebController {
 public function __construct(private Inventory $inv){}
 public function home(Request $r){return redirect()->route($r->user()->role==='cashier'?'pos':'dashboard');}
 public function page(Request $r,string $page){
  $state=$this->inv->state();$products=DB::table('products')->orderBy('id')->get();foreach($products as $p)$p->usable=$this->inv->available($p->id,$state->open_date);
  $today=DB::table('sales')->where('date',$state->open_date)->selectRaw('COUNT(*) as transactions, COALESCE(SUM(requested),0) as requested, COALESCE(SUM(sold),0) as sold, COALESCE(SUM(lost),0) as lost')->first();
  $data=['today'=>$today,'page'=>$page,'state'=>$state,'products'=>$products,'menus'=>DB::table('menus')->orderBy('id')->get()];
  if(in_array($page,['dashboard','forecast','recommendations'],true)){
   $run=DB::table('forecast_runs')->latest('id')->first();$data+=['run'=>$run,'fresh'=>$run&&$run->revision==$state->revision&&$run->as_of===$state->closed_through&&!$state->started,'predictions'=>$run?json_decode($run->forecast_json,true)['predictions']:[],'recs'=>$run?json_decode($run->recommendation_json,true)['recommendations']:[],'calendar'=>DB::table('calendar_days')->where('date',$state->open_date)->first()];
   $data['recent']=DB::table('daily_history')->join('products','products.id','=','daily_history.product_id')->where('daily_history.date','>=',date('Y-m-d',strtotime($state->closed_through.' -13 days')))->orderBy('daily_history.date')->select('daily_history.*','products.name','products.unit')->get()->groupBy('name');
  }
  if(in_array($page,['dashboard','recommendations','orders','production','report'],true)){
   $data['activeOrders']=DB::table('orders')->join('products','products.id','=','orders.product_id')->whereIn('orders.status',['draft','approved','partially_received'])->orderBy('expected_date')->select('orders.*','products.name','products.unit')->get();
  }
  if(in_array($page,['dashboard','recommendations'],true)){
   $policy=$r->query('policy','fixed_q80');$data['policy']=is_string($policy)&&in_array($policy,['fixed_q80','conservative','cost_based','empirical28'],true)?$policy:'fixed_q80';
  }
  if($page==='report'){
   $data['lastClosed']=DB::table('daily_history as h')->join('products as p','p.id','=','h.product_id')->where('h.date',$state->closed_through)->select('h.*','p.label','p.unit')->get();
   $data['todayMovements']=DB::table('stock_movements')->where('date',$state->open_date)->selectRaw('product_id, kind, SUM(qty) as total')->groupBy('product_id','kind')->get()->groupBy('product_id');
  }
  if($page==='pos'){
   $available=$products->keyBy('id');$recipe=DB::table('bom')->get()->groupBy('menu_id');
   foreach($data['menus'] as $m){$rows=$recipe->get($m->id,collect());$m->capacity=$rows->isEmpty()?0:$rows->min(fn($b)=>(int)floor(($available[$b->product_id]->usable+1e-8)/(float)$b->qty));}
  }
  if($page==='pos')$data['sales']=DB::table('sales')->join('menus','menus.id','=','sales.menu_id')->where('sales.date',$state->open_date)->orderByDesc('sales.id')->select('sales.*','menus.name')->limit(100)->get();
  if($page==='inventory'){$data['lots']=DB::table('stock_lots')->join('products','products.id','=','stock_lots.product_id')->where('remaining','>',0)->orderBy('expires_date')->select('stock_lots.*','products.name','products.unit')->get();$data['movements']=DB::table('stock_movements')->join('products','products.id','=','stock_movements.product_id')->orderByDesc('stock_movements.date')->orderByDesc('stock_movements.id')->select('stock_movements.*','products.name','products.unit')->limit(100)->get();}
  if($page==='orders'||$page==='production')$data['orders']=DB::table('orders')->join('products','products.id','=','orders.product_id')->where('type',$page==='production'?'production':'purchase')->orderByDesc('orders.id')->select('orders.*','products.name','products.unit')->get();
  if($page==='menus')$data['bom']=DB::table('bom')->join('products','products.id','=','bom.product_id')->select('bom.*','products.name','products.unit')->get()->groupBy('menu_id');
  if($page==='performance'){$data['evaluation']=array_values(array_filter(Csv::read(base_path('ml_models/evaluation.csv')),fn($r)=>in_array($r['variant'],['final','lag7','empirical28'],true)));$data['manifest']=json_decode(file_get_contents(base_path('ml_models/manifest.json')),true);}
  if($page==='backtesting')$data['backtest']=Csv::read(base_path('ml_models/backtest_summary.csv'));
  return view('page',$data);
 }
 public function sale(Request $r){$v=$r->validate(['menu_id'=>'required|integer','requested'=>'required|integer|min:1|max:10000','event_key'=>'required|uuid']);$x=$this->inv->sale($v['menu_id'],$v['requested'],$v['event_key'],$r->user()->id);return back()->with('ok',($x['duplicate']?'Transaksi sudah tercatat. ':'Tersimpan. ').'Terjual '.$x['sold'].' porsi; tidak terpenuhi '.$x['lost'].' porsi.');}
 public function waste(Request $r){$v=$r->validate(['product_id'=>'required|integer','qty'=>'required|numeric|gt:0|max:100000|decimal:0,3','event_key'=>'required|uuid']);$this->inv->waste($v['product_id'],$v['qty'],$v['event_key'],$r->user()->id);return back()->with('ok','Bahan terbuang dicatat dan stok diperbarui.');}
 public function forecast(Request $r,Forecast $f){try{$f->generate();}catch(\Illuminate\Validation\ValidationException $e){throw $e;}catch(\Throwable $e){report($e);return back()->withErrors(['ml'=>'Prediksi belum berhasil. Hubungi administrator untuk memeriksa layanan prediksi. Hasil lama tidak diganti.']);}return redirect()->route($r->input('return_to')==='recommendations'?'recommendations':'forecast')->with('ok','Prediksi dan saran belanja telah diperbarui.');}
 public function calendar(Request $r){$v=$r->validate(array_fill_keys(['is_holiday','is_promotion','is_ramadan','is_eid_period'],'required|boolean'));$this->inv->calendar($v);return back()->with('ok','Pengaturan hari diperbarui. Tekan Perbarui prediksi untuk menghitung saran belanja.');}
 public function close(Request $r){$v=$r->validate(['date'=>'required|date_format:Y-m-d','confirmed'=>'accepted']);$this->inv->close($v['date'],$r->user()->id,true);return redirect()->route('recommendations')->with('ok','Hari ditutup. Laporan tersimpan. Perbarui prediksi untuk hari berikutnya.');}
 public function draft(Request $r){$v=$r->validate(['product_id'=>'required|integer','qty'=>'required|numeric|gt:0|max:100000|decimal:0,3','expected_date'=>'required|date_format:Y-m-d','event_key'=>'required|uuid','forecast_run_id'=>'nullable|integer|required_with:policy','policy'=>'nullable|required_with:forecast_run_id|in:fixed_q80,conservative,cost_based,empirical28']);$this->inv->draft($v['product_id'],$v['qty'],$v['expected_date'],$v['event_key'],$r->user()->id,$v['forecast_run_id']??null,$v['policy']??null);return redirect()->route($this->inv->product($v['product_id'])->is_production?'production':'orders')->with('ok','Draft disimpan. Persetujuan belum menambah stok.');}
 public function draftPlan(Request $r){
  $v=$r->validate(['forecast_run_id'=>'required|integer','policy'=>'required|in:fixed_q80,conservative,cost_based,empirical28','product_ids'=>'required|array|min:1|max:4','product_ids.*'=>'required|integer|distinct','confirmed'=>'accepted']);
  $count=$this->inv->locked(function($s)use($v,$r){
   $run=DB::table('forecast_runs')->find($v['forecast_run_id']);
   if(!$run||$s->started||$run->revision!=$s->revision||$run->as_of!==$s->closed_through||$run->target_date!==$s->open_date)Inventory::fail('Saran belanja perlu diperbarui sebelum membuat rencana.');
   $recs=collect(json_decode($run->recommendation_json,true,512,JSON_THROW_ON_ERROR)['recommendations']);
   $count=0;
   foreach($v['product_ids'] as $pid){$p=$this->inv->product((int)$pid);
    $rec=$recs->first(fn($x)=>$x['product_name']===$p->name&&$x['policy']===$v['policy']);
    if(!$rec||$rec['recommended_qty']<=0)Inventory::fail('Tidak ada saran pengadaan untuk salah satu bahan.');
    $active=DB::table('orders')->where('product_id',$pid)->where('expected_date',$s->open_date)->whereIn('status',$p->is_production?['draft','approved','partially_received']:['draft'])->exists();
    if($active)Inventory::fail('Rencana aktif sudah ada untuk '.$p->label.'. Periksa pesanan sebelum membuat ulang.');
    $this->inv->draft((int)$pid,(float)$rec['recommended_qty'],$s->open_date,(string)Str::uuid(),$r->user()->id,$run->id,$v['policy']);$count++;
   }return $count;
  });return redirect()->route('orders')->with('ok',$count.' rencana dibuat. Setujui semua rencana yang diperlukan sebelum menerima barang atau mencatat penjualan.');
 }
 public function transition(Request $r,int $id){$v=$r->validate(['action'=>'required|in:approve,cancel']);$this->inv->transition($id,$v['action'],$r->user()->id);return back()->with('ok','Status pesanan diperbarui.');}
 public function receipt(Request $r,int $id){$v=$r->validate(['qty'=>'required|numeric|gt:0|max:100000|decimal:0,3','event_key'=>'required|uuid']);$this->inv->receipt($id,$v['qty'],$v['event_key'],$r->user()->id);return back()->with('ok','Barang diterima dan stok diperbarui.');}
 public function label(Request $r,int $id){$v=$r->validate(['label'=>'required|string|max:120']);$this->inv->locked(fn()=>DB::table('products')->where('id',$id)->update($v));return back()->with('ok','Label diperbarui. Kontrak model tidak berubah.');}
 public function menu(Request $r){$v=$r->validate(['name'=>'required|string|max:120','qty'=>'required|array','qty.*'=>'nullable|numeric|min:0|max:100|decimal:0,3']);$this->inv->locked(function()use($v){$ps=DB::table('products')->pluck('id')->map(fn($x)=>(int)$x)->all();$bom=[];foreach($v['qty'] as $id=>$q){if(!in_array((int)$id,$ps,true))Inventory::fail('Unknown product');if((float)$q>0){$p=$this->inv->product((int)$id);if($p->is_production&&abs($q-round($q))>1e-7)Inventory::fail('Porsi nasi harus utuh');$bom[(int)$id]=$q;}}if(!$bom)Inventory::fail('BOM tidak boleh kosong');$id=DB::table('menus')->insertGetId(['code'=>'M-'.Str::uuid(),'name'=>$v['name'],'active'=>true]);foreach($bom as $p=>$q)DB::table('bom')->insert(['menu_id'=>$id,'product_id'=>$p,'qty'=>$q]);});return back()->with('ok','Versi menu baru dibuat.');}
 public function toggle(int $id){$this->inv->locked(function()use($id){$m=DB::table('menus')->find($id)??abort(404);DB::table('menus')->where('id',$id)->update(['active'=>!$m->active]);});return back()->with('ok','Status menu diperbarui.');}
}
