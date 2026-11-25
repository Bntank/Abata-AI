<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('abata:forecast',function(){ $id=app(\App\Services\Forecast::class)->generate();$this->info('Forecast run record: '.$id); })->purpose('Load-only inference for the next complete demo day');

// Deployment only: never wipe an existing database. No training or fixture insertion.
Artisan::command('abata:railway-init', function () {
    $mode = getenv('ABATA_INIT_MODE') ?: 'existing';
    if (!in_array($mode, ['seed', 'existing'], true)) {
        $this->error('ABATA_INIT_MODE must be seed or existing.'); return 1;
    }
    $key = (string) config('app.key');
    if (!str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32 || substr($key, 7) === 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=') {
        $this->error('Set a stable APP_KEY (base64-encoded 32 bytes).'); return 1;
    }
    if (config('database.default') !== 'mysql') {
        $this->error('Railway requires MySQL.'); return 1;
    }
    $db = \Illuminate\Support\Facades\DB::class;
    $schema = \Illuminate\Support\Facades\Schema::class;
    $ready = false;
    for ($i=0; $i<30; $i++) {
        try { $db::selectOne('SELECT 1'); $ready=true; break; }
        catch (\Throwable $e) { $db::purge(); sleep(2); }
    }
    if (!$ready) { $this->error('MySQL unavailable. Verify the Railway database service and references.'); return 1; }
    $hasState = $schema::hasTable('demo_state') && $db::table('demo_state')->exists();
    if (!$hasState && $mode === 'existing') {
        $this->error('Existing database has no ABATA state. Import the full local database or explicitly select seed mode.'); return 1;
    }
    if ($hasState && !$schema::hasTable('migrations')) {
        $this->error('Import must include the migrations table. No changes made.'); return 1;
    }
    if (!$hasState && $mode === 'seed') {
        // An empty database or a previously completed migration with no business data is allowed.
        foreach (['users','products','sales','stock_movements','orders','daily_history','model_registries'] as $table) {
            if ($schema::hasTable($table) && $db::table($table)->exists()) {
                $this->error('Partial or non-empty database detected; refusing automatic seed.'); return 1;
            }
        }
        $passwords = config('abata.passwords');
        foreach ($passwords as $p) {
            if (!is_string($p) || strlen($p)<16 || strlen($p)>72 || str_starts_with($p,'REPLACE_WITH_')) {
                $this->error('Set three distinct ABATA_*_PASSWORD secrets (16–72 characters) for initial seed.'); return 1;
            }
        }
        if (count(array_unique($passwords))!==3) { $this->error('Initial role passwords must be distinct.'); return 1; }
    }
    if ($this->call('migrate', ['--force'=>true]) !== 0) return 1;
    if (!$hasState && $mode === 'seed') {
        if ($this->call('db:seed', ['--force'=>true]) !== 0) return 1;
    }
    $state = $db::table('demo_state')->where('id',1)->first();
    $manifestPath=base_path('ml_models/manifest.json');
    $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    $registry=$db::table('model_registries')->where('run_id',$manifest['run_id'])->first();
    if (!$state || $state->scope!==$manifest['scope'] || $state->seed_hash!==$manifest['history_sha256'] ||
        !$registry || $registry->manifest_sha256!==hash_file('sha256',$manifestPath) ||
        $registry->status!==$manifest['status'] || $db::table('products')->count()!==4) {
        $this->error('Database/model contract mismatch. Restore a compatible database; do not bypass validation.'); return 1;
    }
    $this->info('ABATA database ready. Existing history preserved.');
    return 0;
})->purpose('Initialize an empty Railway database or validate an imported ABATA database');

// No password appears in the CLI arguments, source files, or normal command output.
Artisan::command('abata:reset-password {role}', function () {
    $role=$this->argument('role');
    if (!in_array($role,['admin','manager','cashier'],true)) { $this->error('Invalid role.'); return 1; }
    $p=$this->secret('New password (16–72 characters)');
    if (!is_string($p)||strlen($p)<16||strlen($p)>72||$p!==$this->secret('Repeat password')) { $this->error('Password invalid or confirmation differs.'); return 1; }
    $updated=\Illuminate\Support\Facades\DB::table('users')->where('role',$role)->where('email',$role.'@abata.local')->update(['password'=>\Illuminate\Support\Facades\Hash::make($p),'remember_token'=>null,'updated_at'=>now()]);
    if ($updated!==1) { $this->error('Expected one role account.'); return 1; }
    $this->info('Password updated.'); return 0;
})->purpose('Rotate an ABATA role password from a private terminal');
