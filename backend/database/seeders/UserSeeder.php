<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development accounts (brief §AK). Password: "password" — NOT for production.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $sda = Site::where('code', 'SIG-SDA')->first();
        $bpn = Site::where('code', 'SIG-BPN')->first();

        $accounts = [
            ['username' => 'superadmin', 'name' => 'Super Admin', 'roles' => ['super_admin'], 'site' => $sda],
            ['username' => 'admingudang', 'name' => 'Admin Gudang', 'roles' => ['admin_gudang'], 'site' => $sda],
            ['username' => 'anakgudang1', 'name' => 'Anak Gudang 1', 'roles' => ['anak_gudang'], 'site' => $sda],
            ['username' => 'anakgudang2', 'name' => 'Anak Gudang 2', 'roles' => ['anak_gudang'], 'site' => $sda],
            ['username' => 'lapangan1', 'name' => 'Lapangan Gudang 1', 'roles' => ['lapangan_gudang'], 'site' => $sda],
            ['username' => 'lapangan2', 'name' => 'Lapangan Gudang 2', 'roles' => ['lapangan_gudang'], 'site' => $sda],
            ['username' => 'purchasing', 'name' => 'Purchasing', 'roles' => ['purchasing'], 'site' => $sda],
            ['username' => 'bos', 'name' => 'BOS / Management', 'roles' => ['bos'], 'site' => $sda],
            ['username' => 'karyawan1', 'name' => 'Karyawan Satu', 'roles' => ['karyawan'], 'site' => $sda],
            ['username' => 'karyawan2', 'name' => 'Karyawan Dua', 'roles' => ['karyawan'], 'site' => $sda],
            ['username' => 'karyawanbpn', 'name' => 'Karyawan Balikpapan', 'roles' => ['karyawan'], 'site' => $bpn],
        ];

        $roles = Role::pluck('id', 'slug');

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'email' => $account['username'].'@stockwise.local',
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'site_id' => $account['site']?->id,
                ],
            );

            $user->roles()->sync(
                collect($account['roles'])->map(fn ($slug) => $roles[$slug])->all()
            );
        }
    }
}
