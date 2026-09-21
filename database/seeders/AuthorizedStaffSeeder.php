<?php
// database/seeders/AuthorizedStaffSeeder.php
//
// Seeds the real roster of Federal Polytechnic Ede staff authorized to
// register for the ASUP CICS cooperative. This is genuine reference data
// provided by the cooperative — not demo/placeholder data — and none of
// these rows become actual members until each person registers for real.

namespace Database\Seeders;

use App\Models\AuthorizedStaff;
use Illuminate\Database\Seeder;

class AuthorizedStaffSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [
            ['ASUP/001', 'Lawrence Longe'],
            ['ASUP/002', 'Azeez Rofiat'],
            ['ASUP/003', 'Fadare Samuel'],
            ['ASUP/004', 'Alabi Islamiat'],
            ['ASUP/005', 'Ajayi Sodiq'],
            ['ASUP/006', 'Adegboyega Ebenezer'],
            ['ASUP/007', 'Olaniyan Ameedat'],
            ['ASUP/008', 'Adigun Dorcas'],
            ['ASUP/009', 'Adetoro Christianah'],
            ['ASUP/010', 'Eze Samuel'],
            ['ASUP/011', 'Ayeni Isreal'],
            ['ASUP/012', 'Oladimeji Fatimoh'],
            ['ASUP/013', 'Qosim Jamaldeen'],
            ['ASUP/014', 'Banjo Korede'],
            ['ASUP/015', 'Akinyo Phaeez'],
            ['ASUP/016', 'Ogunfusika Isreal'],
            ['ASUP/017', 'Owolabi Blessing'],
            ['ASUP/018', 'Adeyemi Abdulazeez'],
            ['ASUP/019', 'Omoba Vincent'],
            ['ASUP/020', 'Egbewumi Samson'],
        ];

        foreach ($staff as [$staffId, $name]) {
            AuthorizedStaff::updateOrCreate(
                ['staff_id' => $staffId],
                ['full_name' => $name, 'is_registered' => false]
            );
        }
    }
}
