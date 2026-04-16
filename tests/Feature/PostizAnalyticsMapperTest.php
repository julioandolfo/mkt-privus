<?php

namespace Tests\Feature;

use App\Services\Social\Postiz\PostizAnalyticsMapper;
use Tests\TestCase;

class PostizAnalyticsMapperTest extends TestCase
{
    public function test_maps_common_metrics_to_columns(): void
    {
        $analytics = [
            ['label' => 'Followers', 'data' => [['total' => '1200', 'date' => '2026-04-15'], ['total' => '1250', 'date' => '2026-04-16']], 'percentageChange' => 4.1],
            ['label' => 'Impressions', 'data' => [['total' => '8500', 'date' => '2026-04-16']], 'percentageChange' => 12.0],
            ['label' => 'Engagement', 'data' => [['total' => '340', 'date' => '2026-04-16']], 'percentageChange' => 2.0],
            ['label' => 'Likes', 'data' => [['total' => '200', 'date' => '2026-04-16']], 'percentageChange' => 1.0],
            ['label' => 'Comments', 'data' => [['total' => '45', 'date' => '2026-04-16']], 'percentageChange' => 0],
            ['label' => 'Shares', 'data' => [['total' => '12', 'date' => '2026-04-16']], 'percentageChange' => 0],
        ];

        $mapped = PostizAnalyticsMapper::map($analytics);

        $this->assertEquals(1250.0, $mapped['followers_count']);
        $this->assertEquals(8500.0, $mapped['impressions']);
        $this->assertEquals(340.0, $mapped['engagement']);
        $this->assertEquals(200.0, $mapped['likes']);
        $this->assertEquals(45.0, $mapped['comments']);
        $this->assertEquals(12.0, $mapped['shares']);
        $this->assertSame(4.1, $mapped['platform_data']['postiz']['changes']['followers']);
    }

    public function test_unknown_labels_go_to_platform_data(): void
    {
        $analytics = [
            ['label' => 'Followers', 'data' => [['total' => '500', 'date' => '2026-04-16']], 'percentageChange' => 0],
            ['label' => 'Profile Views', 'data' => [['total' => '120', 'date' => '2026-04-16']], 'percentageChange' => 5],
            ['label' => 'Custom Metric', 'data' => [['total' => '77', 'date' => '2026-04-16']], 'percentageChange' => 0],
        ];

        $mapped = PostizAnalyticsMapper::map($analytics);

        $this->assertEquals(500.0, $mapped['followers_count']);
        $this->assertEquals(120.0, $mapped['platform_data']['postiz']['profile_views']);
        $this->assertEquals(77.0, $mapped['platform_data']['postiz']['custom_metric']);
    }

    public function test_normalizes_aliases_into_canonical_columns(): void
    {
        $analytics = [
            ['label' => 'Subscribers', 'data' => [['total' => '10000', 'date' => '2026-04-16']], 'percentageChange' => 0],
            ['label' => 'Link Clicks', 'data' => [['total' => '88', 'date' => '2026-04-16']], 'percentageChange' => 0],
            ['label' => 'Reposts', 'data' => [['total' => '15', 'date' => '2026-04-16']], 'percentageChange' => 0],
        ];

        $mapped = PostizAnalyticsMapper::map($analytics);

        $this->assertEquals(10000.0, $mapped['followers_count']);
        $this->assertEquals(88.0, $mapped['clicks']);
        $this->assertEquals(15.0, $mapped['shares']);
    }

    public function test_parses_abbreviated_numbers(): void
    {
        $analytics = [
            ['label' => 'Followers', 'data' => [['total' => '1.2K', 'date' => '2026-04-16']], 'percentageChange' => 0],
            ['label' => 'Impressions', 'data' => [['total' => '3.5M', 'date' => '2026-04-16']], 'percentageChange' => 0],
        ];

        $mapped = PostizAnalyticsMapper::map($analytics);

        $this->assertEquals(1200.0, $mapped['followers_count']);
        $this->assertEquals(3_500_000.0, $mapped['impressions']);
    }

    public function test_empty_or_invalid_entries_are_skipped(): void
    {
        $analytics = [
            ['label' => '', 'data' => [['total' => '100', 'date' => '2026-04-16']], 'percentageChange' => 0],
            ['label' => 'Followers', 'data' => [], 'percentageChange' => 0],
            ['label' => 'Likes', 'data' => [['total' => 'not-a-number', 'date' => '2026-04-16']], 'percentageChange' => 0],
            ['label' => 'Comments', 'data' => [['total' => '10', 'date' => '2026-04-16']], 'percentageChange' => 0],
        ];

        $mapped = PostizAnalyticsMapper::map($analytics);

        $this->assertArrayNotHasKey('followers_count', $mapped);
        $this->assertArrayNotHasKey('likes', $mapped);
        $this->assertEquals(10.0, $mapped['comments']);
    }
}
