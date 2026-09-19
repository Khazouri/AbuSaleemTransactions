<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter department tree for the municipality.
 *
 *   بلدية أبو سليم (ABS)              <- root
 *     ├─ إدارة الشؤون الإدارية (ADM)
 *     ├─ إدارة الهندسة والمشاريع (ENG)
 *     ├─ إدارة الشؤون المالية (FIN)
 *     ├─ مكتب المقرر (REP)
 *     ├─ لجنة شؤون الموظفين (CMT)
 *     ├─ قسم المرتبات والمزايا (SAL)
 *     └─ إدارة الموارد البشرية (HR)
 *
 * These are a usable starting point, not a fixed structure — departments are
 * fully editable from the UI in Stage 6. The codes matter because they appear
 * in request reference numbers (YYYY-DEPT-000123).
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // Root first — the children need its id for their parent_id.
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
            // Stage 47 — a distinct admin unit from FIN (الشؤون المالية):
            // [A] §1's org chart lists المرتبات والمزايا as its own sibling
            // unit alongside شؤون الموظفين, not a subset of financial affairs.
            ['code' => 'SAL', 'name_ar' => 'قسم المرتبات والمزايا', 'name_en' => 'Salaries & Benefits'],
            // Stage 87 — the same reasoning: [F] names إدارة الموارد البشرية
            // as its own body, distinct from ADM (إدارة الشؤون الإدارية,
            // R05's department). Reusing ADM for R12 would blur exactly the
            // distinction this stage draws between the two roles.
            ['code' => 'HR', 'name_ar' => 'إدارة الموارد البشرية', 'name_en' => 'Human Resources'],
        ];

        foreach ($children as $child) {
            Department::updateOrCreate(
                ['code' => $child['code']],
                // Merge the parent link into each child's attributes.
                $child + ['parent_id' => $root->id],
            );
        }
    }
}
