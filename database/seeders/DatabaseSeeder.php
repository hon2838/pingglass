<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MonitorSetting;
use App\Models\Target;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@hon2838.name.my',
            'password' => Hash::make('password12345'),
        ]);

        $this->seedSettings();
        $this->seedSampleData();
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'probe_interval', 'value' => '60', 'type' => 'int'],
            ['key' => 'icmp_samples', 'value' => '10', 'type' => 'int'],
            ['key' => 'tcp_samples', 'value' => '10', 'type' => 'int'],
            ['key' => 'icmp_timeout', 'value' => '2000', 'type' => 'int'],
            ['key' => 'tcp_timeout', 'value' => '2000', 'type' => 'int'],
            ['key' => 'loss_threshold_percent', 'value' => '10', 'type' => 'float'],
            ['key' => 'latency_threshold_ms', 'value' => '200', 'type' => 'float'],
            ['key' => 'raw_retention_days', 'value' => '30', 'type' => 'int'],
            ['key' => 'rollup5m_retention_days', 'value' => '180', 'type' => 'int'],
            ['key' => 'down_confirmation_cycles', 'value' => '3', 'type' => 'int'],
            ['key' => 'recovery_confirmation_cycles', 'value' => '2', 'type' => 'int'],
        ];

        foreach ($settings as $setting) {
            MonitorSetting::create($setting);
        }
    }

    private function seedSampleData(): void
    {
        $infra = Category::create([
            'name' => 'Core Infrastructure',
            'slug' => 'core-infra',
            'description' => 'User Server Fleet',
            'is_public' => true,
            'is_enabled' => true,
            'sort_order' => 10,
        ]);

        $dns = Category::create([
            'name' => 'Global DNS',
            'slug' => 'global-dns',
            'description' => 'Public DNS Resolvers',
            'is_public' => true,
            'is_enabled' => true,
            'sort_order' => 20,
        ]);

        $targets = [
            ['category_id' => $infra->id, 'name' => 'HON1 Server', 'slug' => 'hon1-server', 'host' => '140.83.58.143', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 22, 'sort_order' => 10],
            ['category_id' => $infra->id, 'name' => 'HON2 Server', 'slug' => 'hon2-server', 'host' => '140.83.86.233', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 22, 'sort_order' => 20],
            ['category_id' => $infra->id, 'name' => 'HON2838 Main', 'slug' => 'hon2838-main', 'host' => 'hon2838s.hon2838.name.my', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 80, 'sort_order' => 30],
            ['category_id' => $dns->id, 'name' => 'Cloudflare DNS', 'slug' => 'cloudflare-dns', 'host' => '1.1.1.1', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 53, 'sort_order' => 10],
            ['category_id' => $dns->id, 'name' => 'Google DNS', 'slug' => 'google-dns', 'host' => '8.8.8.8', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 53, 'sort_order' => 20],
        ];

        foreach ($targets as $data) {
            Target::create(array_merge($data, [
                'show_host_publicly' => true,
                'is_public' => true,
                'is_enabled' => true,
            ]));
        }
    }
}
