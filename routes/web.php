<?php

use Illuminate\Support\Facades\Route;

Route::get('/', \App\Livewire\ChatPage::class)->name('chat');
