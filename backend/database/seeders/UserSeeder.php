<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development accounts (brief §AK). Password: "Password@26" — NOT for production.
 * Login accepts username OR email.
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'Password@26';

    public function run(): void
    {
        $sda = Site::where('code', 'SIG-SDA')->first();
        $bpn = Site::where('code', 'SIG-BPN')->first();

        $accounts = [
            ['username' => 'superadmin', 'email' => 'superadmin@gmail.com', 'name' => 'Super Admin', 'roles' => ['super_admin'], 'site' => $sda],
            ['username' => 'admingudang', 'email' => 'admingudang@gmail.com', 'name' => 'Admin Gudang', 'roles' => ['admin_gudang'], 'site' => $sda],
            ['username' => 'adminlapangan', 'email' => 'adminlapangan@gmail.com', 'name' => 'Admin Lapangan', 'roles' => ['lapangan_gudang'], 'site' => $sda],
            ['username' => 'purchasing', 'email' => 'purchasing@gmail.com', 'name' => 'Purchasing', 'roles' => ['purchasing'], 'site' => $sda],
            ['username' => 'bos', 'email' => 'bos@gmail.com', 'name' => 'BOS / Management', 'roles' => ['bos'], 'site' => $sda],
            ['username' => 'kariawan', 'email' => 'kariawan@gmail.com', 'name' => 'Karyawan', 'roles' => ['karyawan'], 'site' => $sda],
            // extra roles not named by the client, kept for testing
            ['username' => 'anakgudang1', 'email' => 'anakgudang1@gmail.com', 'name' => 'Anak Gudang 1', 'roles' => ['anak_gudang'], 'site' => $sda],
            ['username' => 'anakgudang2', 'email' => 'anakgudang2@gmail.com', 'name' => 'Anak Gudang 2', 'roles' => ['anak_gudang'], 'site' => $sda],
            ['username' => 'karyawanbpn', 'email' => 'karyawanbpn@gmail.com', 'name' => 'Karyawan Balikpapan', 'roles' => ['karyawan'], 'site' => $bpn],
        ];

        $roles = Role::pluck('id', 'slug');

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => Hash::make(self::PASSWORD),
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
