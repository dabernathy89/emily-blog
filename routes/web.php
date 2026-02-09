<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

Route::statamic('{year}/{month}', 'articles.archive', function (string $year, string $month) {
    $date = Carbon::createFromDate((int) $year, (int) $month, 1);

    return [
        'title' => $date->format('F Y'),
        'archive_since' => $date->copy()->subDay()->format('Y-m-d'),
        'archive_until' => $date->copy()->endOfMonth()->addDay()->format('Y-m-d'),
    ];
})->where('year', '[0-9]{4}')->where('month', '[0-9]{2}');
