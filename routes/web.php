<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (config('api_docs.enabled')) {
    Route::view('/docs/api', 'api-docs')->name('api-docs.ui');
    Route::get('/docs/api/openapi.yaml', fn () => response(
        File::get(base_path('docs/openapi.yaml')),
        headers: ['Content-Type' => 'application/yaml'],
    ))->name('api-docs.spec');
}
