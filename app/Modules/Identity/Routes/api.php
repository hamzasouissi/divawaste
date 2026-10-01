<?php

use App\Modules\Identity\Resources\MeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('me', fn (Request $request) => new MeResource($request->user()))->name('me');
