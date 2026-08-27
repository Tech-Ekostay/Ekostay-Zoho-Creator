<?php

use Illuminate\Support\Facades\Route;

// Single entry point; the React app owns routing below it.
Route::view('/{any?}', 'app')->where('any', '.*');
