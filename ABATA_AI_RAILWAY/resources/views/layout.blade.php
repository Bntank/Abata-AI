<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ABATA AI — @yield('title','Ringkasan Usaha')</title><link rel="stylesheet" href="{{ asset('app.css') }}"><script defer src="{{ asset('app.js') }}"></script></head><body>
@php($role=auth()->user()->role)
@php($labels=['admin'=>'Administrator','manager'=>'Pengelola','cashier'=>'Kasir'])
<div class="shell"><aside id="sidebar"><a class="brand" href="{{ route('home') }}"><span class="brand-mark">A</span><span>ABATA <b>AI</b><small>Chicken · Persediaan</small></span></a>
<nav aria-label="Navigasi utama">
@if($role==='cashier')<div class="nav-label">PENJUALAN</div><a class="active" href="{{ route('pos') }}">Kasir & riwayat transaksi</a>
@else
<div class="nav-label">OPERASIONAL</div>
@foreach(['dashboard'=>'Ringkasan Usaha','pos'=>'Kasir','inventory'=>'Persediaan','recommendations'=>'Saran Belanja','orders'=>'Pembelian & Produksi','report'=>'Laporan Harian'] as $key=>$name)
<a class="{{ ($page??'')===$key||($key==='orders'&&$page==='production')||($key==='recommendations'&&$page==='forecast')?'active':'' }}" href="{{ route($key) }}">{{ $name }}</a>@endforeach
<div class="nav-label">PENGATURAN</div><details @if(in_array($page,['products','menus'])) open @endif><summary>Master data</summary><a href="{{ route('products') }}" class="{{ $page==='products'?'active':'' }}">Bahan baku</a><a href="{{ route('menus') }}" class="{{ $page==='menus'?'active':'' }}">Menu & resep</a></details>
<details @if(in_array($page,['performance','backtesting'])) open @endif><summary>Analitik lanjutan</summary><a href="{{ route('performance') }}" class="{{ $page==='performance'?'active':'' }}">Evaluasi model</a><a href="{{ route('backtesting') }}" class="{{ $page==='backtesting'?'active':'' }}">Simulasi strategi</a><a href="{{ route('forecast') }}">Detail prediksi</a></details>
@endif</nav><div class="account"><b>{{ auth()->user()->name }}</b><small>{{ $labels[$role]??$role }}</small><form method="post" action="{{ route('logout') }}">@csrf<button class="secondary w-full">Keluar</button></form></div></aside>
<main id="main-content"><header class="topbar"><div class="flex items-center gap-3"><button type="button" class="mobile-toggle secondary" aria-controls="sidebar" aria-expanded="false" data-sidebar>Menu</button><div><span class="eyebrow">ABATA CHICKEN / {{ $labels[$role]??$role }}</span><h1>@yield('title')</h1></div></div><div class="date-pill"><small>Tanggal operasional</small><b>{{ \Carbon\Carbon::parse($state->open_date)->locale('id')->translatedFormat('d F Y') }}</b></div></header>
@if(session('ok'))<div class="notice" role="status">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="error" role="alert"><b>Periksa kembali</b>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
@yield('content')<footer>ABATA AI · Manajemen persediaan @if($role!=='cashier') · <a href="{{ route('performance') }}">Informasi model dan sumber data</a>@endif</footer></main></div></body></html>
