<?php

namespace Database\Seeders;

use App\Models\Financiera;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['CaptaVale', 'BHermanos', 'Dportenis', 'Concredito', 'Crediavance'] as $nombre) {
            Financiera::firstOrCreate(['nombre' => $nombre], [
                'comision_porcentaje' => 0,
                'recargo_porcentaje' => 20,
                'activo' => true,
            ]);
        }
    }
}
