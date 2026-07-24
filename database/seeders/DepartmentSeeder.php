<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * A small nested department tree for Abu Saleem municipality.
     */
    public function run(): void
    {
        $root = Department::updateOrCreate(
            ['code' => 'ABS'],
            ['name_ar' => 'بلدية أبو سليم', 'name_en' => 'Abu Saleem Municipality', 'parent_id' => null],
        );

        $children = [
            ['code' => 'ADM', 'name_ar' => 'إدارة الشؤون الإدارية', 'name_en' => 'Administrative Affairs'],
            ['code' => 'ENG', 'name_ar' => 'إدارة الهندسة والمشاريع', 'name_en' => 'Engineering & Projects'],
            ['code' => 'FIN', 'name_ar' => 'إدارة الشؤون المالية', 'name_en' => 'Financial Affairs'],
            ['code' => 'REP', 'name_ar' => 'مكتب المقرر', 'name_en' => 'Reviewer Office'],
            ['code' => 'CMT', 'name_ar' => 'لجنة شؤون الموظفين', 'name_en' => 'Staff Affairs Committee'],
        ];

        foreach ($children as $child) {
            Department::updateOrCreate(
                ['code' => $child['code']],
                $child + ['parent_id' => $root->id],
            );
        }
    }
}
