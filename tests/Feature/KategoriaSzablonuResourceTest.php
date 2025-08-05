<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Spatie\Permission\Models\Role::findOrCreate('admin');
});

describe('KategoriaSzablonuResource', function () {
    it('blokuje dostęp bez uprawnień', function () {
        $response = $this->get('/admin/kategorie-szablonow');
        $response->assertStatus(403);
    });

    it('pozwala adminowi na dostęp', function () {
        $user = \App\Models\User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
        $response = $this->get('/admin/kategorie-szablonow');
        $response->assertStatus(200);
    });

    it('pozwala użytkownikowi z uprawnieniem view kategoria_szablonu', function () {
        $user = \App\Models\User::factory()->create();
        $permission = \Spatie\Permission\Models\Permission::findOrCreate('view kategoria_szablonu');
        $user->givePermissionTo($permission);
        $this->actingAs($user);
        $response = $this->get('/admin/kategorie-szablonow');
        $response->assertStatus(200);
    });
});
