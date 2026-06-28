<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $devices = Device::with('user')->paginate(20);

        return $this->paginatedResponse($devices);
    }

    public function trust(Device $device)
    {
        $device->update([
            'is_trusted' => true,
            'trusted_at' => now(),
        ]);

        AuditService::log('device_trusted', Device::class, $device->id);

        return $this->successResponse($device->load('user'), 'Device trusted');
    }

    public function untrust(Device $device)
    {
        $device->update([
            'is_trusted' => false,
            'trusted_at' => null,
        ]);

        AuditService::log('device_untrusted', Device::class, $device->id);

        return $this->successResponse($device->load('user'), 'Device untrusted');
    }
}
