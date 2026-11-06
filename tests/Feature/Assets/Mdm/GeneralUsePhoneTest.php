<?php

namespace Tests\Feature\Assets\Mdm;

use App\Livewire\Assets\Mdm\DeviceDetail;
use App\Livewire\Assets\Mdm\DevicesDashboard;
use App\Livewire\Assets\PhonesList;
use App\Models\IctAsset;
use App\Models\MdmDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

/** A phone enrolled in MDM can be marked general use (a shared device) without breaking its MDM screens or link. */
class GeneralUsePhoneTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
        Queue::fake();
    }

    public function test_an_enrolled_phone_marked_general_use_keeps_its_device_and_the_mdm_screens_show_general_use(): void
    {
        $district = $this->district();
        $holder = $this->employeeIn($district, '30001', 'Old Holder');
        $phone = $this->phone($district, ['assigned_to_employee_id' => $holder->id]);
        $device = MdmDevice::factory()->forAsset($phone)->create();
        $actor = $this->superAdmin($district);

        Livewire::actingAs($actor)->test(PhonesList::class)
            ->call('openEdit', $phone->id)
            ->set('form.is_general_use', true)
            ->call('save')
            ->assertHasNoErrors();

        $phone->refresh();
        $this->assertTrue($phone->is_general_use);
        $this->assertNull($phone->assigned_to_employee_id);
        $this->assertSame($phone->id, $device->fresh()->ict_asset_id, 'the MDM link is untouched');

        Livewire::actingAs($actor)->test(DevicesDashboard::class)->assertSee('General use');
        Livewire::actingAs($actor)->test(DeviceDetail::class, ['deviceId' => $device->id])->assertSee('General use');
    }
}
