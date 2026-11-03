<?php

namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Support\DaviClient;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * DAVI is reached only through the API, with a short-lived token of the signed-in user bound to their company: it
 * reads and acts with the user's rights, never more, and its own token cannot call it.
 */
class DaviProxyTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->manager = $this->makeMember($this->company, ['client_admin' => null]);
        config(['services.davi.url' => 'http://davi.test', 'services.davi.key' => 'shared-key']);
    }

    public function test_a_turn_goes_to_davi_with_a_token_of_the_user_for_their_company(): void
    {
        Http::fake(['davi.test/*' => Http::response(['answer' => 'Bonjour', 'kind' => 'answer', 'sources' => []])]);

        $this->asManager()->postJson('/api/v1/davi/turn', ['conversation' => 'conv-123', 'message' => 'Où en est ce lot ?', 'screen' => '/lots/x',
            'entity' => ['kind' => 'lot', 'number' => 'L-2026-000001']])->assertOk()->assertJsonPath('data.answer', 'Bonjour');
        $this->asManager()->postJson('/api/v1/davi/turn', ['conversation' => 'conv-123', 'message' => 'oui'])->assertOk();

        $sent = [];
        Http::assertSent(function (ClientRequest $request) use (&$sent) {
            $sent[] = $request->header('X-Davi-Token')[0];

            return $request->url() === 'http://davi.test/turn' && $request->header('X-Davi-Key')[0] === 'shared-key' && $request['conversation'] === 'conv-123';
        });
        // One token for both turns: the user's, for this company, ending within two hours.
        $this->assertCount(1, array_unique($sent));
        $token = PersonalAccessToken::findToken($sent[0]);
        $this->assertSame($this->manager->id, $token->tokenable_id);
        $this->assertSame($this->company->id, (int) $token->company_id);
        $this->assertSame(DaviClient::TOKEN_NAME, $token->name);
        $this->assertTrue($token->expires_at->lte(now()->addHours(2)));
    }

    public function test_the_question_game_and_remarks_go_to_davi(): void
    {
        Http::fake(['davi.test/game/mission' => Http::response(['mission' => ['key' => 'where:Nouvel OF', 'kind' => 'where', 'goal' => 'Vous voulez créer un nouvel OF.'], 'stats' => ['questions' => 0]]),
            'davi.test/game/play' => Http::response(['stats' => ['questions' => 1, 'points' => 3]]), 'davi.test/feedback' => Http::response(['ok' => true])]);

        $this->asManager()->postJson('/api/v1/davi/game/mission', ['kind' => 'where'])->assertOk()->assertJsonPath('data.mission.key', 'where:Nouvel OF');
        $this->asManager()->postJson('/api/v1/davi/game/play', ['key' => 'where:Nouvel OF', 'question' => 'comment ajouter un OF', 'reply' => ['kind' => 'answer'], 'verdict' => 'wrong'])
            ->assertOk()->assertJsonPath('data.stats.points', 3);
        $this->asManager()->postJson('/api/v1/davi/game/play', ['key' => 'x', 'question' => 'q', 'reply' => ['kind' => 'answer'], 'verdict' => 'peut-être'])->assertUnprocessable();
        $this->asManager()->postJson('/api/v1/davi/feedback', ['question' => 'mes prestataires', 'answer' => 'Aucun résultat.', 'verdict' => 'down', 'remark' => 'il oublie les partenaires sans compte'])
            ->assertNoContent();

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'http://davi.test/game/play' && $request['verdict'] === 'wrong' && $request['question'] === 'comment ajouter un OF');
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'http://davi.test/feedback' && $request['verdict'] === 'down');
    }

    public function test_davi_cannot_call_itself_and_says_when_it_is_away(): void
    {
        $issued = $this->manager->createToken(DaviClient::TOKEN_NAME, ['*'], now()->addHour());
        $issued->accessToken->forceFill(['company_id' => $this->company->id])->save();
        $this->withToken($issued->plainTextToken)->postJson('/api/v1/davi/turn', ['conversation' => 'conv-123', 'message' => 'boucle'])->assertForbidden();

        Http::fake(['davi.test/*' => fn () => throw new ConnectionException('down')]);
        $this->asManager()->postJson('/api/v1/davi/suggest', ['screen' => '/lots'])->assertStatus(503);

        config(['services.davi.key' => null]);
        $this->asManager()->getJson('/api/v1/davi/status')->assertOk()->assertJsonPath('data.available', false);
        $this->asManager()->postJson('/api/v1/davi/turn', ['conversation' => 'conv-123', 'message' => 'bonjour'])->assertStatus(503);
    }

    public function test_a_permit_is_sent_to_davi_and_nothing_is_saved(): void
    {
        Http::fake(['davi.test/read-permit' => Http::response(['read' => true, 'fields' => ['reference' => ['value' => 'AUT-2026-17', 'evidence' => 'Autorisation n° AUT-2026-17']]])]);
        $before = DB::table('waste_authorizations')->count();

        $this->asManager()->post('/api/v1/davi/read-permit', ['file' => UploadedFile::fake()->createWithContent('permis.pdf', "%PDF-1.4\n%fake")], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.fields.reference.value', 'AUT-2026-17');
        $this->assertSame($before, DB::table('waste_authorizations')->count());
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'http://davi.test/read-permit' && $request->isMultipart());
    }

    private function asManager(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->company));
    }
}
