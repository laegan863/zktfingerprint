<?php

use App\Http\Controllers\AttendanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AttendanceController::class, 'index']);
Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance');
Route::get('/attendance/recent',  [AttendanceController::class, 'recent']);
Route::get('/attendance/devices', [AttendanceController::class, 'devices']);
