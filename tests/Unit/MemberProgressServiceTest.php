<?php

namespace Tests\Unit;

use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberProgressService;
use Tests\TestCase;

class MemberProgressServiceTest extends TestCase
{
    public function test_completed_lesson_id_set_returns_lookup_for_host_lessons(): void
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'prog'.substr(md5(uniqid('', true)), 0, 8),
        ]);
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product->users()->attach($user->id);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Módulo 1',
            'position' => 1,
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Curso',
            'position' => 1,
        ]);
        $lessonDone = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'type' => 'video',
            'position' => 1,
        ]);
        $lessonPending = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 2',
            'type' => 'video',
            'position' => 2,
        ]);

        MemberLessonProgress::create([
            'user_id' => $user->id,
            'member_lesson_id' => $lessonDone->id,
            'product_id' => $product->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);

        $service = app(MemberProgressService::class);
        $set = $service->completedLessonIdSet($product, $user);

        $this->assertArrayHasKey($lessonDone->id, $set);
        $this->assertArrayNotHasKey($lessonPending->id, $set);
        $this->assertSame(1, $service->completedLessonsCount($product, $user));
    }
}
