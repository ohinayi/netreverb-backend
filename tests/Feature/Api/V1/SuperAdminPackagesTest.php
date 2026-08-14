<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminPackagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing-piper-voices'));

        parent::tearDown();
    }

    public function test_super_admin_sees_piper_voice_install_state_and_libretranslate_languages(): void
    {
        $voicesDir = storage_path('app/testing-piper-voices');
        File::ensureDirectoryExists($voicesDir);
        File::put($voicesDir.'/en_US-lessac-medium.onnx', str_repeat('x', 128));
        File::put($voicesDir.'/en_US-lessac-medium.onnx.json', '{}');

        config()->set('tts.piper.voices', [
            'en_US-lessac-medium' => [
                'label' => 'Lessac',
                'description' => 'US English',
                'model' => $voicesDir.'/en_US-lessac-medium.onnx',
            ],
            'en_US-amy-medium' => [
                'label' => 'Amy',
                'description' => 'US English · female',
                'model' => $voicesDir.'/en_US-amy-medium.onnx',
            ],
        ]);

        config()->set('translation.providers.libretranslate.base_url', 'http://libretranslate.test');
        Http::fake([
            'http://libretranslate.test/languages' => Http::response([
                ['code' => 'en', 'name' => 'English'],
                ['code' => 'fr', 'name' => 'French'],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->getJson('/api/v1/super-admin/packages')
            ->assertOk()
            ->assertJsonPath('data.libretranslate.reachable', true)
            ->assertJsonCount(2, 'data.libretranslate.languages')
            ->assertJsonPath('data.piper_voices.0.key', 'en_US-lessac-medium')
            ->assertJsonPath('data.piper_voices.0.installed', true)
            ->assertJsonPath('data.piper_voices.1.key', 'en_US-amy-medium')
            ->assertJsonPath('data.piper_voices.1.installed', false);
    }

    public function test_regular_user_cannot_view_packages(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/super-admin/packages')->assertForbidden();
    }

    public function test_downloading_an_unknown_voice_returns_a_clean_error(): void
    {
        config()->set('tts.piper.voices', []);

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->postJson('/api/v1/super-admin/packages/voices/not-a-real-voice/download')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown Piper voice "not-a-real-voice".');
    }

    public function test_regular_user_cannot_download_a_voice(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/super-admin/packages/voices/en_US-lessac-medium/download')
            ->assertForbidden();
    }
}
