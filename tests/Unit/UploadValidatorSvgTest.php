<?php

namespace Tests\Unit;

use App\Support\UploadValidator;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class UploadValidatorSvgTest extends TestCase
{
    private function svgFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);
    }

    public function test_entity_encoded_javascript_href_is_neutralized(): void
    {
        $file = $this->svgFile(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<a xlink:href="&#x6a;avascript:alert(1)"><text>x</text></a>'
            .'<script>alert(2)</script>'
            .'</svg>'
        );

        $method = new ReflectionMethod(UploadValidator::class, 'sanitizeSvg');
        $method->invoke(null, $file);

        $sanitized = (string) file_get_contents($file->getRealPath());
        $decoded = html_entity_decode($sanitized, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringNotContainsStringIgnoringCase('javascript', $decoded);
        $this->assertStringNotContainsStringIgnoringCase('<script', $decoded);
    }

    public function test_event_handlers_and_foreign_objects_are_stripped(): void
    {
        $file = $this->svgFile(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            .'<rect onload="alert(1)" onclick="alert(2)" width="1" height="1"/>'
            .'<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(3)</script></body></foreignObject>'
            .'</svg>'
        );

        $method = new ReflectionMethod(UploadValidator::class, 'sanitizeSvg');
        $method->invoke(null, $file);

        $sanitized = (string) file_get_contents($file->getRealPath());

        $this->assertStringNotContainsStringIgnoringCase('onload', $sanitized);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $sanitized);
        $this->assertStringNotContainsStringIgnoringCase('foreignObject', $sanitized);
        $this->assertStringNotContainsStringIgnoringCase('<script', $sanitized);
    }

    public function test_legitimate_svg_content_survives(): void
    {
        $file = $this->svgFile(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            .'<circle cx="5" cy="5" r="4" fill="#0f766e"/>'
            .'<a href="#anchor"><text x="1" y="9">Logo</text></a>'
            .'</svg>'
        );

        $method = new ReflectionMethod(UploadValidator::class, 'sanitizeSvg');
        $method->invoke(null, $file);

        $sanitized = (string) file_get_contents($file->getRealPath());

        $this->assertStringContainsString('<circle', $sanitized);
        $this->assertStringContainsString('#anchor', $sanitized);
    }
}
