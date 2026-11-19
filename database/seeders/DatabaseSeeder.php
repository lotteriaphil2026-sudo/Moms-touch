<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Intentionally empty in every environment. Accounts start with zero wallets.
        // Create an administrator privately with `php artisan portal:create-admin`.
        // Never reset existing balances or ledger history during deployment.
    }
}
