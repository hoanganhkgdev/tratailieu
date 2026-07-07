<?php

use App\Livewire\TempleChat;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/tra-cuu', TempleChat::class)
    ->middleware(['auth', 'throttle:20,1'])
    ->name('tra-cuu');
