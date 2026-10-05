<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// Установщик 1.0.0 больше не лежит в репозитории: старые ссылки ведут на актуальную версию.
Route::permanentRedirect('/downloads/GoydaCord-Setup-1.0.0.exe', 'https://sonetcord.ru/downloads/windows');
