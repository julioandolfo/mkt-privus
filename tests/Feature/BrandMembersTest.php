<?php

namespace Tests\Feature;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandMembersTest extends TestCase
{
    use RefreshDatabase;

    private function brandWithOwner(): array
    {
        $owner = User::factory()->create();
        $brand = Brand::factory()->create();
        $brand->users()->attach($owner->id, ['role' => BrandRole::Owner->value]);
        $owner->update(['current_brand_id' => $brand->id]);

        return [$owner, $brand];
    }

    private function addMember(Brand $brand, BrandRole $role): User
    {
        $member = User::factory()->create();
        $brand->users()->attach($member->id, ['role' => $role->value]);
        $member->update(['current_brand_id' => $brand->id]);

        return $member;
    }

    public function test_owner_can_change_member_role(): void
    {
        [$owner, $brand] = $this->brandWithOwner();
        $member = $this->addMember($brand, BrandRole::Editor);

        $this->actingAs($owner)
            ->put(route('brands.members.update', [$brand->id, $member->id]), ['role' => 'viewer'])
            ->assertRedirect();

        $this->assertSame(BrandRole::Viewer, $member->fresh()->roleInBrand($brand));
    }

    public function test_owner_role_cannot_be_changed_or_removed(): void
    {
        [$owner, $brand] = $this->brandWithOwner();

        $this->actingAs($owner)
            ->put(route('brands.members.update', [$brand->id, $owner->id]), ['role' => 'editor'])
            ->assertSessionHas('error');

        $this->assertSame(BrandRole::Owner, $owner->fresh()->roleInBrand($brand));

        $this->actingAs($owner)
            ->delete(route('brands.members.destroy', [$brand->id, $owner->id]))
            ->assertSessionHas('error');

        $this->assertNotNull($owner->fresh()->roleInBrand($brand));
    }

    public function test_owner_can_remove_member(): void
    {
        [$owner, $brand] = $this->brandWithOwner();
        $member = $this->addMember($brand, BrandRole::Editor);

        $this->actingAs($owner)
            ->delete(route('brands.members.destroy', [$brand->id, $member->id]))
            ->assertRedirect();

        $member->refresh();
        $this->assertNull($member->roleInBrand($brand));
        $this->assertNull($member->current_brand_id);
    }

    public function test_editor_cannot_manage_members(): void
    {
        [, $brand] = $this->brandWithOwner();
        $editor = $this->addMember($brand, BrandRole::Editor);
        $other = $this->addMember($brand, BrandRole::Viewer);

        $this->actingAs($editor)
            ->put(route('brands.members.update', [$brand->id, $other->id]), ['role' => 'admin'])
            ->assertForbidden();
    }
}
