<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique();$t->string('password');$t->string('role');$t->rememberToken();$t->timestamps();});
  Schema::create('products',function(Blueprint $t){$t->id();$t->string('name')->unique();$t->string('label');$t->string('unit');$t->decimal('purchase_cost',14,2);$t->decimal('underage_cost',14,2);$t->decimal('overage_cost',14,2);$t->unsignedInteger('lead_time');$t->decimal('order_multiple',12,3);$t->unsignedInteger('shelf_life_days');$t->boolean('is_production');});
  Schema::create('menus',function(Blueprint $t){$t->id();$t->string('code')->unique();$t->string('name');$t->boolean('active')->default(true);});
  Schema::create('bom',function(Blueprint $t){$t->foreignId('menu_id')->constrained('menus');$t->foreignId('product_id')->constrained();$t->decimal('qty',14,3);$t->primary(['menu_id','product_id']);});
  Schema::create('demo_state',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->date('closed_through');$t->date('open_date');$t->boolean('started')->default(false);$t->unsignedBigInteger('revision')->default(0);$t->string('scope');$t->string('seed_hash',64);});
  Schema::create('calendar_days',function(Blueprint $t){$t->date('date')->primary();foreach(['is_holiday','is_promotion','is_ramadan','is_eid_period'] as $c)$t->boolean($c);$t->string('source');});
  Schema::create('daily_history',function(Blueprint $t){$t->foreignId('product_id')->constrained();$t->date('date');foreach(['quantity','demand_quantity','opening_stock','incoming_stock','waste_quantity','closing_stock'] as $c)$t->decimal($c,14,3);$t->string('source');$t->primary(['product_id','date']);});
  Schema::create('sales',function(Blueprint $t){$t->id();$t->foreignId('menu_id')->constrained('menus');$t->date('date');$t->unsignedInteger('requested');$t->unsignedInteger('sold');$t->unsignedInteger('lost');$t->string('event_key',100)->unique();$t->string('source');$t->foreignId('user_id')->nullable()->constrained('users');});
  Schema::create('sale_items',function(Blueprint $t){$t->foreignId('sale_id')->constrained('sales');$t->foreignId('product_id')->constrained();$t->decimal('usage_qty',14,3);$t->decimal('demand_qty',14,3);$t->primary(['sale_id','product_id']);});
  Schema::create('stock_lots',function(Blueprint $t){$t->id();$t->foreignId('product_id')->constrained();$t->date('received_date');$t->date('expires_date');$t->decimal('remaining',14,3);$t->string('source');});
  Schema::create('stock_movements',function(Blueprint $t){$t->id();$t->foreignId('product_id')->constrained();$t->date('date');$t->string('kind');$t->decimal('qty',14,3);$t->string('event_key',150)->unique();$t->string('note')->nullable();$t->foreignId('user_id')->nullable()->constrained('users');$t->index(['product_id','date']);});
  Schema::create('model_registries',function(Blueprint $t){$t->string('run_id')->primary();$t->string('status');$t->string('scope');$t->string('manifest_sha256',64);$t->string('artifact_dir');$t->longText('manifest_json');$t->timestamps();});
  Schema::create('forecast_runs',function(Blueprint $t){$t->id();$t->date('as_of');$t->date('target_date');$t->unsignedBigInteger('revision');$t->string('run_id');$t->foreign('run_id')->references('run_id')->on('model_registries');$t->string('status');$t->longText('forecast_json');$t->longText('recommendation_json');$t->timestamps();});
  Schema::create('orders',function(Blueprint $t){$t->id();$t->foreignId('product_id')->constrained();$t->string('type');$t->string('status');$t->decimal('qty',14,3);$t->decimal('received',14,3)->default(0);$t->date('expected_date');$t->string('event_key',100)->unique();$t->foreignId('forecast_run_id')->nullable()->constrained('forecast_runs');$t->string('policy')->nullable();$t->foreignId('created_by')->constrained('users');$t->foreignId('approved_by')->nullable()->constrained('users');$t->timestamps();});
  Schema::create('receipts',function(Blueprint $t){$t->id();$t->foreignId('order_id')->constrained('orders');$t->date('date');$t->decimal('qty',14,3);$t->string('event_key',100)->unique();$t->foreignId('user_id')->constrained('users');});
 }
 public function down(): void {foreach(['receipts','orders','forecast_runs','model_registries','stock_movements','stock_lots','sale_items','sales','daily_history','calendar_days','demo_state','bom','menus','products','users'] as $n)Schema::dropIfExists($n);}
};
