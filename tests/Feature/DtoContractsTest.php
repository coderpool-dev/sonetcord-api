<?php

namespace Tests\Feature;

use App\Data\CallData;
use App\Data\PushNotificationData;
use App\Data\UpdateProfileData;
use App\Data\UpdateServerChannelData;
use App\Data\UpdateServerRoleData;
use App\Jobs\DeliverWebPush;
use App\Models\User;
use App\Services\Account\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DtoContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_patch_preserves_omitted_fields_and_clears_explicit_null(): void
    {
        $user = User::factory()->create(['banner_color' => '#112233', 'status_text' => 'Available']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/profile', ['name' => 'Updated'])->assertOk();
        $this->assertSame('#112233', $user->fresh()->banner_color);
        $this->assertSame('Available', $user->fresh()->status_text);

        $this->postJson('/api/auth/profile', ['banner_color' => null, 'status_text' => null])->assertOk();
        $this->assertNull($user->fresh()->banner_color);
        $this->assertNull($user->fresh()->status_text);
    }

    public function test_patch_contracts_retain_false_zero_empty_lists_and_null(): void
    {
        $role = UpdateServerRoleData::fromArray(['hoist' => '0', 'permissions' => '0']);
        $this->assertFalse($role->hoist);
        $this->assertSame(0, $role->permissions);
        $this->assertTrue($role->has('hoist'));
        $this->assertFalse($role->has('color'));

        $channel = UpdateServerChannelData::fromArray(['category_id' => null, 'overwrites' => []]);
        $this->assertTrue($channel->has('category_id'));
        $this->assertNull($channel->categoryId);
        $this->assertSame([], $channel->overwrites);
        $this->assertFalse($channel->has('member_overwrites'));

        $profile = UpdateProfileData::fromArray(['clear_status' => false]);
        $this->assertFalse($profile->clearStatus);
        $this->assertFalse($profile->has('status_text'));
    }

    public function test_call_result_preserves_notification_flags_in_the_http_payload(): void
    {
        $payload = ['call_id' => 'call-1', 'channel_id' => 1, 'initiator_id' => 2,
            'initiator_login' => 'caller', 'status' => 'active', 'type' => 'voice', 'silent_user_ids' => [3]];
        $this->assertSame($payload, CallData::fromArray($payload)->toArray());
        unset($payload['silent_user_ids']);
        $this->assertSame($payload, CallData::fromArray($payload)->toArray());
    }

    public function test_serialized_jobs_keep_the_legacy_payload_and_deliver_a_typed_notification(): void
    {
        $payload = ['title' => 'Incoming', 'body' => 'Caller', 'url' => '/channels/1', 'tag' => 'call-1',
            'icon' => null, 'kind' => 'call', 'call_id' => 'call-1', 'channel_id' => 1,
            'initiator_id' => 2, 'initiator_login' => 'caller', 'initiator_avatar' => null, 'silent_user_ids' => [3]];
        $job = unserialize(serialize(new DeliverWebPush(7, PushNotificationData::fromArray($payload))));
        $this->assertSame($payload, $job->payload);

        $sender = $this->mock(PushNotificationService::class);
        $sender->shouldReceive('deliver')->once()->withArgs(fn (int $id, PushNotificationData $data) => $id === 7 && $data->toArray() === $payload);
        $job->handle($sender);
    }
}
