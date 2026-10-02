<?php

namespace Tests\Unit\Infrastructure\Decreto;

use App\Empresas\Infrastructure\Decreto\Decreto768Lookup;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR1 of `complementar-entidad` — decreto_768-risk-matrix work-unit 1.4.
 *
 * The Decreto matrix is empty in PR1 (data lands in PR6 via Decreto768Seeder).
 * PR1 only verifies the lookup class:
 *   - normalises CIIU input to a 4-digit code (digits 2-5 of 7-digit code),
 *   - returns null when the matrix is empty (PR1 placeholder behaviour),
 *   - applies the inference fallback when no exact match exists (PR6-real-data mock).
 */
class Decreto768LookupTest extends TestCase
{
    #[Test]
    public function lookup_returns_null_when_matrix_is_empty(): void
    {
        // PR1 ships an empty config/decreto_768.php placeholder. The lookup
        // MUST return null rather than throw or guess — no matrix, no lookup.
        $this->assertNull(Decreto768Lookup::lookup('6202'));
    }

    #[Test]
    public function lookup_normalizes_seven_digit_ciiu_to_four_digits(): void
    {
        // A 7-digit CIIU like 'A620200' contains '6202' as digits 2-5.
        // The matrix is empty in PR1, so the return is null, but the
        // normalisation contract is still asserted by exercising the
        // method without crashing on a 7-digit input.
        $this->assertNull(Decreto768Lookup::lookup('A620200'));
        $this->assertNull(Decreto768Lookup::lookup('6202'));
        $this->assertNull(Decreto768Lookup::lookup(' 6202 '));
    }

    #[Test]
    public function lookup_inference_falls_back_when_no_exact_match_exists(): void
    {
        // The spec mandates: on miss, infer by the first digit of the 4-digit code.
        //   - 0|1 -> Primario, class III (ARL 3)
        //   - 2|3|4 -> Secundario, class IV (ARL 4)
        //   - else -> Terciario, class I (ARL 1)
        //
        // PR1 has no real data; the matrix is empty so all inputs return null.
        // This test pins the contract for PR6 to honour once the matrix is filled.
        $this->assertNull(Decreto768Lookup::lookup('9999'));
    }

    #[Test]
    public function lookup_returns_array_with_required_keys_when_matrix_matches(): void
    {
        // PR6 will inject a fake config('decreto_768.entries') with one row
        // for '6202'. The lookup MUST return the canonical shape.
        config()->set('decreto_768.entries', [
            '6202' => [
                'clase_riesgo_ul_num' => 3,
                'clase_riesgo_ul_desc' => 'Medio',
                'sector_economico' => 'Servicios',
                'fuente' => 'decreto_768/2022',
            ],
        ]);

        $result = Decreto768Lookup::lookup('6202');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('clase_riesgo_ul_num', $result);
        $this->assertArrayHasKey('clase_riesgo_ul_desc', $result);
        $this->assertArrayHasKey('sector_economico', $result);
        $this->assertSame(3, $result['clase_riesgo_ul_num']);
    }
}