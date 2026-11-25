<?php
/** Opt-in real process concurrency test. Requires a brand-new isolated MySQL schema. */
use App\Services\Inventory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
$database=getenv('ABATA_MYSQL_TEST_DB');
if(!is_string($database)||!preg_match('/^abata_reference_test_[a-z0-9_]+$/D',$database)){fwrite(STDERR,"Set ABATA_MYSQL_TEST_DB to a NEW empty schema beginning abata_reference_test_\n");exit(2);}
foreach(['APP_ENV'=>'testing','DB_CONNECTION'=>'mysql','DB_DATABASE'=>$database,'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array'] as $k=>$v){putenv("$k=$v");$_ENV[$k]=$v;$_SERVER[$k]=$v;}
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='mysql'||DB::connection()->getDatabaseName()!==$database||DB::selectOne('SELECT DATABASE() AS db')->db!==$database)throw new RuntimeException('Isolated test DB guard failed');
$i=app(Inventory::class);
if(($argv[1]??'')==='worker'){
 $dir=$argv[2];$n=$argv[3];$menu=(int)$argv[4];$uid=(int)$argv[5];file_put_contents($dir.'/ready-'.$n,'1');$start=microtime(true);while(!file_exists($dir.'/go')){if(microtime(true)-$start>15)throw new RuntimeException('Barrier timeout');usleep(10000);}echo json_encode($i->sale($menu,1,(string)Str::uuid(),$uid));exit;
}
if(DB::select('SHOW TABLES'))throw new RuntimeException('Test DB must be empty; create a new schema. No reset is performed.');
config(['abata.passwords'=>['admin'=>'test-admin-long','manager'=>'test-manager-long','cashier'=>'test-cashier-long']]);
if(Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException('Migration failed');if(Artisan::call('db:seed',['--force'=>true])!==0)throw new RuntimeException('Seeder failed');
$manager=DB::table('users')->where('role','manager')->value('id');$cashier=DB::table('users')->where('role','cashier')->value('id');$rice=DB::table('products')->where('name','Nasi Porsi')->value('id');$menu=DB::table('menus')->where('code','M01')->value('id');$d=$i->state()->open_date;$order=$i->draft($rice,1,$d,(string)Str::uuid(),$manager);$i->transition($order,'approve',$manager);$i->receipt($order,1,(string)Str::uuid(),$manager);
$dir=sys_get_temp_dir().'/abata-race-'.bin2hex(random_bytes(8));mkdir($dir,0700);$ps=[];
try{for($n=0;$n<2;$n++){$p=new Process([PHP_BINARY,__FILE__,'worker',$dir,(string)$n,(string)$menu,(string)$cashier],base_path(),null,null,30);$p->start();$ps[]=$p;}$start=microtime(true);while(!file_exists($dir.'/ready-0')||!file_exists($dir.'/ready-1')){if(microtime(true)-$start>15)throw new RuntimeException('Workers not ready');usleep(10000);}file_put_contents($dir.'/go','1');$rows=[];foreach($ps as $p){$p->wait();if(!$p->isSuccessful())throw new RuntimeException('Worker error: '.$p->getErrorOutput());$rows[]=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);}if(array_sum(array_column($rows,'sold'))!==1||array_sum(array_column($rows,'lost'))!==1||$i->available($rice,$d)!==0.0)throw new RuntimeException('Race invariant violated');echo json_encode(['status'=>'PASS','database'=>$database,'workers'=>$rows,'rice_remaining'=>$i->available($rice,$d)],JSON_PRETTY_PRINT).PHP_EOL;}finally{foreach($ps as $p)if($p->isRunning())$p->stop(1);foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
