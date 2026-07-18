<?php

namespace Tests\Unit;

use App\Services\Telephony\FreeSwitchEventSocketClient;
use App\Services\Telephony\SocketFreeSwitchConferenceGateway;
use Mockery;
use Tests\TestCase;

class SocketFreeSwitchConferenceGatewayTest extends TestCase
{
    public function test_list_members_falls_back_to_discovered_conference_name_when_bare_room_name_is_empty(): void
    {
        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('conference 45000000014 xml_list')
            ->andReturn('Conference not found');
        $client->shouldReceive('api')
            ->once()
            ->with('show channels as json')
            ->andReturn(json_encode([
                'rows' => [
                    [
                        'dest' => '45000000014',
                        'application' => 'conference',
                        'application_data' => '45000000014-213.199.52.39@default',
                    ],
                ],
            ]));
        $client->shouldReceive('api')
            ->once()
            ->with('conference 45000000014-213.199.52.39 xml_list')
            ->andReturn(<<<'XML'
<document type="freeswitch/xml">
  <section name="result">
    <conference name="45000000014-213.199.52.39">
      <members>
        <member type="caller">
          <id>42</id>
          <caller_id_number>100001</caller_id_number>
          <caller_id_name>Ohi Ibrahim</caller_id_name>
          <uuid>be3d82ff-87d8-45c7-8f16-1c37e8c2ecce</uuid>
        </member>
      </members>
    </conference>
  </section>
</document>
XML);

        $gateway = new SocketFreeSwitchConferenceGateway($client);

        $members = $gateway->listMembers('45000000014');

        $this->assertCount(1, $members);
        $this->assertSame('42', $members[0]['member_id']);
        $this->assertSame('100001', $members[0]['caller_number']);
        $this->assertSame('Ohi Ibrahim', $members[0]['caller_name']);
        $this->assertSame('be3d82ff-87d8-45c7-8f16-1c37e8c2ecce', $members[0]['uuid']);
        $this->assertSame('45000000014-213.199.52.39', $members[0]['conference_name']);
    }

    public function test_mute_member_uses_discovered_conference_name_when_needed(): void
    {
        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('conference 45000000014 mute 42 quiet')
            ->andReturn('Conference not found');
        $client->shouldReceive('api')
            ->once()
            ->with('show channels as json')
            ->andReturn(json_encode([
                'rows' => [
                    [
                        'dest' => '45000000014',
                        'application' => 'conference',
                        'application_data' => '45000000014-213.199.52.39@default',
                    ],
                ],
            ]));
        $client->shouldReceive('api')
            ->once()
            ->with('conference 45000000014-213.199.52.39 mute 42 quiet')
            ->andReturn('+OK');

        $gateway = new SocketFreeSwitchConferenceGateway($client);

        $gateway->muteMember('45000000014', '42');

        $this->assertTrue(true);
    }

    public function test_list_members_falls_back_to_live_channel_snapshot_when_xml_roster_is_empty(): void
    {
        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('conference 45000000014 xml_list')
            ->andReturn('');
        $client->shouldReceive('api')
            ->once()
            ->with('show channels as json')
            ->andReturn(json_encode([
                'rows' => [
                    [
                        'uuid' => 'c072387b-f140-4f0a-a9da-5d0cc5c31a9f',
                        'dest' => '45000000014',
                        'application' => 'conference',
                        'application_data' => '45000000014-213.199.52.39@default',
                        'cid_num' => '100001',
                        'cid_name' => 'Ohi Ibrahim',
                    ],
                ],
            ]));
        $client->shouldReceive('api')
            ->once()
            ->with('conference 45000000014-213.199.52.39 xml_list')
            ->andReturn('');

        $gateway = new SocketFreeSwitchConferenceGateway($client);

        $members = $gateway->listMembers('45000000014');

        $this->assertCount(1, $members);
        $this->assertSame('', $members[0]['member_id']);
        $this->assertSame('100001', $members[0]['caller_number']);
        $this->assertSame('Ohi Ibrahim', $members[0]['caller_name']);
        $this->assertSame('c072387b-f140-4f0a-a9da-5d0cc5c31a9f', $members[0]['uuid']);
        $this->assertSame('45000000014-213.199.52.39@default', $members[0]['conference_name']);
    }
}
