<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Unit;

use App\DataMapper\CompanySettings;
use App\Services\Pdf\PdfMock;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * 
 */
class PdfVariablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = CompanySettings::defaults();
    }

    public function testPdfVariableDefaults()
    {
        $this->assertTrue(is_array($this->settings->pdf_variables->client_details));
    }

    public function testDefaultPdfColumnsHaveTranslatedMockLabels(): void
    {
        $reflection = new ReflectionClass(PdfMock::class);
        $mock = $reflection->newInstanceWithoutConstructor();
        $labels = $reflection->getMethod('mockTranslatedLabels')->invoke($mock);

        foreach ((array) $this->settings->pdf_variables as $name => $columns) {
            if (!str_ends_with($name, '_columns')) {
                continue;
            }

            foreach (array_diff((array) $columns, ['$total_taxes', '$line_taxes']) as $column) {
                self::assertArrayHasKey("{$column}_label", $labels, $name);
                self::assertNotSame('', $labels["{$column}_label"], $name);
            }
        }
    }

    #[DataProvider('coefficientLocales')]
    public function testCoefficientColumnsAreTranslated(string $locale): void
    {
        foreach (['time_coefficient', 'time_coefficient_name'] as $key) {
            self::assertTrue(Lang::hasForLocale("texts.{$key}", 'en'));
            self::assertTrue(Lang::hasForLocale("texts.{$key}", $locale));
            self::assertNotSame(trans("texts.{$key}", [], 'en'), trans("texts.{$key}", [], $locale));
        }
    }

    public static function coefficientLocales(): iterable
    {
        yield 'French' => ['fr'];
        yield 'Swiss French' => ['fr_CH'];
        yield 'German' => ['de'];
        yield 'Italian' => ['it'];
    }
}
