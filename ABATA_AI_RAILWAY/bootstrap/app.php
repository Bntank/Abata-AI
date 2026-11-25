<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
 ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
 ->withMiddleware(function(Middleware $m): void { $m->alias(['role'=>\App\Http\Middleware\Role::class]);
  // Railway terminates TLS at its reverse proxy. Enable only for Railway ingress.
  if (getenv('ABATA_TRUST_PROXY') === 'true') {
   $m->trustProxies(at: '*', headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
  } })
 ->withExceptions(function(Exceptions $e): void {})->create();
