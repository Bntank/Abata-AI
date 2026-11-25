<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class DemoTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();config(['abata.passwords'=>['admin'=>'test-admin-long','manager'=>'test-manager-long','cashier'=>'test-cashier-long']]);$this->seed();}
 private function user($role){return User::where('role',$role)->firstOrFail();}
 private function product($name){return DB::table('products')->where('name',$name)->first();}
 private function key(){return (string)Str::uuid();}
 public function test_seed_and_rerun_are_idempotent():void {$this->assertDatabaseCount('daily_history',2920);$this->assertDatabaseCount('sales',4380);$this->assertDatabaseCount('products',4);$this->assertDatabaseCount('menus',6);$this->seed();$this->assertDatabaseCount('sales',4380);$this->assertDatabaseCount('model_registries',1);foreach(DB::table('products')->get() as $p)$this->assertEqualsWithDelta(DB::table('stock_lots')->where('product_id',$p->id)->sum('remaining'),DB::table('stock_movements')->where('product_id',$p->id)->sum('qty'),.001);}
 public function test_auth_and_cashier_role():void {$this->get('/dashboard')->assertRedirect('/login');$this->post('/login',['email'=>'cashier@abata.local','password'=>'wrong'])->assertSessionHasErrors('email');$this->post('/login',['email'=>'cashier@abata.local','password'=>'test-cashier-long'])->assertRedirect();$this->get('/pos')->assertOk();$this->get('/dashboard')->assertForbidden();$this->post('/orders',[])->assertForbidden();$this->get('/login/quick/admin')->assertNotFound();}
 public function test_manager_views_and_admin_only_master():void {$this->actingAs($this->user('manager'));foreach(['dashboard','forecast','recommendations','inventory','orders','production','performance','backtesting','products','menus'] as $page)$this->get('/'.$page)->assertOk();$this->post('/menus',[])->assertForbidden();}
 public function test_po_receipt_and_duplicate():void {$i=app(Inventory::class);$p=$this->product('Minyak Goreng');$uid=$this->user('manager')->id;$d=$i->state()->open_date;$before=$i->available($p->id,$d);$id=$i->draft($p->id,4,$d,$this->key(),$uid);$i->transition($id,'approve',$uid);$this->assertEquals($before,$i->available($p->id,$d));$k=$this->key();$i->receipt($id,2,$k,$uid);$i->receipt($id,2,$k,$uid);$this->assertEqualsWithDelta($before+2,$i->available($p->id,$d),.001);$this->assertDatabaseHas('orders',['id'=>$id,'status'=>'partially_received']);$i->transition($id,'cancel',$uid);$this->assertDatabaseHas('orders',['id'=>$id,'status'=>'cancelled']);}
 public function test_pos_partial_lost_and_close_day():void {$i=app(Inventory::class);$uid=$this->user('manager')->id;$date=$i->state()->open_date;$p=$this->product('Nasi Porsi');$id=$i->draft($p->id,1,$date,$this->key(),$uid);$i->transition($id,'approve',$uid);$i->receipt($id,1,$this->key(),$uid);$mid=DB::table('menus')->where('code','M01')->value('id');$k=$this->key();$cashier=$this->user('cashier')->id;$r=$i->sale($mid,3,$k,$cashier);$this->assertEquals(1,$r['sold']);$this->assertEquals(2,$r['lost']);$this->assertTrue($i->sale($mid,3,$k,$cashier)['duplicate']);$this->assertEquals(0,$i->available($p->id,$date));$i->close($date,$uid,true);$this->assertDatabaseCount('daily_history',2924);$this->assertDatabaseHas('daily_history',['date'=>$date,'product_id'=>$p->id,'quantity'=>1,'demand_quantity'=>3,'closing_stock'=>0]);$this->assertEquals('2026-11-26',$i->state()->open_date);}
 public function test_nasi_expiry():void {$i=app(Inventory::class);$uid=$this->user('manager')->id;$d=$i->state()->open_date;$p=$this->product('Nasi Porsi');$id=$i->draft($p->id,10,$d,$this->key(),$uid);$i->transition($id,'approve',$uid);$i->receipt($id,10,$this->key(),$uid);$i->close($d,$uid,true);$this->assertDatabaseHas('daily_history',['date'=>$d,'product_id'=>$p->id,'waste_quantity'=>10,'closing_stock'=>0]);}
 public function test_reject_conflicting_receipt_key_and_overreceipt():void {$i=app(Inventory::class);$uid=$this->user('manager')->id;$d=$i->state()->open_date;$p=$this->product('Minyak Goreng');$id=$i->draft($p->id,2,$d,$this->key(),$uid);$i->transition($id,'approve',$uid);$this->expectException(\Illuminate\Validation\ValidationException::class);$i->receipt($id,3,$this->key(),$uid);}
 public function test_forecast_load_only_and_next_day():void {
  $i=app(Inventory::class);$f=app(\App\Services\Forecast::class);$uid=$this->user('manager')->id;$id=$f->generate();$r=DB::table('forecast_runs')->find($id);$actual=json_decode($r->forecast_json,true);$ref=json_decode(file_get_contents(base_path('ml_models/inference.json')),true);$this->assertCount(8,$actual['predictions']);foreach($ref['predictions'] as $n=>$p)foreach(['q50','q80','qcf'] as $q)if(isset($p[$q]))$this->assertEqualsWithDelta($p[$q],$actual['predictions'][$n][$q],.000001);$this->assertCount(16,json_decode($r->recommendation_json,true)['recommendations']);
  $i->close($i->state()->open_date,$uid,true);$new=DB::table('forecast_runs')->find($f->generate());$this->assertEquals('2026-11-26',$new->target_date);$this->assertEquals($r->run_id,$new->run_id);
 }
 public function test_simplified_navigation_and_report_role():void {
  $this->actingAs($this->user('manager'));
  $this->get('/dashboard')->assertOk()->assertSee('Ringkasan Usaha')->assertSee('Saran Belanja');
  $this->get('/report')->assertOk()->assertSee('Pergerakan bahan hari ini')->assertSee('Tutup operasional hari ini');
  $this->get('/recommendations')->assertOk()->assertSee('Perbarui prediksi');
  $this->actingAs($this->user('cashier'));
  $this->get('/pos')->assertOk()->assertSee('Catat pesanan pelanggan')->assertSee('Porsi tidak terpenuhi');
  $this->get('/report')->assertForbidden();$this->post('/orders/plan',[])->assertForbidden();
 }
 public function test_batch_plan_is_validated_and_atomic():void {
  $manager=$this->user('manager');$this->actingAs($manager);$f=app(\App\Services\Forecast::class);$i=app(Inventory::class);$run=$f->generate();
  $p=$this->product('Ayam Potong (kg)');$oil=$this->product('Minyak Goreng');$rice=$this->product('Nasi Porsi');$before=$i->available($p->id,$i->state()->open_date);
  $data=['forecast_run_id'=>$run,'policy'=>'fixed_q80','confirmed'=>'1','product_ids'=>[$p->id,$oil->id,$rice->id]];
  $this->post('/orders/plan',array_replace($data,['product_ids'=>[$p->id,99999]]))->assertNotFound();$this->assertDatabaseCount('orders',0);
  $this->post('/orders/plan',$data)->assertRedirect(route('orders'));$this->assertDatabaseCount('orders',3);
  $this->assertDatabaseHas('orders',['product_id'=>$p->id,'qty'=>75,'status'=>'draft','forecast_run_id'=>$run,'policy'=>'fixed_q80']);
  $this->assertDatabaseHas('orders',['product_id'=>$rice->id,'type'=>'production','status'=>'draft']);
  $this->assertEqualsWithDelta($before,$i->available($p->id,$i->state()->open_date),.001);
  $this->post('/orders/plan',$data)->assertSessionHasErrors('operation');$this->assertDatabaseCount('orders',3);
  $i->calendar(['is_holiday'=>0,'is_promotion'=>1,'is_ramadan'=>0,'is_eid_period'=>0]);
  $this->post('/orders/plan',$data)->assertSessionHasErrors('operation');$this->assertDatabaseCount('orders',3);
 }

}
