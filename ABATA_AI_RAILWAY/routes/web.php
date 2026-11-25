<?php
use App\Http\Controllers\{AuthController,WebController};
use Illuminate\Support\Facades\Route;
Route::middleware('guest')->group(function(){Route::get('/login',[AuthController::class,'form'])->name('login');Route::post('/login',[AuthController::class,'login'])->middleware('throttle:5,1')->name('login.submit');});
Route::middleware('auth')->group(function(){
 Route::post('/logout',[AuthController::class,'logout'])->name('logout');Route::get('/',[WebController::class,'home'])->name('home');
 Route::middleware('role:admin,manager,cashier')->group(function(){Route::get('/pos',[WebController::class,'page'])->defaults('page','pos')->name('pos');Route::post('/pos',[WebController::class,'sale'])->name('sale');});
 Route::middleware('role:admin,manager')->group(function(){foreach(['dashboard','forecast','recommendations','inventory','orders','production','performance','backtesting','products','menus','report'] as $page)Route::get('/'.$page,[WebController::class,'page'])->defaults('page',$page)->name($page);
 Route::post('/forecast/generate',[WebController::class,'forecast'])->middleware('throttle:6,1')->name('forecast.generate');Route::post('/calendar',[WebController::class,'calendar'])->name('calendar.save');Route::post('/day/close',[WebController::class,'close'])->name('day.close');Route::post('/inventory/waste',[WebController::class,'waste'])->name('waste');Route::post('/orders',[WebController::class,'draft'])->name('order.draft');Route::post('/orders/plan',[WebController::class,'draftPlan'])->name('order.plan');Route::post('/orders/{id}/transition',[WebController::class,'transition'])->whereNumber('id')->name('order.transition');Route::post('/orders/{id}/receipt',[WebController::class,'receipt'])->whereNumber('id')->name('order.receipt');});
 Route::middleware('role:admin')->group(function(){Route::post('/products/{id}/label',[WebController::class,'label'])->whereNumber('id')->name('product.label');Route::post('/menus',[WebController::class,'menu'])->name('menu.create');Route::post('/menus/{id}/toggle',[WebController::class,'toggle'])->whereNumber('id')->name('menu.toggle');});
});
