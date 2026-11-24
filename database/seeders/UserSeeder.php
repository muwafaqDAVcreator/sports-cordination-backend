<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin account
        User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@example.com',
            'phone'    => '0771234567',
            'password' => Hash::make('password123'),
            'role'     => 'admin',
            'house'    => null,
        ]);

        // Coordinator accounts — one per house
        $coordinators = [
            ['name' => 'Muwafaq',   'email' => 'muwa@example.com',  'house' => 'Red'],
            ['name' => 'Coordinator Blue',  'email' => 'blue@example.com',  'house' => 'Blue'],
            ['name' => 'Coordinator Green', 'email' => 'green@example.com', 'house' => 'Green'],
            ['name' => 'Coordinator Yellow','email' => 'yellow@example.com','house' => 'Yellow'],
        ];

        foreach ($coordinators as $data) {
            User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'phone'    => '077000000' . array_search($data, $coordinators),
                'password' => Hash::make('password123'),
                'role'     => 'coordinator',
                'house'    => $data['house'],
            ]);
        }
    }
}
