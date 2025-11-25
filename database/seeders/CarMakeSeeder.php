<?php

namespace Database\Seeders;

use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Database\Seeder;

class CarMakeSeeder extends Seeder
{
    public function run(): void
    {
        $makes = [
            'Toyota' => ['Corolla', 'Camry', 'RAV4', 'Hilux'],
            'Honda' => ['Civic', 'Accord', 'CR-V', 'Jazz'],
            'Tesla' => ['Model 3', 'Model Y', 'Model S', 'Model X'],
            'Ford' => ['Mustang', 'F-150', 'Focus', 'Explorer'],
            'BMW' => ['3 Series', '5 Series', 'X3', 'X5'],
            'Mercedes-Benz' => ['A-Class', 'C-Class', 'E-Class', 'GLC'],
            'Nissan' => ['Altima', 'Sentra', 'X-Trail'],
            'Hyundai' => ['Elantra', 'Tucson', 'Santa Fe'],
            'Kia' => ['Sportage', 'Sorento', 'Rio'],
            'Volkswagen' => ['Golf', 'Passat', 'Tiguan'],
        ];

        foreach ($makes as $makeName => $models) {
            $make = CarMake::firstOrCreate(['name' => $makeName]);

            foreach ($models as $modelName) {
                CarModel::firstOrCreate([
                    'car_make_id' => $make->id,
                    'name' => $modelName,
                ]);
            }
        }
    }
}
