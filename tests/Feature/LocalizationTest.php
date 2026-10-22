<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_portal_navigation_labels_have_locale_translations(): void
    {
        $labels = ['Compliance', 'Your organisation'];
        $locales = ['en', 'fr', 'es', 'de', 'nl'];

        foreach ($locales as $locale) {
            App::setLocale($locale);

            foreach ($labels as $label) {
                $translated = trans($label);

                if ($locale === 'en') {
                    $this->assertSame($label, $translated, sprintf('%s should remain unchanged for English.', $label));
                } else {
                    $this->assertNotSame($label, $translated, sprintf('%s should be translated for %s.', $label, $locale));
                }
            }
        }
    }
}
