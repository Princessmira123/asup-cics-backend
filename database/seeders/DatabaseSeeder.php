<?php
// database/seeders/DatabaseSeeder.php
//
// This project deliberately ships with NO demo/fake members, admins, or
// loans — every account in the real database should come from someone
// actually registering, or from an admin being created deliberately via
// tinker. The only legitimate seed data is the authorized staff roster
// below, which represents real Federal Polytechnic Ede staff who are
// allowed to register — not fabricated member accounts.

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AuthorizedStaffSeeder::class,
        ]);

        $this->command->info('Authorized staff roster seeded. No demo members, admins, or loans were created.');
    }
}
