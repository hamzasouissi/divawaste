<?php

use App\Modules\Assistant\Support\DaviClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * DAVI, the assistant (G-11, AU-02): the screens ask it questions, it answers from the verified knowledge and the
 * user's own data, proposes actions and runs one only after the user's explicit yes — always with the user's rights
 * (DaviClient). DAVI's own token cannot call these routes.
 */
Route::middleware(['auth:sanctum', 'throttle:davi'])->prefix('davi')->group(function () {
    $company = function (Request $request): ?int {
        $token = $request->user()->currentAccessToken();
        abort_if($token instanceof PersonalAccessToken && $token->name === DaviClient::TOKEN_NAME, 403);
        $id = $token instanceof PersonalAccessToken ? $token->company_id : ($request->hasSession() ? $request->session()->get('current_company_id') : null);

        return is_numeric($id) ? (int) $id : null;
    };
    $entity = ['entity' => ['nullable', 'array'], 'entity.kind' => ['required_with:entity', 'in:lot,pickup'], 'entity.number' => ['required_with:entity', 'string', 'max:40']];
    $screen = ['screen' => ['nullable', 'string', 'max:200']];

    Route::get('status', function (Request $request) use ($company) {
        $company($request);

        return ['data' => ['available' => DaviClient::configured()]];
    })->name('davi.status');

    // One turn of a conversation: a question, an action to prepare, or the "oui" / "non" to a prepared one.
    Route::post('turn', function (Request $request, DaviClient $davi) use ($company, $entity, $screen) {
        $companyId = $company($request);
        $input = $request->validate(['conversation' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{6,64}$/'], 'message' => ['required', 'string', 'max:1000']] + $screen + $entity);

        return ['data' => $davi->post($request->user(), $companyId, '/turn', $input)];
    })->name('davi.turn');

    // What to ask or do on the page the user is on.
    Route::post('suggest', function (Request $request, DaviClient $davi) use ($company, $entity, $screen) {
        $companyId = $company($request);

        return ['data' => $davi->post($request->user(), $companyId, '/suggest', $request->validate($screen + $entity) + ['screen' => null])];
    })->name('davi.suggest');

    // AU-02: the values of a customs permit read from its PDF, each with the words it came from; nothing is saved.
    Route::post('read-permit', function (Request $request, DaviClient $davi) use ($company) {
        $companyId = $company($request);
        $input = $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:10240']]);

        return ['data' => $davi->post($request->user(), $companyId, '/read-permit', [], $input['file'])];
    })->name('davi.read-permit');

    /*
     * The question game ("Entraîner DAVI"): a mission made by DAVI from what the platform has, the player's own
     * question (asked through `turn` like any other), their verdict; and a remark on any answer of the panel. DAVI keeps
     * them as questions for its next training, with the mission's target as label.
     */
    Route::post('game/mission', function (Request $request, DaviClient $davi) use ($company) {
        $companyId = $company($request);
        $input = $request->validate(['kind' => ['nullable', 'in:all,where,action,knowledge,lookup'], 'same' => ['nullable', 'string', 'max:300'],
            'skip' => ['nullable', 'array', 'max:50'], 'skip.*' => ['string', 'max:300']]);

        return ['data' => $davi->post($request->user(), $companyId, '/game/mission', array_filter($input, fn ($value) => $value !== null))];
    })->name('davi.game.mission');

    Route::post('game/judge', function (Request $request, DaviClient $davi) use ($company) {
        $companyId = $company($request);
        $input = $request->validate(['key' => ['required', 'string', 'max:300'], 'reply' => ['required', 'array']]);

        return ['data' => $davi->post($request->user(), $companyId, '/game/judge', $input)];
    })->name('davi.game.judge');

    Route::post('game/play', function (Request $request, DaviClient $davi) use ($company, $screen) {
        $companyId = $company($request);
        $input = $request->validate(['key' => ['required', 'string', 'max:300'], 'question' => ['required', 'string', 'max:1000'], 'reply' => ['required', 'array'],
            'verdict' => ['required', 'in:right,wrong,other'], 'remark' => ['nullable', 'string', 'max:2000']] + $screen);

        return ['data' => $davi->post($request->user(), $companyId, '/game/play', $input)];
    })->name('davi.game.play');

    Route::post('feedback', function (Request $request, DaviClient $davi) use ($company, $screen) {
        $companyId = $company($request);
        $input = $request->validate(['question' => ['nullable', 'string', 'max:1000'], 'answer' => ['nullable', 'string', 'max:4000'], 'tier' => ['nullable', 'string', 'max:60'],
            'verdict' => ['required', 'in:up,down'], 'remark' => ['nullable', 'string', 'max:2000']] + $screen);
        $davi->post($request->user(), $companyId, '/feedback', $input);

        return response()->noContent();
    })->name('davi.feedback');

    Route::post('forget', function (Request $request, DaviClient $davi) use ($company) {
        $companyId = $company($request);
        $input = $request->validate(['conversation' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{6,64}$/']]);
        $davi->post($request->user(), $companyId, '/forget', $input);

        return response()->noContent();
    })->name('davi.forget');
});
