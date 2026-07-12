<?php

use App\Livewire\MonasticChat;
use App\Livewire\TempleChat;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/tra-cuu', TempleChat::class)
    ->middleware(['auth', 'throttle:20,1'])
    ->name('tra-cuu');

Route::get('/tra-cuu-tang-ni', MonasticChat::class)
    ->middleware(['auth', 'throttle:20,1'])
    ->name('tra-cuu-tang-ni');
