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
            'email' => 'admin@pingglass.local',
            'password' => Hash::make('password'),
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
        $shanghai = Category::create([
            'name' => '上海',
            'slug' => 'shanghai',
            'description' => 'Shanghai network targets',
            'is_public' => true,
            'is_enabled' => true,
            'sort_order' => 10,
        ]);

        $beijing = Category::create([
            'name' => '北京',
            'slug' => 'beijing',
            'description' => 'Beijing network targets',
            'is_public' => true,
            'is_enabled' => true,
            'sort_order' => 20,
        ]);

        $guangdong = Category::create([
            'name' => '广东',
            'slug' => 'guangdong',
            'description' => 'Guangdong network targets',
            'is_public' => true,
            'is_enabled' => true,
            'sort_order' => 30,
        ]);

        $targets = [
            ['category_id' => $shanghai->id, 'name' => '上海电信', 'slug' => 'shanghai-telecom', 'host' => '124.74.52.254', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 65499, 'sort_order' => 10],
            ['category_id' => $shanghai->id, 'name' => '上海联通', 'slug' => 'shanghai-unicom', 'host' => '112.65.18.154', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 65499, 'sort_order' => 20],
            ['category_id' => $shanghai->id, 'name' => '上海移动', 'slug' => 'shanghai-mobile', 'host' => '117.131.0.1', 'icmp_enabled' => true, 'tcp_enabled' => false, 'sort_order' => 30],
            ['category_id' => $beijing->id, 'name' => '北京电信', 'slug' => 'beijing-telecom', 'host' => '223.72.1.1', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 443, 'sort_order' => 10],
            ['category_id' => $beijing->id, 'name' => '北京联通', 'slug' => 'beijing-unicom', 'host' => '123.125.81.6', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 443, 'sort_order' => 20],
            ['category_id' => $beijing->id, 'name' => '北京移动', 'slug' => 'beijing-mobile', 'host' => '221.130.33.1', 'icmp_enabled' => true, 'tcp_enabled' => false, 'sort_order' => 30],
            ['category_id' => $guangdong->id, 'name' => '广州电信', 'slug' => 'guangzhou-telecom', 'host' => '14.215.116.1', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 80, 'sort_order' => 10],
            ['category_id' => $guangdong->id, 'name' => '广州联通', 'slug' => 'guangzhou-unicom', 'host' => '221.5.88.1', 'icmp_enabled' => true, 'tcp_enabled' => true, 'tcp_port' => 80, 'sort_order' => 20],
            ['category_id' => $guangdong->id, 'name' => '广州移动', 'slug' => 'guangzhou-mobile', 'host' => '211.136.192.1', 'icmp_enabled' => true, 'tcp_enabled' => false, 'sort_order' => 30],
        ];

        foreach ($targets as $data) {
            Target::create(array_merge($data, [
                'show_host_publicly' => false,
                'is_public' => true,
                'is_enabled' => true,
            ]));
        }
    }
}
