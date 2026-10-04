<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;

/**
 * A2 technical debt: moving a category must respect the depth of its whole
 * subtree, not only its own level (max depth = 5).
 */
final class CategorySubtreeDepthTest extends MasterDataTestCase
{
    public function test_moving_a_category_with_descendants_cannot_push_them_past_max_depth(): void
    {
        // A → B → C  (subtree height 3)
        $a = $this->category('A');
        $b = $this->category('B', $a);
        $c = $this->category('C', $b);
        // L1 → L2 → L3 → L4  (L4 is at level 4)
        $l1 = $this->category('L1');
        $l2 = $this->category('L2', $l1);
        $l3 = $this->category('L3', $l2);
        $l4 = $this->category('L4', $l3);

        // A under L4 would put C at level 7 > 5.
        $this->cmd('PATCH', "categories/{$a}", ['parent_id' => $l4])
            ->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_TOO_DEEP');
        $this->assertNull(DB::table('product_categories')->where('id', $a)->value('parent_id'));

        // A under L2 puts C exactly at level 5 → allowed.
        $this->cmd('PATCH', "categories/{$a}", ['parent_id' => $l2])->assertOk();
        $this->assertSame($l2, DB::table('product_categories')->where('id', $a)->value('parent_id'));
        $this->assertNotNull($c);

        // and a leaf may still go to level 5
        $this->cmd('PATCH', "categories/{$b}", ['parent_id' => $l4])->assertStatus(422); // B→C height 2 under L4 = level 6
    }
}
