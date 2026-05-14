<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CiudadSeeder extends Seeder
{
    public function run(): void
    {
        $ciudades = [
            ['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05002', 'nombre' => 'Abejorral', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05004', 'nombre' => 'Abriaquí', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05021', 'nombre' => 'Alejandría', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05030', 'nombre' => 'Amagá', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05031', 'nombre' => 'Amalfi', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '11001', 'nombre' => 'Bogotá D.C.', 'departamento' => 'Bogotá D.C.'],
            ['cod_municipio' => '08001', 'nombre' => 'Barranquilla', 'departamento' => 'Atlántico'],
            ['cod_municipio' => '76001', 'nombre' => 'Cali', 'departamento' => 'Valle del Cauca'],
            ['cod_municipio' => '13001', 'nombre' => 'Cartagena', 'departamento' => 'Bolívar'],
            ['cod_municipio' => '05088', 'nombre' => 'Barbosa', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05101', 'nombre' => 'Belmira', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05107', 'nombre' => 'Bello', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05113', 'nombre' => 'Betania', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '05120', 'nombre' => 'Betulia', 'departamento' => 'Antioquia'],
            ['cod_municipio' => '68001', 'nombre' => 'Bucaramanga', 'departamento' => 'Santander'],
            ['cod_municipio' => '54001', 'nombre' => 'Cúcuta', 'departamento' => 'Norte de Santander'],
            ['cod_municipio' => '41001', 'nombre' => 'Neiva', 'departamento' => 'Huila'],
            ['cod_municipio' => '23001', 'nombre' => 'Montería', 'departamento' => 'Córdoba'],
            ['cod_municipio' => '63001', 'nombre' => 'Armenia', 'departamento' => 'Quindío'],
            ['cod_municipio' => '76109', 'nombre' => 'Buenaventura', 'departamento' => 'Valle del Cauca'],
            ['cod_municipio' => '73001', 'nombre' => 'Ibagué', 'departamento' => 'Tolima'],
            ['cod_municipio' => '66001', 'nombre' => 'Pereira', 'departamento' => 'Risaralda'],
            ['cod_municipio' => '17001', 'nombre' => 'Manizales', 'departamento' => 'Caldas'],
            ['cod_municipio' => '85001', 'nombre' => 'Yopal', 'departamento' => 'Casanare'],
        ];

        foreach ($ciudades as $ciudad) {
            DB::table('ciudades')->updateOrInsert(
                ['cod_municipio' => $ciudad['cod_municipio']],
                array_merge($ciudad, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
