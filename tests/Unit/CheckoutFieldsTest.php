<?php
namespace ZeroSense\Tests\Unit;

use Mockery;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use ZeroSense\Features\WooCommerce\Checkout\Components\CheckoutFields;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Test;

class CheckoutFieldsTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_normalizes_service_area_id_to_default_language_on_save()
    {
        // ARRANGE
        // Mock $_POST data simulating an English checkout submission
        $_POST['event_service_location'] = '192'; // English Term ID

        // Mock WPML filters
        Filters\expectApplied('wpml_default_language')
            ->once()
            ->andReturn('es');

        // Mock wpml_object_id to translate ID 192 (EN) -> 187 (ES)
        Filters\expectApplied('wpml_object_id')
            ->once()
            ->with('192', 'service-area', true, 'es')
            ->andReturn(187);

        // Mock generic filters run in save_meta_box_fields
        Filters\expectApplied('rwmb_meta_boxes')
            ->andReturn([
                [
                    'post_types' => ['shop_order'],
                    'fields' => [
                        [
                            'id' => 'event_service_location',
                            'type' => 'taxonomy',
                            'taxonomy' => 'service-area'
                        ]
                    ]
                ]
            ]);

        // Mock get_term to identify taxonomy if needed (though our code prefers field config now)
        Functions\stubs([
            'sanitize_text_field' => function ($v) {
                return $v;
            },
            'is_wp_error' => false,
            'wc_get_logger' => function () {
                return Mockery::mock(['debug' => true]);
            }
        ]);

        // EXPECTATION
        // Check if update_post_meta is called with the NORMALIZED ID (187) instead of 192
        Functions\expect('update_post_meta')
            ->once()
            ->with(12345, 'event_service_location', 187);

        // ACT
        $instance = new CheckoutFields();
        $instance->save_meta_box_fields(12345);
        $this->assertTrue(true); // Silence risky warning
    }

    #[Test]
    public function it_handles_array_taxonomy_from_metabox_config_robustly()
    {
        // ARRANGE
        $_POST['event_service_location'] = '192';

        Filters\expectApplied('wpml_default_language')->andReturn('es');

        // Verify that even if taxonomy is passed as array ['service-area'], we convert it to string 'service-area'
        Filters\expectApplied('wpml_object_id')
            ->once()
            ->with('192', 'service-area', true, 'es')
            ->andReturn(187);

        Filters\expectApplied('rwmb_meta_boxes')
            ->andReturn([
                [
                    'post_types' => ['shop_order'],
                    'fields' => [
                        [
                            'id' => 'event_service_location',
                            'type' => 'taxonomy',
                            'taxonomy' => ['service-area'] // Array configuration
                        ]
                    ]
                ]
            ]);

        Functions\stubs([
            'sanitize_text_field' => function ($v) {
                return $v;
            },
            'is_wp_error' => false
        ]);

        Functions\expect('update_post_meta')
            ->once()
            ->with(12345, 'event_service_location', 187);

        // ACT
        $instance = new CheckoutFields();
        $instance->save_meta_box_fields(12345);
        $this->assertTrue(true); // Silence risky warning
    }
}
