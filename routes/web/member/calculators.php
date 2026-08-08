<?php

use App\Livewire\CalculatorCenter;
use Illuminate\Support\Facades\Route;

Route::get('/calculators', CalculatorCenter::class)->name('calculators.index');
