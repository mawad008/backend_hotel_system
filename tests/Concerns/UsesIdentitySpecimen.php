<?php

namespace Tests\Concerns;

use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;

/**
 * The claim fields matching the dummy document provider's SYNTHETIC specimen
 * passport (fictional ICAO "Utopia" holder) — never a real person's data.
 */
trait UsesIdentitySpecimen
{
    /** @return array<string, string> */
    protected function specimenClaimFields(): array
    {
        $s = DummyIdentityDocumentProvider::SPECIMEN;

        return [
            'full_name' => $s['given'].' '.$s['surname'],
            'document_number' => $s['document_number'],
            'date_of_birth' => $s['date_of_birth'],
        ];
    }
}
