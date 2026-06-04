<?php

namespace Modules\CRM\Actions;

use Modules\CRM\Models\Contacto;

class AssignScoreAction
{
    /**
     * Calculate and assign score to a Contacto.
     *
     * @param Contacto $contacto
     * @return int The calculated score
     */
    public function execute(Contacto $contacto): int
    {
        $score = 10; // Base score

        // 1. Cargo check (+30)
        if ($contacto->cargo) {
            $cargoLower = mb_strtolower($contacto->cargo);
            $highValueRoles = ['ceo', 'gerente', 'director', 'director general', 'fundador', 'chief', 'executive', 'president', 'vicepresident', 'vp'];
            foreach ($highValueRoles as $role) {
                if (str_contains($cargoLower, $role)) {
                    $score += 30;
                    break;
                }
            }
        }

        // 2. Fuente/Canal check (+20)
        if ($contacto->fuente) {
            $fuenteLower = mb_strtolower($contacto->fuente);
            $highValueFuentes = ['web', 'formulario', 'recomendado'];
            foreach ($highValueFuentes as $fuente) {
                if (str_contains($fuenteLower, $fuente)) {
                    $score += 20;
                    break;
                }
            }
        }

        // 3. Corporate email check (+20)
        if ($contacto->email_contacto) {
            $emailParts = explode('@', $contacto->email_contacto);
            if (count($emailParts) === 2) {
                $domain = mb_strtolower($emailParts[1]);
                $freeEmailProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'aol.com'];
                if (!in_array($domain, $freeEmailProviders)) {
                    $score += 20;
                }
            }
        }

        // Clamp score between 0 and 100
        $score = max(0, min(100, $score));

        // Save score to contacto database
        $contacto->score = $score;
        $contacto->save();

        return $score;
    }
}
