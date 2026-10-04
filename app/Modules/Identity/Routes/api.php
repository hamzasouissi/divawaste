<?php

use App\Modules\Identity\Resources\MeResource;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', function (Request $request) {
	$input = $request->validate([
		'email' => ['required', 'string', 'email'],
		'password' => ['required', 'string'],
		'company' => ['sometimes', 'nullable', 'string', 'ulid'],
	]);

	$email = mb_strtolower(trim($input['email']));
	$user = User::query()->where('email', $email)->first();

	if ($user === null || ! $user->isActive() || ! Hash::check($input['password'], $user->getAuthPassword())) {
		throw new AuthenticationException;
	}

	$companies = DB::table('company_users as memberships')
		->join('companies', 'companies.id', '=', 'memberships.company_id')
		->where('memberships.user_id', $user->id)
		->where('memberships.status', 'active')
		->where('companies.status', 'active')
		->orderBy('companies.legal_name')
		->get(['companies.id', 'companies.ulid', 'companies.legal_name']);

	$company = null;
	if (isset($input['company']) && $input['company'] !== null) {
		$requestedUlid = strtoupper($input['company']);
		$company = $companies->firstWhere('ulid', $requestedUlid);

		abort_unless($company !== null, 403);
	} else {
		$company = $companies->firstWhere('id', (int) $user->last_company_id);
		$company ??= $companies->count() === 1 ? $companies->first() : null;

		if ($company === null && $companies->count() > 1) {
			return response()->json(['data' => [
				'requires_company_selection' => true,
				'companies' => $companies->map(fn ($item) => [
					'ulid' => $item->ulid,
					'legal_name' => $item->legal_name,
				])->values(),
			]]);
		}
	}

	$token = $user->createToken('api');
	$token->accessToken->forceFill(['company_id' => $company?->id])->save();
	$user->forceFill([
		'last_login_at' => now(),
		'last_login_ip' => $request->ip(),
		'last_company_id' => $company?->id,
	])->save();

	return response()->json(['data' => [
		'access_token' => $token->plainTextToken,
		'token_type' => 'Bearer',
		'requires_company_selection' => false,
		'company' => $company === null ? null : [
			'ulid' => $company->ulid,
			'legal_name' => $company->legal_name,
		],
		'user' => (new MeResource($user->refresh()))->resolve($request),
	]]);
})->name('auth.login')->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->get('me', fn (Request $request) => new MeResource($request->user()))->name('me');
