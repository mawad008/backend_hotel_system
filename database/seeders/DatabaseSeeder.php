<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(LocationSeeder::class);

        if (! app()->environment('production')) {
            $this->call(Phase1DemoSeeder::class);
            // The Guest App Figma hotels (الواحة / المرسى / النخيل) with
            // their images, rooms and Hotel Detail content.
            $this->call(FigmaHotelsDemoSeeder::class);
            // Review categories, guest reviews + ratings, facilities —
            // fills only what a hotel is missing.
            $this->call(ReviewDemoSeeder::class);
        }
    }
}
